<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\AiAnswerSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Jawaban finansial singkat untuk fitur "Tanya AI" (header action Dashboard).
 *
 * Sebelum dikirim ke AI, pertanyaan user di-RETRIEVE dulu (konsep RAG untuk
 * data terstruktur) lewat detectFilters():
 *  - TANPA filter periode/kategori/jenis → ringkasan mencakup SELURUH riwayat
 *    (default WAJIB — data struk lama 2017-2024 ikut terhitung);
 *  - ADA filter eksplisit di pertanyaan (periode "Maret 2025", "bulan ini",
 *    nama kategori, jenis data "utang"/"pemasukan"/dst) → data DISARING hanya
 *    ke cakupan itu supaya jawaban relevan dengan pertanyaan spesifik.
 *
 * Cakupan data mencakup TIGA modul: Pengeluaran (Expense), Pemasukan (Income),
 * serta Utang & Piutang (Debt) — sehingga AI bisa menjawab pertanyaan lintas
 * modul (mis. "apakah pemasukan saya cukup buat bayar utang saya?").
 *
 * Jawaban mentah dari AI difilter lagi oleh guardAnswer() sebelum tampil ke
 * user (anti loop token & anti markdown), lalu ditampilkan sebagai notifikasi
 * Filament oleh App\Filament\Pages\Dashboard::processAskAi().
 */
class FinancialInsightService
{
    /**
     * Batas maksimum token jawaban AI. Jawaban fitur ini memang singkat
     * (ringkasan + angka), jadi 400 token sudah lebih dari cukup — sekaligus
     * membatasi dampak bila model "nyangkut" mengulang token: jawaban
     * terpotong lebih cepat dan tidak pernah memenuhi layar.
     */
    private const MAX_ANSWER_TOKENS = 400;

    /**
     * Pesan fallback yang ditampilkan ke user bila jawaban AI terdeteksi
     * rusak (mis. pengulangan karakter/kata berlebihan).
     */
    private const INVALID_ANSWER_MESSAGE = 'Maaf, AI tidak bisa menjawab pertanyaan ini dengan baik, coba pertanyaan lain atau tanya ulang.';

    /**
     * Kata dasar nama kategori yang TIDAK dijadikan pemicu pencocokan
     * per-kata (tetap bisa match lewat nama lengkap). Tanpa stoplist ini,
     * pertanyaan umum seperti "berapa belanja saya?" ikut ter-filter ke
     * kategori "Belanja Rumah Tangga" hanya karena satu kata "belanja".
     *
     * @var array<int, string>
     */
    private const CATEGORY_WORD_STOPLIST = ['belanja', 'rumah', 'tangga'];

    /**
     * Nama bulan (lengkap + singkat umum) untuk deteksi periode eksplisit
     * seperti "Maret 2025" — kunci huruf kecil, dicocokkan case-insensitive.
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'januari' => 1, 'jan' => 1,
        'februari' => 2, 'feb' => 2,
        'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12,
    ];

    /**
     * Nama bulan lengkap (angka → label) untuk menulis label periode yang
     * mudah dibaca AI, mis. "Maret 2025".
     *
     * @var array<int, string>
     */
    private const MONTH_LABELS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function ask(User $user, string $question): string
    {
        $limit = (int) config('services.cohere.daily_limit', 10);
        $key = 'ai-insight:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);
            $timeText = $seconds >= 3600
                ? (int) ceil($seconds / 3600).' jam'
                : max(1, (int) ceil($seconds / 60)).' menit';

            return sprintf(
                'Batas pertanyaan harian tercapai (%d dari %d). Bisa tanya lagi dalam %s.',
                $limit,
                $limit,
                $timeText
            );
        }
        RateLimiter::hit($key, 86400);

        // RETRIEVAL: tentukan cakupan data (periode/kategori/jenis) dari
        // pertanyaan SEBELUM membangun ringkasan — default tanpa filter =
        // seluruh riwayat (bukan jendela pendek seperti 90 hari).
        $filters = $this->detectFilters($user, $question);
        $summary = $this->buildSummary($user, $filters);
        $model = config('services.cohere.insight_model', config('services.cohere.model', 'command-r7b-12-2024'));

        try {
            $response = Http::withToken(config('services.cohere.api_key'))
                ->connectTimeout(5)
                ->timeout(60)
                ->retry(2, 500, $this->shouldRetry(...), false)
                ->post('https://api.cohere.ai/v1/chat', [
                    'model' => $model,
                    'message' => $this->buildPrompt($summary, $question, $filters),
                    // Temperature rendah menjaga jawaban tetap faktual (tidak
                    // kreatif), tetapi justru kondisi inilah yang bisa memicu
                    // loop pengulangan token — lihat guardAnswer().
                    'max_tokens' => self::MAX_ANSWER_TOKENS,
                    'temperature' => 0.2,
                ]);

            if (! $response->successful()) {
                Log::error('Cohere insight gagal: '.$response->body());

                return 'Maaf, sistem sedang bermasalah. Coba lagi nanti.';
            }

            $raw = trim((string) ($response->json('text') ?? $response->json('message.content.0.text') ?? ''));
            if ($raw === '') {
                return 'Maaf, tidak mendapat respons dari AI.';
            }

            // VALIDASI + pembersihan sebelum jawaban sampai ke user.
            return $this->guardAnswer($raw, $user, $question);
        } catch (Throwable $e) {
            Log::warning('Cohere insight error: '.$e->getMessage());

            return 'Maaf, sistem sedang bermasalah. Coba lagi nanti.';
        }
    }

    /**
     * Deteksi filter retrieval dari teks pertanyaan (case-insensitive).
     *
     * Prinsipnya INVERS dari filter bawaan: TIDAK ADA filter yang terdeteksi
     * berarti SELURUH RIWAYAT (all-time) yang dikirim — filter hanya aktif
     * bila user EKSPLISIT menyebut periode / kategori / jenis data.
     *
     * @return array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }
     */
    private function detectFilters(User $user, string $question): array
    {
        $q = mb_strtolower(trim($question));

        return [
            'period' => $this->detectPeriod($q),
            'categories' => $this->detectCategories($user, $q),
            'types' => $this->detectTypes($q),
            'debtSubtypes' => $this->detectDebtSubtypes($q),
        ];
    }

    /**
     * Deteksi periode EKSPLISIT di pertanyaan. Urutan deteksi:
     *  1. Nama bulan + tahun ("maret 2025") → satu bulan penuh;
     *  2. Frasa relatif ("bulan ini", "minggu lalu", "3 bulan terakhir", …)
     *     — frasa multi-kata dicek dulu agar tidak "dimakan" kata pendeknya
     *     (mis. "bulan kemarin" sebelum "kemarin");
     *  3. Tahun tunggal ("tahun 2024" / angka berdiri sendiri "2019")
     *     → satu tahun penuh.
     *
     * @return ?array{start: Carbon, end: Carbon, label: string}
     */
    private function detectPeriod(string $q): ?array
    {
        // (1) Nama bulan + tahun, mis. "pengeluaran bulan Maret 2025".
        $monthPattern = '/\b('.implode('|', array_keys(self::MONTHS)).')\s+(\d{4})\b/u';
        if (preg_match($monthPattern, $q, $m)) {
            $month = self::MONTHS[$m[1]];
            $year = (int) $m[2];
            $start = Carbon::create($year, $month, 1)->startOfMonth();

            return [
                'start' => $start->copy(),
                'end' => $start->copy()->endOfMonth(),
                'label' => self::MONTH_LABELS[$month].' '.$year,
            ];
        }

        // (2) Frasa relatif — frasa panjang didahulukan supaya kata kunci
        //     pendek di dalamnya tidak menangkap lebih dulu.
        $now = Carbon::now();
        $relative = [
            'bulan ini' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'bulan ini'],
            'minggu ini' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'minggu ini'],
            'tahun ini' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'tahun ini'],
            'bulan lalu' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'bulan lalu'],
            'bulan kemarin' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'bulan lalu'],
            'minggu lalu' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek(), 'minggu lalu'],
            'tahun lalu' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear(), 'tahun lalu'],
            'hari ini' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'hari ini'],
            'kemarin' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'kemarin'],
        ];
        foreach ($relative as $phrase => [$start, $end, $label]) {
            if (str_contains($q, $phrase)) {
                return ['start' => $start, 'end' => $end, 'label' => $label];
            }
        }

        // (3) "N hari/minggu/bulan terakhir".
        if (preg_match('/(\d+)\s+(hari|minggu|bulan)\s+terakhir/u', $q, $m)) {
            $n = max(1, (int) $m[1]);
            $unit = $m[2];
            $start = match ($unit) {
                'hari' => $now->copy()->subDays($n - 1)->startOfDay(),
                'minggu' => $now->copy()->subWeeks($n - 1)->startOfWeek(),
                default => $now->copy()->subMonthsNoOverflow($n - 1)->startOfMonth(),
            };

            return [
                'start' => $start,
                'end' => $now->copy()->endOfDay(),
                'label' => $n.' '.$unit.' terakhir',
            ];
        }

        // (4) Tahun tunggal: "tahun 2024" maupun angka berdiri sendiri
        //     "2019" — \b menolak angka tempel seperti "20250".
        if (preg_match('/\b((?:19|20)\d{2})\b/', $q, $m)) {
            $year = (int) $m[1];

            return [
                'start' => Carbon::create($year, 1, 1)->startOfYear(),
                'end' => Carbon::create($year, 1, 1)->endOfYear(),
                'label' => 'tahun '.$year,
            ];
        }

        // Tanpa penyebutan periode → default SELURUH RIWAYAT (null = tanpa
        // filter tanggal). Inilah yang membuat data struk 2017-2024 ikut
        // terhitung pada pertanyaan umum seperti "total pengeluaran saya".
        return null;
    }

    /**
     * Deteksi kategori yang disebut eksplisit — dicocokkan dengan daftar
     * Category user (kategori default sistem + kategori miliknya sendiri).
     *
     * Dua tingkat pencocokan:
     *  1. Nama lengkap sebagai substring ("kategori makanan & minuman");
     *  2. Per-kata bermakna (≥4 huruf, di luar CATEGORY_WORD_STOPLIST),
     *     mis. "makanan" cocok dengan "Makanan & Minuman" — namun "belanja"
     *     TIDAK memicu "Belanja Rumah Tangga" (lihat stoplist).
     *
     * @return ?Collection<int, Category> null bila tidak ada filter kategori
     */
    private function detectCategories(User $user, string $q): ?Collection
    {
        $categories = Category::query()
            // Kategori default (user_id NULL) dipakai lintas user, jadi
            // ambil default + milik user ini saja.
            ->where(function ($query) use ($user) {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->get();

        $matched = $categories->filter(function (Category $category) use ($q): bool {
            $name = mb_strtolower($category->name);

            // (1) Nama lengkap.
            if (str_contains($q, $name)) {
                return true;
            }

            // (2) Kata bermakna per kata.
            $words = preg_split('/[^a-z0-9]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word) < 4 || in_array($word, self::CATEGORY_WORD_STOPLIST, true)) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $q)) {
                    return true;
                }
            }

            return false;
        });

        return $matched->isNotEmpty() ? $matched->values() : null;
    }

    /**
     * Deteksi jenis data yang ditanyakan. Kalau SATU jenis pun disebut
     * eksplisit, ringkasan hanya berisi jenis itu (jangan campur semua jenis
     * saat user cuma nanya salah satu). Tanpa penyebutan → ketiganya masuk.
     *
     * @return array<int, string> subset dari ['expense', 'income', 'debt']
     */
    private function detectTypes(string $q): array
    {
        $types = [];

        if (preg_match('/\b(pengeluaran|belanjaan|belanja|biaya)\b/u', $q)) {
            $types[] = 'expense';
        }

        if (preg_match('/\b(pemasukan|pendapatan|uang\s+masuk|kas\s+masuk)\b/u', $q)) {
            $types[] = 'income';
        }

        // \butang\b tidak match di dalam kata "piutang" (tidak ada boundary),
        // sehingga "piutang" murni tidak ikut menyalakan keyword "utang".
        if (preg_match('/\b(utang|hutang|piutang|pinjaman|tanggungan)\b/u', $q)) {
            $types[] = 'debt';
        }

        return $types === [] ? ['expense', 'income', 'debt'] : $types;
    }

    /**
     * Sub-tipe Debt: bila user hanya menyebut "utang" (atau hanya
     * "piutang"), ringkasan dibatasi ke sub-tipe itu saja; disebut keduanya
     * atau tidak disebut sama sekali → keduanya.
     *
     * @return array<int, string> subset dari ['utang', 'piutang']
     */
    private function detectDebtSubtypes(string $q): array
    {
        $hasUtang = (bool) preg_match('/\b(utang|hutang)\b/u', $q);
        $hasPiutang = (bool) preg_match('/\bpiutang\b/u', $q);

        if ($hasUtang && ! $hasPiutang) {
            return [Debt::TYPE_UTANG];
        }

        if ($hasPiutang && ! $hasUtang) {
            return [Debt::TYPE_PIUTANG];
        }

        return [Debt::TYPE_UTANG, Debt::TYPE_PIUTANG];
    }

    /**
     * Susun ringkasan data yang dikirim ke AI sesuai filter retrieval:
     * hanya jenis data yang ditanyakan yang masuk (expense/income/debt),
     * masing-masing sudah disaring periode/kategori bila terdeteksi.
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildSummary(User $user, array $filters): string
    {
        $sections = [];

        if (in_array('expense', $filters['types'], true)) {
            $sections[] = $this->buildExpenseSummary($user, $filters);
        }

        if (in_array('income', $filters['types'], true)) {
            $sections[] = $this->buildIncomeSummary($user, $filters);
        }

        if (in_array('debt', $filters['types'], true)) {
            $sections[] = $this->buildDebtSummary($user, $filters);
        }

        $sections = array_values(array_filter(
            $sections,
            static fn (string $section): bool => trim($section) !== ''
        ));

        if ($sections === []) {
            return 'Belum ada data keuangan yang tercatat untuk user ini.';
        }

        return implode("\n\n", $sections);
    }

    /**
     * Ringkasan pengeluaran sesuai filter. TANPA filter (periode & kategori
     * sama-sama null) memakai format LAMA persis — seluruh riwayat, agregat
     * per tahun + per kategori + jendela 12 bulan terakhir — supaya konsisten
     * dengan widget StatsOverview Dashboard dan test regresi yang mengunci
     * formatnya. DENGAN filter, hanya baris di luar cakupan yang dibuang.
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildExpenseSummary(User $user, array $filters): string
    {
        $period = $filters['period'];
        $categories = $filters['categories'];

        $query = Expense::query()
            // where('user_id') eksplisit (di samping global scope OwnedByUserScope)
            // supaya ringkasan tetap benar walau service dipanggil di luar
            // konteks request user yang sedang login (mis. command/queue).
            ->where('user_id', $user->id)
            ->select(['id', 'amount', 'date_shopping', 'category_id', 'vendor'])
            ->with('category:id,name');

        if ($period !== null) {
            // Transaksi tanpa tanggal (date_shopping NULL) otomatis gugur di
            // whereBetween — jumlahnya dilaporkan terpisah di ringkasan.
            $query->whereBetween('date_shopping', [
                $period['start']->toDateString(),
                $period['end']->toDateString(),
            ]);
        }

        if ($categories !== null) {
            $query->whereIn('category_id', $categories->pluck('id'));
        }

        $expenses = $query->get();

        if ($period === null && $categories === null) {
            return $this->buildUnfilteredExpenseSummary($expenses);
        }

        return $this->buildFilteredExpenseSummary($user, $expenses, $filters);
    }

    /**
     * Ringkasan SELURUH riwayat expense — TANPA filter tanggal/kategori apa
     * pun, supaya pertanyaan umum seperti "total pengeluaran saya berapa?"
     * tetap menghitung data lama (mis. transaksi 2017–2024), bukan hanya
     * data terbaru.
     *
     * Supaya ukuran prompt tetap wajar untuk user dengan RIBUAN transaksi,
     * data TIDAK dikirim per baris: cukup agregat per tahun + per kategori
     * (+ top kategori & top vendor).
     *
     * Agregasi tahun/kategori dihitung di PHP, bukan SQL, karena fungsi
     * ekstraksi tahun berbeda antar driver (extract() di PostgreSQL yang
     * dipakai aplikasi vs strftime() di SQLite yang dipakai test) — hanya
     * kolom ringan yang diambil, jadi tetap hemat memori.
     *
     * @param  Collection<int, Expense>  $expenses
     */
    private function buildUnfilteredExpenseSummary(Collection $expenses): string
    {
        $lines = ['=== PENGELUARAN (EXPENSE) ==='];

        if ($expenses->isEmpty()) {
            $lines[] = 'Tidak ada data pengeluaran yang tercatat untuk user ini (riwayat kosong).';

            return implode("\n", $lines);
        }

        $count = $expenses->count();
        $total = (float) $expenses->sum('amount');

        // Tanggal belanja bisa NULL (mis. expense lama tanpa tanggal) —
        // dipisahkan agar pengelompokan per tahun tidak mencampurnya, tetapi
        // tetap ikut dihitung pada total keseluruhan.
        $dated = $expenses->filter(fn (Expense $expense): bool => $expense->date_shopping !== null);

        $lines[] = sprintf(
            'Total pengeluaran SELURUH riwayat: Rp %s (%d transaksi).',
            number_format($total, 0, ',', '.'),
            $count
        );
        $lines[] = sprintf(
            'Rata-rata per transaksi (seluruh riwayat): Rp %s.',
            number_format($total / max(1, $count), 0, ',', '.')
        );

        $byYear = $dated
            ->groupBy(fn (Expense $expense): string => $expense->date_shopping->format('Y'))
            ->sortKeys();

        if ($byYear->isNotEmpty()) {
            $lines[] = sprintf(
                'Periode data tercatat: %s s.d. %s (%d tahun berbeda).',
                $dated->min(fn (Expense $expense): string => $expense->date_shopping->toDateString()),
                $dated->max(fn (Expense $expense): string => $expense->date_shopping->toDateString()),
                $byYear->count()
            );

            $lines[] = 'Rincian total per tahun:';
            foreach ($byYear as $year => $yearExpenses) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d transaksi)',
                    $year,
                    number_format((float) $yearExpenses->sum('amount'), 0, ',', '.'),
                    $yearExpenses->count()
                );
            }

            $lines[] = 'Rincian per tahun & kategori (agregat):';
            foreach ($byYear as $year => $yearExpenses) {
                $lines[] = "  Tahun $year:";
                foreach ($this->groupByCategory($yearExpenses) as $categoryName => $categoryExpenses) {
                    $lines[] = sprintf(
                        '    - %s: Rp %s (%d transaksi)',
                        $categoryName,
                        number_format((float) $categoryExpenses->sum('amount'), 0, ',', '.'),
                        $categoryExpenses->count()
                    );
                }
            }
        }

        // Rincian per BULAN untuk 12 bulan terakhir supaya pertanyaan sejenak
        // "pengeluaran bulan ini / 3 bulan terakhir" tetap bisa dijawab dengan
        // data (placeholder modal & user cenderung bertanya soal bulan ini).
        // Agregat per kategori tiap bulan — tetap ringkas karena terbatas 12 bulan.
        $monthWindowStart = now()->startOfMonth()->subMonths(11);
        $monthWindowEnd = now()->startOfMonth();
        $lines[] = sprintf(
            'Rincian per bulan (agregat, %s s.d. %s; bulan tak tercantum = tidak ada pengeluaran):',
            $monthWindowStart->format('Y-m'),
            $monthWindowEnd->format('Y-m')
        );
        for ($m = $monthWindowStart->copy(); $m <= $monthWindowEnd; $m->addMonth()) {
            $monthKey = $m->format('Y-m');
            $monthExpenses = $dated->filter(fn (Expense $expense): bool => $expense->date_shopping->format('Y-m') === $monthKey
            );

            if ($monthExpenses->isEmpty()) {
                $lines[] = "  Bulan {$monthKey}: tidak ada pengeluaran tercatat.";

                continue;
            }

            $lines[] = sprintf(
                '  Bulan %s: Rp %s (%d transaksi)',
                $monthKey,
                number_format((float) $monthExpenses->sum('amount'), 0, ',', '.'),
                $monthExpenses->count()
            );

            foreach ($this->groupByCategory($monthExpenses) as $categoryName => $categoryExpenses) {
                $lines[] = sprintf(
                    '    - %s: Rp %s (%d transaksi)',
                    $categoryName,
                    number_format((float) $categoryExpenses->sum('amount'), 0, ',', '.'),
                    $categoryExpenses->count()
                );
            }
        }

        $lines[] = 'Kategori terbesar sepanjang riwayat (top 5):';

        $rank = 0;
        foreach ($this->groupByCategory($expenses)->take(5) as $categoryName => $categoryExpenses) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $categoryName,
                number_format((float) $categoryExpenses->sum('amount'), 0, ',', '.'),
                $categoryExpenses->count()
            );
        }

        $lines[] = 'Vendor dengan pengeluaran terbesar sepanjang riwayat (top 5):';
        $rank = 0;
        foreach ($this->groupByVendor($expenses)->take(5) as $vendorName => $vendorExpenses) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $vendorName,
                number_format((float) $vendorExpenses->sum('amount'), 0, ',', '.'),
                $vendorExpenses->count()
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Ringkasan pengeluaran DENGAN filter (periode dan/atau kategori) —
     * hanya baris agregat dari data TERFILTER yang dikirim, tanpa agregat
     * tahun-lain/tahun-tidak-terkait, supaya jawaban AI tidak "bocor" ke
     * periode di luar yang ditanyakan (mis. pertanyaan "Maret 2025" tidak
     * menyertakan angka 2017/2024 sama sekali).
     *
     * @param  Collection<int, Expense>  $expenses  data yang sudah ter-sumber
     *                                              dari query berfilter
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildFilteredExpenseSummary(User $user, Collection $expenses, array $filters): string
    {
        $period = $filters['period'];
        $categories = $filters['categories'];

        $scopeParts = [];
        if ($period !== null) {
            $scopeParts[] = 'periode '.$period['label'];
        }
        if ($categories !== null) {
            $scopeParts[] = 'kategori '.implode(', ', $categories->pluck('name')->all());
        }
        $scope = implode(' + ', $scopeParts);

        $lines = ['=== PENGELUARAN (EXPENSE) — DIFILTER ==='];

        if ($expenses->isEmpty()) {
            $lines[] = sprintf('Tidak ada data pengeluaran untuk filter %s.', $scope);

            return implode("\n", $lines);
        }

        $count = $expenses->count();
        $total = (float) $expenses->sum('amount');

        $lines[] = sprintf(
            'Total pengeluaran %s: Rp %s (%d transaksi).',
            $scope,
            number_format($total, 0, ',', '.'),
            $count
        );

        if ($period !== null) {
            $lines[] = sprintf(
                'Rentang periode: %s s.d. %s.',
                $period['start']->toDateString(),
                $period['end']->toDateString()
            );

            // Transaksi tanpa tanggal tidak lolos whereBetween; laporkan agar
            // AI jujur bilang angkanya mungkin sedikit lebih kecil dari total
            // keseluruhan bila user membandingkan dengan total all-time.
            $undated = Expense::query()
                ->where('user_id', $user->id)
                ->whereNull('date_shopping')
                ->when($categories !== null, fn ($q) => $q->whereIn('category_id', $categories->pluck('id')))
                ->count();
            if ($undated > 0) {
                $lines[] = sprintf(
                    'Catatan: %d transaksi tanpa tanggal tidak ikut terhitung pada filter periode.',
                    $undated
                );
            }
        }

        if ($categories !== null) {
            $nullCategory = Expense::query()
                ->where('user_id', $user->id)
                ->whereNull('category_id')
                ->when($period !== null, fn ($q) => $q->whereBetween('date_shopping', [
                    $period['start']->toDateString(),
                    $period['end']->toDateString(),
                ]))
                ->count();
            if ($nullCategory > 0) {
                $lines[] = sprintf(
                    'Catatan: %d transaksi tanpa kategori tidak ikut terhitung pada filter kategori.',
                    $nullCategory
                );
            }
        }

        $dated = $expenses->filter(fn (Expense $expense): bool => $expense->date_shopping !== null);

        // Per bulan — rentang filter yang lebar tetap ringkas karena hanya
        // bulan yang benar-benar punya data yang muncul.
        $byMonth = $dated
            ->groupBy(fn (Expense $expense): string => $expense->date_shopping->format('Y-m'))
            ->sortKeys();
        $lines[] = 'Rincian per bulan (hanya bulan yang ada data):';
        foreach ($byMonth as $monthKey => $monthExpenses) {
            $lines[] = sprintf(
                '  Bulan %s: Rp %s (%d transaksi)',
                $monthKey,
                number_format((float) $monthExpenses->sum('amount'), 0, ',', '.'),
                $monthExpenses->count()
            );
        }

        // Per hari untuk periode pendek (≤±34 hari: minggu/bulan) supaya
        // pertanyaan "bulan Maret 2025" bisa dijawab rinci per tanggal.
        if ($period !== null && $period['start']->diffInDays($period['end']) <= 34) {
            $byDay = $dated
                ->groupBy(fn (Expense $expense): string => $expense->date_shopping->toDateString())
                ->sortKeys();
            $lines[] = 'Rincian per hari:';
            foreach ($byDay as $day => $dayExpenses) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d transaksi)',
                    $day,
                    number_format((float) $dayExpenses->sum('amount'), 0, ',', '.'),
                    $dayExpenses->count()
                );
            }
        }

        $lines[] = 'Rincian per kategori:';
        foreach ($this->groupByCategory($expenses) as $categoryName => $categoryExpenses) {
            $lines[] = sprintf(
                '  - %s: Rp %s (%d transaksi)',
                $categoryName,
                number_format((float) $categoryExpenses->sum('amount'), 0, ',', '.'),
                $categoryExpenses->count()
            );
        }

        $lines[] = 'Rincian per vendor (top 5):';
        $rank = 0;
        foreach ($this->groupByVendor($expenses)->take(5) as $vendorName => $vendorExpenses) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $vendorName,
                number_format((float) $vendorExpenses->sum('amount'), 0, ',', '.'),
                $vendorExpenses->count()
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Ringkasan Total Pemasukan (Income) — all-time bila tanpa filter
     * periode, atau hanya pemasukan dalam periode bila terdeteksi. Sumber
     * uang masuk (gaji, freelance, penjualan, dst.) ikut dirangkum agar
     * pertanyaan seperti "berapa pemasukan saya?" terjawab dari data asli,
     * bukan dari pengeluaran saja.
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildIncomeSummary(User $user, array $filters): string
    {
        $period = $filters['period'];

        $query = Income::query()
            ->where('user_id', $user->id)
            ->select(['id', 'amount', 'date_received', 'source']);

        if ($period !== null) {
            $query->whereBetween('date_received', [
                $period['start']->toDateString(),
                $period['end']->toDateString(),
            ]);
        }

        $incomes = $query->get();
        $scope = $period !== null ? 'periode '.$period['label'] : 'SELURUH riwayat';

        $lines = ['=== PEMASUKAN (INCOME) ==='];

        if ($incomes->isEmpty()) {
            $lines[] = sprintf('Tidak ada data pemasukan %s.', $scope);

            return implode("\n", $lines);
        }

        $count = $incomes->count();
        $total = (float) $incomes->sum('amount');

        $lines[] = sprintf(
            'Total pemasukan %s: Rp %s (%d catatan).',
            $scope,
            number_format($total, 0, ',', '.'),
            $count
        );

        $dated = $incomes->filter(fn (Income $income): bool => $income->date_received !== null);
        if ($dated->isNotEmpty()) {
            $lines[] = sprintf(
                'Periode data pemasukan: %s s.d. %s.',
                $dated->min(fn (Income $income): string => $income->date_received->toDateString()),
                $dated->max(fn (Income $income): string => $income->date_received->toDateString())
            );
        }

        $byYear = $dated
            ->groupBy(fn (Income $income): string => $income->date_received->format('Y'))
            ->sortKeys();
        if ($byYear->count() > 1) {
            $lines[] = 'Rincian pemasukan per tahun:';
            foreach ($byYear as $year => $yearIncomes) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d catatan)',
                    $year,
                    number_format((float) $yearIncomes->sum('amount'), 0, ',', '.'),
                    $yearIncomes->count()
                );
            }
        }

        $bySource = $incomes
            ->groupBy(fn (Income $income): string => $income->source ?: 'Tanpa Sumber')
            ->sortByDesc(fn (Collection $items): float => (float) $items->sum('amount'));
        $lines[] = 'Sumber pemasukan terbesar (top 5):';
        $rank = 0;
        foreach ($bySource->take(5) as $source => $sourceIncomes) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d catatan)',
                $rank,
                $source,
                number_format((float) $sourceIncomes->sum('amount'), 0, ',', '.'),
                $sourceIncomes->count()
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Ringkasan Utang & Piutang (Debt) — hanya catatan AKTIF (belum lunas /
     * sebagian) yang dihitung sebagai kewajiban/hak berjalan, lengkap dengan
     * nama pihak & sisa tagihannya supaya pertanyaan seperti "ke siapa saya
     * masih hutang?" bisa dijawab. Sub-tipe mengikuti deteksi pertanyaan
     * (hanya "utang" / hanya "piutang" / keduanya).
     *
     * CATATAN: posisi utang/piutang adalah data TERKINI (bukan rentang
     * periode), sehingga filter periode sengaja TIDAK diterapkan ke sini —
     * keadaan "aktif saat ini" tidak punya konsep "periode lalu".
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildDebtSummary(User $user, array $filters): string
    {
        $debts = Debt::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $filters['debtSubtypes'])
            ->get();

        $lines = ['=== UTANG & PIUTANG (DEBT) ==='];

        if ($debts->isEmpty()) {
            $lines[] = 'Tidak ada data utang/piutang yang tercatat untuk user ini.';

            return implode("\n", $lines);
        }

        $lines[] = 'Posisi di bawah adalah kondisi TERKINI (bukan rentang periode tertentu).';

        foreach ($filters['debtSubtypes'] as $type) {
            $items = $debts->where('type', $type);
            if ($items->isEmpty()) {
                continue;
            }

            $isUtang = $type === Debt::TYPE_UTANG;
            $label = $isUtang ? 'UTANG' : 'PIUTANG';
            $word = $isUtang ? 'lunas' : 'tertagih';

            $active = $items->filter(fn (Debt $debt): bool => $debt->status !== Debt::STATUS_LUNAS);
            $totalSisa = (float) $active->sum(fn (Debt $debt): float => (float) $debt->amount - (float) $debt->paid_amount);
            $totalNominal = (float) $active->sum('amount');
            $overdue = $active->filter(fn (Debt $debt): bool => $debt->isOverdue());

            $lines[] = sprintf(
                '%s AKTIF (belum %s): %d catatan, total sisa Rp %s dari total Rp %s.%s',
                $label,
                $word,
                $active->count(),
                number_format($totalSisa, 0, ',', '.'),
                number_format($totalNominal, 0, ',', '.'),
                $overdue->isNotEmpty() ? sprintf(' %d catatan LEBIH JATUH TEMPO.', $overdue->count()) : ''
            );

            foreach ($active->take(10) as $debt) {
                $detail = sprintf(
                    '  - %s: sisa Rp %s dari Rp %s (status: %s',
                    $debt->counterparty_name,
                    number_format((float) $debt->amount - (float) $debt->paid_amount, 0, ',', '.'),
                    number_format((float) $debt->amount, 0, ',', '.'),
                    $debt->statusLabel()
                );
                if ($debt->due_date !== null) {
                    $detail .= ', jatuh tempo '.$debt->due_date->toDateString();
                }
                $lines[] = $detail.');';
            }

            $lunas = $items->filter(fn (Debt $debt): bool => $debt->status === Debt::STATUS_LUNAS);
            if ($lunas->isNotEmpty()) {
                $lines[] = sprintf(
                    '%s lunas: %d catatan (tidak dihitung sebagai kewajiban/hak aktif).',
                    $label,
                    $lunas->count()
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Kelompokkan koleksi expense per nama kategori, urut dari total terbesar.
     * Kategori kosong (parsing gagal / belum diisi) → "Tanpa Kategori".
     *
     * @param  Collection<int, Expense>  $expenses
     * @return Collection<string, Collection<int, Expense>>
     */
    private function groupByCategory(Collection $expenses): Collection
    {
        return $expenses
            ->groupBy(fn (Expense $expense): string => $expense->category?->name ?? 'Tanpa Kategori')
            ->sortByDesc(fn (Collection $items): float => (float) $items->sum('amount'));
    }

    /**
     * Kelompokkan koleksi expense per vendor, urut dari total terbesar.
     * Vendor kosong → "Tanpa Nama Vendor".
     *
     * @param  Collection<int, Expense>  $expenses
     * @return Collection<string, Collection<int, Expense>>
     */
    private function groupByVendor(Collection $expenses): Collection
    {
        return $expenses
            ->groupBy(fn (Expense $expense): string => $expense->vendor ?: 'Tanpa Nama Vendor')
            ->sortByDesc(fn (Collection $items): float => (float) $items->sum('amount'));
    }

    /**
     * Validasi + bersihkan jawaban mentah AI sebelum dikembalikan ke user.
     *
     * Failure mode yang dijaga: model "nyangkut" mengulang satu token
     * (mis. "3333333…") sampai memenuhi layar. Ini sifat dasar model pada
     * temperature sangat rendah sehingga TIDAK bisa dicegah 100% dari sisi
     * aplikasi — yang bisa dilakukan aplikasi adalah MENDETEKSI dan membuang
     * jawaban tersebut supaya tidak pernah tampil ke user, lalu mencatatnya
     * di log untuk investigasi.
     */
    private function guardAnswer(string $rawAnswer, User $user, string $question): string
    {
        if (AiAnswerSanitizer::hasPathologicalRepetition($rawAnswer)) {
            Log::warning('Jawaban Tanya AI terdeteksi rusak (pengulangan karakter/kata berlebihan) — jawaban tidak ditampilkan ke user.', [
                'user_id' => $user->id,
                'question' => $question,
                'answer_length' => mb_strlen($rawAnswer),
                'answer_excerpt' => mb_substr($rawAnswer, 0, 120),
            ]);

            return self::INVALID_ANSWER_MESSAGE;
        }

        // Pengaman kedua: AI kadang tetap memakai **bold** walau prompt sudah
        // melarangnya. Tanda bintang mentah membingungkan user di notifikasi,
        // jadi dibuang di sini (bukan hanya mengandalkan kepatuhan model).
        $answer = AiAnswerSanitizer::stripMarkdown($rawAnswer);

        // Jawaban yang setelah dibersihkan jadi kosong (mis. isinya hanya "**")
        // sama tidak bergunanya dengan jawaban rusak.
        return $answer === '' ? self::INVALID_ANSWER_MESSAGE : $answer;
    }

    /**
     * Susun prompt lengkap: system prompt (aturan jawab + cakupan filter
     * yang dipakai) + ringkasan data + pertanyaan user.
     *
     * Cakupan filter dinyatakan eksplisit supaya AI TIDAK berasumsi sendiri
     * (mis. mengira data selalu seluruh riwayat saat user sebenarnya bertanya
     * "bulan Maret 2025", atau sebaliknya).
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildPrompt(string $summary, string $question, array $filters): string
    {
        $system = 'Kamu asisten keuangan berbahasa Indonesia. '
            .'Jawab HANYA berdasarkan data yang diberikan, JANGAN mengarang angka yang tidak ada di data. '
            // Jenis data disebut eksplisit supaya AI tahu ini bukan hanya data
            // pengeluaran — jawaban bisa lintas modul (pemasukan vs utang).
            .'Data user mencakup pengeluaran (expense), pemasukan (income), serta utang & piutang (debt), '
            .'sehingga kamu bisa menjawab pertanyaan lintas modul — misal "apakah pemasukan saya cukup buat bayar utang saya?". '
            .'SELURUH riwayat pengeluaran user adalah cakupan default bila tidak ada filter yang disebut. '
            .$this->buildScopeNote($filters)
            // Instruksi format: jawaban tampil sebagai teks polos di notifikasi.
            .'Jawab dalam teks polos (plain text) saja, JANGAN gunakan format markdown seperti **bold**, _italic_, '
            .'atau simbol penekanan lain — tulis angka rupiah apa adanya tanpa penekanan huruf tebal. '
            .'Kalau data tidak cukup untuk menjawab, katakan dengan jujur bahwa datanya tidak tersedia.';

        $header = $this->buildDataHeader($filters);

        return <<<PROMPT
$system

--- $header ---
$summary
--- AKHIR DATA ---

Pertanyaan user:
$question

Jawaban (teks polos, tanpa markdown):
PROMPT;
    }

    /**
     * Header blok data: menyebut filter yang aktif (atau menegaskan tanpa
     * filter) sehingga konteks cakupan terbaca jelas saat inspeksi prompt.
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildDataHeader(array $filters): string
    {
        $parts = [];

        if ($filters['period'] !== null) {
            $parts[] = 'periode '.$filters['period']['label'];
        }

        if ($filters['categories'] !== null) {
            $parts[] = 'kategori '.implode(', ', $filters['categories']->pluck('name')->all());
        }

        if (count($filters['types']) < 3) {
            $parts[] = 'jenis data: '.$this->typeLabels($filters['types']).' saja';
        }

        if (in_array('debt', $filters['types'], true) && count($filters['debtSubtypes']) < 2) {
            $parts[] = 'sub-tipe utang: '.implode(' / ', $filters['debtSubtypes']).' saja';
        }

        if ($parts === []) {
            return 'DATA KEUANGAN USER (SELURUH RIWAYAT, TANPA BATAS PERIODE)';
        }

        return 'DATA KEUANGAN USER (DIFILTER: '.implode('; ', $parts).')';
    }

    /**
     * Catatan cakupan untuk system prompt — dua kemungkinan:
     *  - Tanpa filter sama sekali: tegaskan seluruh riwayat dipakai
     *    (termasuk data lama) + batasan jendela rincian bulanan 12 bulan;
     *  - Ada filter: tegaskan data SUDAH disaring dan jawaban wajib dibatasi
     *    ke cakupan itu (tidak boleh bocor ke angka di luar filter).
     *
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildScopeNote(array $filters): string
    {
        $notes = [];

        if ($filters['period'] !== null) {
            $notes[] = sprintf(
                'periode = %s (%s s.d. %s)',
                $filters['period']['label'],
                $filters['period']['start']->toDateString(),
                $filters['period']['end']->toDateString()
            );
        }

        if ($filters['categories'] !== null) {
            $notes[] = 'kategori = '.implode(', ', $filters['categories']->pluck('name')->all());
        }

        if (count($filters['types']) < 3) {
            $notes[] = 'jenis data = '.$this->typeLabels($filters['types']).' saja';
        }

        if (in_array('debt', $filters['types'], true) && count($filters['debtSubtypes']) < 2) {
            $notes[] = 'sub-tipe utang = '.implode(' / ', $filters['debtSubtypes']).' saja';
        }

        if ($notes === []) {
            return 'Data di bawah TANPA filter (seluruh riwayat, dari data paling lama sampai terbaru) '
                .'— untuk pertanyaan umum gunakan seluruh riwayat itu, jangan membatasi ke periode pendek. '
                .'Rincian bulanan hanya tersedia untuk 12 bulan terakhir; di luar jendela itu pakai rincian per tahun, jangan mengarang angka. ';
        }

        return 'Data di bawah SUDAH DIFILTER sesuai pertanyaan user: '.implode('; ', $notes)
            .'. Jawab HANYA dari cakupan data tersebut dan jangan menyertakan angka di luar filter itu; '
            .'bila pertanyaan menyangkut cakupan lain yang tidak tersedia di data, katakan datanya tidak disediakan. ';
    }

    /**
     * Label jenis data berbahasa Indonesia untuk prompt.
     *
     * @param  array<int, string>  $types
     */
    private function typeLabels(array $types): string
    {
        $map = [
            'expense' => 'pengeluaran',
            'income' => 'pemasukan',
            'debt' => 'utang & piutang',
        ];

        return implode(', ', array_map(static fn (string $type): string => $map[$type] ?? $type, $types));
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response?->status() ?? 0;

            return $status === 429 || $status >= 500;
        }

        return $exception instanceof ConnectionException;
    }
}










