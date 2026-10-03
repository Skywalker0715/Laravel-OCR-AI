<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\AiAnswerSanitizer;
use App\Support\AiInsightResult;
use App\Support\LogSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Batas panjang pertanyaan (karakter).
     *
     * Dipakai dua kali: sebagai ->maxLength() di field form (penolakan di
     * sisi UI, sebelum request dikirim) dan sebagai pengaman di sisi service
     * supaya service tetap aman bila dipanggil dari command/queue yang tidak
     * melewati validasi Filament.
     */
    public const MAX_QUESTION_CHARS = 2000;

    /**
     * Batas ukuran TOTAL prompt (karakter) yang boleh dikirim ke Cohere.
     *
     * Dipakai sebagai jaring pengaman: agregasi utama sudah dipindah ke SQL
     * GROUP BY + LIMIT (lihat buildExpenseSummary()) sehingga ringkasan tidak
     * ikut membesar secara linear dengan jumlah transaksi, tapi user dengan
     * ribuan kategori/vendor tetap mungkin menghasilkan ringkasan panjang.
     * 12.000 karakter ≈ 3.000 token — jauh di bawah limit konteks Cohere, tapi
     * cukup untuk seluruh ringkasan agregat.
     *
     * Nilainya public (bukan private) supaya test bisa mengunci batas ini.
     */
    public const MAX_PROMPT_CHARS = 12000;

    /**
     * Pengaman: system prompt + header + pertanyaan + kerangka teks tidak
     * boleh melebihi batas. Sisanya jadi jatah untuk ringkasan data.
     */
    private const PROMPT_SKELETON_RESERVE = 260;

    /**
     * Jatah minimum untuk ringkasan data. Bila system prompt + pertanyaan
     * sendiri sudah memakan hampir seluruh batas, ringkasan tetap boleh
     * memakai 1.500 karakter (cukup untuk semua baris TOTAL) daripada nol.
     */
    private const MIN_SUMMARY_CHARS = 1500;

    /**
     * Timeout per percobaan (detik).
     *
     * Dulu 60 detik — untuk fitur "tanya angka" itu terlalu lama: user
     * menunggu dengan tombol submit terkunci dan tidak bisa membatalkan.
     * 20 detik masih jauh di atas waktu respons normal Cohere, tapi membuat
     * worst case tetap enak ditahan: 20 + 0,5 (jeda retry) + 20 = 40,5 detik,
     * di bawah ambang 45 detik yang disepakati.
     */
    private const REQUEST_TIMEOUT_SECONDS = 20;

    /** Timeout koneksi TCP (detik) — terpisah dari timeout total request. */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Jumlah TOTAL percobaan (bukan jumlah retry): 2 = satu kali retry.
     *
     * Retry hanya dilakukan untuk 429/5xx/gagal koneksi (lihat shouldRetry()).
     * 4xx lain (mis. 401 karena API key salah) sengaja TIDAK di-retry karena
     * pasti gagal dan hanya memperpanjang waktu tunggu user.
     */
    private const MAX_ATTEMPTS = 2;

    /** Jeda sebelum percobaan ulang (milidetik). */
    private const RETRY_SLEEP_MS = 500;

    /**
     * Prefix key RateLimiter.
     *
     * DUA limiter dengan sengaja dipisah (lihat ask()):
     *  - 'daily'  → kuota harian, hanya dipotong bila Cohere benar-benar
     *                menjawab sehingga error server TIDAK menghabiskan kuota user;
     *  - 'burst'  → anti-spam per menit, dipotong untuk SETIAP percobaan.
     */
    private const DAILY_LIMIT_KEY_PREFIX = 'ai-insight:';

    private const BURST_LIMIT_KEY_PREFIX = 'ai-insight-burst:';

    /** Umur key limiter harian (detik) = 1 hari. */
    private const DAILY_LIMIT_DECAY = 86400;

    /** Umur key limiter per menit (detik). */
    private const BURST_LIMIT_DECAY = 60;

    /**
     * Ekspresi SQL untuk "nama kategori" saat agregasi. LEFT JOIN ke categories
     * membuat expense tanpa kategori bernilai NULL, dan ini diterjemahkan jadi
     * label yang sama seperti versi PHP sebelumnya.
     */
    private const CATEGORY_BUCKET_EXPRESSION = "COALESCE(categories.name, 'Tanpa Kategori')";

    /** Ekspresi SQL untuk "nama vendor" saat agregasi (string kosong → label). */
    private const VENDOR_BUCKET_EXPRESSION = "COALESCE(NULLIF(expenses.vendor, ''), 'Tanpa Nama Vendor')";

    /** Ekspresi SQL untuk "sumber pemasukan" saat agregasi. */
    private const INCOME_SOURCE_EXPRESSION = "COALESCE(NULLIF(incomes.source, ''), 'Tanpa Sumber')";

    /** Jumlah baris untuk daftar "top N" (kategori, vendor, sumber pemasukan). */
    private const TOP_N = 5;

    /**
     * Penanda yang ditambahkan saat ringkasan harus dipotong agar muat ke batas
     * prompt. Sengaja eksplisit supaya AI tahu ada rincian yang TIDAK dikirim
     * dan tidak mengarang angka untuk mengisinya.
     */
    private const TRUNCATION_NOTICE = '... (sebagian rincian dipotong agar muat ke batas ukuran prompt; angka total di atas tetap utuh)';

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

    /**
     * Jawab pertanyaan user dengan data keuangannya.
     *
     * Mengembalikan AiInsightResult (DTO), bukan string polos: pemanggil
     * memeriksa status-nya untuk menata modal, bukan menebak dari isi teks.
     * Teks yang ditampilkan ke user TIDAK berubah sama sekali.
     *
     * DESAIN DUA LIMITER (penting — lihat komentar tiap bagian):
     *
     *  1. LIMITER HARIAN ("ai-insight:{id}") = kuota yang dijanjikan ke user
     *     ("10 pertanyaan per hari"). Dulu key ini di-hit SEBELUM request,
     *     sehingga Cohere yang sedang 5xx atau timeout tetap memakan kuota:
     *     user bisa kehilangan 10 kuota dalam 10 menit tanpa pernah mendapat
     *     satu jawaban pun. Sekarang key ini di-hit SESUDAH Cohere benar-benar
     *     menjawab — jadi kegagalan di sisi server/timeout tidak menghabiskan
     *     kuota harian user.
     *
     *  2. LIMITER PER MENIT ("ai-insight-burst:{id}") = anti-spam, di-hit untuk
     *     SETIAP percobaan sebelum request. Ini yang menutup celah yang dibuat
     *     oleh (1): kalau error tidak lagi memotong kuota harian, tanpa limiter
     *     kedua user (atau bot) bisa menembak endpoint ini tanpa batas dan
     *     membanjiri Cohere dengan request yang semuanya pasti gagal.
     *
     * Jadi: kuota harian = keadilan (user tidak dirugikan karena server salah),
     * limiter per menit = proteksi (server tidak dibanjiri kalau dipanggil
     * terus-menerus). Keduanya per-user, jadi isolasi antar user terjaga.
     */
    public function ask(User $user, string $question): AiInsightResult
    {
        $question = trim($question);

        // Pengaman service-level. Jalur UI (Dashboard) sudah menolak lebih dulu
        // lewat ->maxLength(), tapi service ini juga bisa dipanggil dari
        // command/queue yang tidak lewat validasi Filament.
        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            $question = mb_substr($question, 0, self::MAX_QUESTION_CHARS);
        }

        $dailyKey = self::DAILY_LIMIT_KEY_PREFIX.$user->id;
        $dailyLimit = $this->dailyLimit();

        if (RateLimiter::tooManyAttempts($dailyKey, $dailyLimit)) {
            $retryAfter = RateLimiter::availableIn($dailyKey);

            return AiInsightResult::rateLimited(
                sprintf(
                    'Batas pertanyaan harian tercapai (%d dari %d). Bisa tanya lagi dalam %s.',
                    $dailyLimit,
                    $dailyLimit,
                    $this->humanizeSeconds($retryAfter)
                ),
                $retryAfter
            );
        }

        // Anti-spam: diperiksa & dipotong untuk setiap percobaan, termasuk
        // percobaan yang nanti gagal — justru itulah gunanya.
        $burstKey = self::BURST_LIMIT_KEY_PREFIX.$user->id;
        $burstLimit = $this->burstLimit();

        if (RateLimiter::tooManyAttempts($burstKey, $burstLimit)) {
            $retryAfter = RateLimiter::availableIn($burstKey);

            return AiInsightResult::tooManyRequests(
                sprintf(
                    'Terlalu banyak pertanyaan berturut-turut. Bisa coba lagi dalam %s.',
                    $this->humanizeSeconds($retryAfter)
                ),
                $retryAfter
            );
        }
        RateLimiter::hit($burstKey, self::BURST_LIMIT_DECAY);

        // RETRIEVAL: tentukan cakupan data (periode/kategori/jenis) dari
        // pertanyaan SEBELUM membangun ringkasan — default tanpa filter =
        // seluruh riwayat (bukan jendela pendek seperti 90 hari).
        $filters = $this->detectFilters($user, $question);
        $summary = $this->buildSummary($user, $filters);
        $model = config('services.cohere.insight_model', config('services.cohere.model', 'command-r7b-12-2024'));

        try {
            $response = Http::withToken(config('services.cohere.api_key'))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                // 2 percobaan = 1 retry. Worst case 20 + 0,5 + 20 = 40,5 detik,
                // jauh di bawah 45 detik (dulu 60 + 0,5 + 60 = 120,5 detik).
                ->retry(self::MAX_ATTEMPTS, self::RETRY_SLEEP_MS, $this->shouldRetry(...), false)
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
                // Body error dipotong (bisa memuat sebagian pertanyaan/data yang dikirim).
                Log::error('Cohere insight gagal: '.LogSanitizer::excerpt($response->body()));

                // Sengaja TIDAK menyentuh limiter harian: request ini tidak
                // menghasilkan jawaban apa pun untuk user.
                return AiInsightResult::providerError('Maaf, sistem sedang bermasalah. Coba lagi nanti.');
            }

            $raw = trim((string) ($response->json('text') ?? $response->json('message.content.0.text') ?? ''));
            if ($raw === '') {
                return AiInsightResult::emptyResponse('Maaf, tidak mendapat respons dari AI.');
            }

            // Satu-satunya tempat kuota harian dipotong: kita sudah benar-benar
            // mendapat jawaban dari Cohere, jadi user memang sudah memakai satu
            // hak bertanya hari ini.
            RateLimiter::hit($dailyKey, self::DAILY_LIMIT_DECAY);

            // VALIDASI + pembersihan sebelum jawaban sampai ke user.
            return $this->guardAnswer($raw, $user, $question);
        } catch (Throwable $e) {
            Log::warning('Cohere insight error: '.$e->getMessage());

            // Timeout / connection error juga tidak memotong kuota harian.
            return AiInsightResult::providerError('Maaf, sistem sedang bermasalah. Coba lagi nanti.');
        }
    }

    /** Kuota "Tanya AI" per user per hari (config services.cohere.daily_limit). */
    private function dailyLimit(): int
    {
        return max(1, (int) config('services.cohere.daily_limit', 10));
    }

    /**
     * Batas anti-spam per menit. Sengaja DIBERIKAN NILAI LEBIH BESAR dari kuota
     * harian: kalau limiter ini lebih kecil, user justru terkunci "terlalu
     * sering" padahal kuota hariannya masih jauh plenty — dan tidak ada orang
     * yang berhalasan bertanya 31 kali dalam 1 menit.
     *
     * Justru inilah alasan limiter per menit tidak bisa menggantikan kuota
     * harian: nilainya terlalu longgar untuk menahan spam (user bisa tetap
     * menembak puluhan kali per menit selama jendela 60 detik berjalan),
     * sementara terlalu ketat untuk dipakai sebagai jatah harian yang adil.
     */
    private function burstLimit(): int
    {
        return max(1, (int) config('services.cohere.burst_limit', 30));
    }

    /**
     * Ubah sisa detik limiter menjadi bahasa manusia ("45 menit", "2 jam"),
     * dengan floor 1 menit supaya tidak pernah tampil "0 menit".
     */
    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds >= 3600) {
            return (int) ceil($seconds / 3600).' jam';
        }

        return max(1, (int) ceil($seconds / 60)).' menit';
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
     * Ringkasan pengeluaran sesuai filter.
     *
     * SEMUA agregasi di method ini & turunannya dijalankan lewat SQL
     * GROUP BY, bukan dengan memuat baris expense ke memori lalu
     * groupBy() di PHP. Alasannya skalanya: user dengan 3.000 transaksi
     * berarti 3.000 objek Eloquent (plus relasi category) ditarik hanya untuk
     * diringkas jadi belasan baris — kerja sia-sia yang juga menahan
     * memory_limit pada shared hosting murah. Dengan GROUP BY, database yang
     * melakukan pekerjaannya dan yang kembali ke aplikasi hanya SEJUMBAR
     * AGREGAT (per tahun/bulan/kategori/vendor).
     *
     * Format output sengaja dipertahankan persis seperti sebelumnya agar
     * regresi test lama & prompt yang sudah "mengenal" model tetap berlaku.
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
        $base = $this->expenseBaseQuery($user, $filters);

        if ($filters['period'] === null && $filters['categories'] === null) {
            return $this->buildUnfilteredExpenseSummary($base);
        }

        return $this->buildFilteredExpenseSummary($user, $base, $filters);
    }

    /**
     * Query dasar expense: sudah dibatasi ke user + filter periode/kategori
     * yang terdeteksi.
     *
     * where('user_id') ditulis eksplisit (di samping global scope
     * OwnedByUserScope) supaya ringkasan tetap benar walau service dipanggil
     * di luar konteks request user yang sedang login (mis. command/queue).
     */
    private function expenseBaseQuery(User $user, array $filters): Builder
    {
        $period = $filters['period'];
        $categories = $filters['categories'];

        // SETIAP kolom ditulis qualified (expenses.*) karena query ini nanti
        // digabung LEFT JOIN ke categories — dan kedua tabel punya kolom
        // 'user_id', sehingga tanpa qualify SQL jadi "ambiguous column name".
        return Expense::query()
            ->where('expenses.user_id', $user->id)
            ->when($period !== null, fn (Builder $query): Builder => $query->whereBetween('expenses.date_shopping', [
                // Transaksi tanpa tanggal (date_shopping NULL) otomatis gugur di
                // whereBetween — jumlahnya dilaporkan terpisah di ringkasan.
                $period['start']->toDateString(),
                $period['end']->toDateString(),
            ]))
            ->when($categories !== null, fn (Builder $query): Builder => $query->whereIn('expenses.category_id', $categories->pluck('id')));
    }

    /**
     * Ekspresi SQL untuk mengekstrak bagian tanggal dari sebuah kolom.
     *
     * Aplikasi berjalan di PostgreSQL (production) sedangkan test memakai
     * SQLite, dan fungsi tanggal keduanya berbeda — dulu ini jadi alasan
     * agregasi dibiarkan di PHP. Sekarang ekspresinya dipilih saat runtime,
     * tapi ketiga cabang menghasilkan TEKS dengan format identik ('2025',
     * '2025-03', '2025-03-15') sehingga sisa kode tidak perlu tahu bedanya.
     *
     * @param  string  $part  'year' | 'month' | 'day'
     */
    private function dateExpression(string $column, string $part): string
    {
        $pattern = match ($part) {
            'year' => 'YYYY',
            'month' => 'YYYY-MM',
            default => 'YYYY-MM-DD',
        };

        // Pola strftime()/date_format() memakai penanda % yang berbeda.
        $slashedPattern = str_replace(['YYYY', 'MM', 'DD'], ['%Y', '%m', '%d'], $pattern);

        return match (DB::connection()->getDriverName()) {
            'pgsql' => sprintf("to_char(%s, '%s')", $column, $pattern),
            'mysql', 'mariadb' => sprintf("date_format(%s, '%s')", $column, $slashedPattern),
            default => sprintf("strftime('%s', %s)", $slashedPattern, $column),
        };
    }

    /**
     * Total keseluruhan expense dalam cakupan query: jumlah transaksi, total
     * nominal, serta rentang tanggal tertua–terbaru.
     *
     * Tanggal diambil lewat dateExpression() (bukan MIN()/MAX() mentah) karena
     * kolom `date` di SQLite disimpan sebagai DATETIME — MIN() tanpa format
     * akan mengembalikan "2017-05-02 00:00:00", sedangkan di PostgreSQL
     * hanya "2017-05-02". dateExpression() merapikan keduanya jadi format sama.
     */
    private function expenseTotals(Builder $query): object
    {
        $day = $this->dateExpression('expenses.date_shopping', 'day');

        return (clone $query)->toBase()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as total_amount')
            ->selectRaw('MIN('.$day.') as min_date')
            ->selectRaw('MAX('.$day.') as max_date')
            ->first();
    }


    /**
     * Agregasi expense per bucket tanggal (tahun / bulan / hari) via GROUP BY.
     *
     * @param  string  $part  'year' | 'month' | 'day'
     * @return Collection<int, object> baris: {bucket, transaction_count, total_amount}
     */
    private function expenseRowsByDateBucket(Builder $query, string $part): Collection
    {
        $expression = $this->dateExpression('expenses.date_shopping', $part);

        return (clone $query)
            ->whereNotNull('expenses.date_shopping')
            ->toBase()
            ->selectRaw($expression.' as bucket')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as total_amount')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get();
    }

    /**
     * Agregasi expense per (bucket tanggal x kategori) — dipakai untuk blok
     * "Rincian per tahun & kategori" dan "Rincian per bulan".
     *
     * Diurutkan bucket menaik lalu total terbesar lebih dulu di dalam satu
     * bucket, meniru urutan groupByCategory() yang lama (nominal turun).
     *
     * @param  string  $part  'year' | 'month'
     * @return Collection<int, object> baris: {bucket, category_bucket, transaction_count, total_amount}
     */
    private function expenseRowsByDateBucketAndCategory(Builder $query, string $part): Collection
    {
        $expression = $this->dateExpression('expenses.date_shopping', $part);

        return (clone $query)
            ->whereNotNull('expenses.date_shopping')
            ->toBase()
            ->leftJoin('categories', 'categories.id', '=', 'expenses.category_id')
            ->selectRaw($expression.' as bucket')
            ->selectRaw(self::CATEGORY_BUCKET_EXPRESSION.' as category_bucket')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as total_amount')
            ->groupByRaw($expression)
            ->groupByRaw(self::CATEGORY_BUCKET_EXPRESSION)
            ->orderByRaw($expression)
            ->orderByRaw('total_amount DESC')
            ->get();
    }

    /**
     * Agregasi expense per kelompok bebas (kategori / vendor).
     *
     * $limit dipakai untuk daftar "top N": database yang memotong baris,
     * bukan PHP — inilah yang membuat ringkasan tidak ikut membesar bersama
     * jumlah vendor unik milik user.
     *
     * @param  string  $expression  Ekspresi SQL untuk nama kelompok.
     * @param  int|null  $limit  Batas baris, null = semua.
     * @return Collection<int, object> baris: {bucket, transaction_count, total_amount}
     */
    private function expenseRowsByGroup(Builder $query, string $expression, ?int $limit = null): Collection
    {
        return (clone $query)
            ->toBase()
            ->leftJoin('categories', 'categories.id', '=', 'expenses.category_id')
            ->selectRaw($expression.' as bucket')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as total_amount')
            ->groupByRaw($expression)
            ->orderByRaw('total_amount DESC')
            ->when($limit !== null, fn ($builder) => $builder->limit($limit))
            ->get();
    }

    /**
     * Kelompokkan baris agregat berdasarkan bucket-nya. Mempertahankan urutan
     * SQL yang sudah benar (bucket menaik, nominal terbesar lebih dulu di
     * dalam satu bucket).
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int|string, Collection<int, object>>
     */

    /**
     * Ringkasan SELURUH riwayat expense — TANPA filter tanggal/kategori apa
     * pun, supaya pertanyaan umum seperti "total pengeluaran saya berapa?"
     * tetap menghitung data lama (mis. transaksi 2017–2024), bukan hanya
     * data terbaru.
     *
     * Cakupannya tetap SELURUH riwayat: tidak ada lagi batas 90 hari. Yang
     * membatasi hanya BENTUK data yang dikirim — agregat per tahun, per bulan
     * (jendela 12 bulan terakhir), per kategori, dan top vendor — semuanya
     * dihitung di database dengan GROUP BY/LIMIT.
     */
    private function buildUnfilteredExpenseSummary(Builder $base): string
    {
        $lines = ['=== PENGELUARAN (EXPENSE) ==='];

        $totals = $this->expenseTotals($base);
        $count = (int) $totals->transaction_count;

        if ($count === 0) {
            $lines[] = 'Tidak ada data pengeluaran yang tercatat untuk user ini (riwayat kosong).';

            return implode("\n", $lines);
        }

        $total = (float) $totals->total_amount;

        $lines[] = sprintf(
            'Total pengeluaran SELURUH riwayat: Rp %s (%d transaksi).',
            number_format($total, 0, ',', '.'),
            $count
        );
        $lines[] = sprintf(
            'Rata-rata per transaksi (seluruh riwayat): Rp %s.',
            number_format($total / max(1, $count), 0, ',', '.')
        );

        $byYear = $this->expenseRowsByDateBucket($base, 'year');

        if ($byYear->isNotEmpty()) {
            $lines[] = sprintf(
                'Periode data tercatat: %s s.d. %s (%d tahun berbeda).',
                (string) $totals->min_date,
                (string) $totals->max_date,
                $byYear->count()
            );

            $lines[] = 'Rincian total per tahun:';
            foreach ($byYear as $year) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d transaksi)',
                    $year->bucket,
                    number_format((float) $year->total_amount, 0, ',', '.'),
                    (int) $year->transaction_count
                );
            }

            $lines[] = 'Rincian per tahun & kategori (agregat):';
            $byYearCategory = $this->groupRowsByBucket($this->expenseRowsByDateBucketAndCategory($base, 'year'));
            foreach ($byYearCategory as $year => $rows) {
                $lines[] = "  Tahun $year:";
                foreach ($rows as $row) {
                    $lines[] = sprintf(
                        '    - %s: Rp %s (%d transaksi)',
                        $row->category_bucket,
                        number_format((float) $row->total_amount, 0, ',', '.'),
                        (int) $row->transaction_count
                    );
                }
            }
        }

        // Rincian per BULAN untuk 12 bulan terakhir supaya pertanyaan sejenak
        // "pengeluaran bulan ini / 3 bulan terakhir" tetap bisa dijawab dengan
        // data (user cenderung bertanya soal bulan ini).
        $monthWindowStart = now()->startOfMonth()->subMonths(11);
        $monthWindowEnd = now()->startOfMonth();

        $lines[] = sprintf(
            'Rincian per bulan (agregat, %s s.d. %s; bulan tak tercantum = tidak ada pengeluaran):',
            $monthWindowStart->format('Y-m'),
            $monthWindowEnd->format('Y-m')
        );

        $windowed = (clone $base)->whereBetween('expenses.date_shopping', [
            $monthWindowStart->toDateString(),
            $monthWindowEnd->copy()->endOfMonth()->toDateString(),
        ]);
        $byMonthCategory = $this->groupRowsByBucket($this->expenseRowsByDateBucketAndCategory($windowed, 'month'));

        for ($month = $monthWindowStart->copy(); $month <= $monthWindowEnd; $month->addMonth()) {
            $monthKey = $month->format('Y-m');
            $rows = $byMonthCategory->get($monthKey);

            if ($rows === null || $rows->isEmpty()) {
                $lines[] = "  Bulan {$monthKey}: tidak ada pengeluaran tercatat.";

                continue;
            }

            $lines[] = sprintf(
                '  Bulan %s: Rp %s (%d transaksi)',
                $monthKey,

                number_format((float) $rows->sum('total_amount'), 0, ',', '.'),
                (int) $rows->sum('transaction_count')
            );

            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '    - %s: Rp %s (%d transaksi)',
                    $row->category_bucket,
                    number_format((float) $row->total_amount, 0, ',', '.'),
                    (int) $row->transaction_count
                );
            }
        }

        $lines[] = 'Kategori terbesar sepanjang riwayat (top 5):';
        $rank = 0;
        foreach ($this->expenseRowsByGroup($base, self::CATEGORY_BUCKET_EXPRESSION, self::TOP_N) as $row) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $row->bucket,
                number_format((float) $row->total_amount, 0, ',', '.'),
                (int) $row->transaction_count
            );
        }

        $lines[] = 'Vendor dengan pengeluaran terbesar sepanjang riwayat (top 5):';
        $rank = 0;
        foreach ($this->expenseRowsByGroup($base, self::VENDOR_BUCKET_EXPRESSION, self::TOP_N) as $row) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $row->bucket,
                number_format((float) $row->total_amount, 0, ',', '.'),
                (int) $row->transaction_count
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
     * @param  array{
     *     period: ?array{start: Carbon, end: Carbon, label: string},
     *     categories: ?Collection<int, Category>,
     *     types: array<int, string>,
     *     debtSubtypes: array<int, string>
     * }  $filters
     */
    private function buildFilteredExpenseSummary(User $user, Builder $base, array $filters): string
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

        $totals = $this->expenseTotals($base);
        $count = (int) $totals->transaction_count;

        if ($count === 0) {
            $lines[] = sprintf('Tidak ada data pengeluaran untuk filter %s.', $scope);

            return implode("\n", $lines);
        }

        $total = (float) $totals->total_amount;

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
            // Query sengaja dibangun dari nol (bukan dari $base) TANPA filter
            // periode: kalau periode ikut dipasang, hasil count-nya selalu 0
            // karena NULL tidak pernah lolos whereBetween.
            $undated = Expense::query()
                ->where('user_id', $user->id)
                ->whereNull('date_shopping')
                ->when($categories !== null, fn (Builder $query): Builder => $query->whereIn('category_id', $categories->pluck('id')))
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
                ->when($period !== null, fn (Builder $query): Builder => $query->whereBetween('date_shopping', [
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

        // Per bulan — rentang filter yang lebar tetap ringkas karena hanya
        // bulan yang benar-benar punya data yang muncul.
        $lines[] = 'Rincian per bulan (hanya bulan yang ada data):';
        foreach ($this->expenseRowsByDateBucket($base, 'month') as $row) {
            $lines[] = sprintf(
                '  Bulan %s: Rp %s (%d transaksi)',
                $row->bucket,
                number_format((float) $row->total_amount, 0, ',', '.'),
                (int) $row->transaction_count
            );
        }

        // Per hari untuk periode pendek (<= 34 hari: minggu/bulan) supaya
        // pertanyaan "bulan Maret 2025" bisa dijawab rinci per tanggal.
        if ($period !== null && $period['start']->diffInDays($period['end']) <= 34) {
            $lines[] = 'Rincian per hari:';
            foreach ($this->expenseRowsByDateBucket($base, 'day') as $row) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d transaksi)',
                    $row->bucket,
                    number_format((float) $row->total_amount, 0, ',', '.'),
                    (int) $row->transaction_count
                );
            }
        }

        $lines[] = 'Rincian per kategori:';
        foreach ($this->expenseRowsByGroup($base, self::CATEGORY_BUCKET_EXPRESSION) as $row) {
            $lines[] = sprintf(
                '  - %s: Rp %s (%d transaksi)',
                $row->bucket,
                number_format((float) $row->total_amount, 0, ',', '.'),
                (int) $row->transaction_count
            );
        }

        $lines[] = 'Rincian per vendor (top 5):';
        $rank = 0;
        foreach ($this->expenseRowsByGroup($base, self::VENDOR_BUCKET_EXPRESSION, self::TOP_N) as $row) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $row->bucket,
                number_format((float) $row->total_amount, 0, ',', '.'),
                (int) $row->transaction_count
            );
        }

        return implode("\n", $lines);
    }

    private function groupRowsByBucket(Collection $rows): Collection
    {
        return $rows->groupBy(fn (object $row): string => (string) $row->bucket);
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

        $base = Income::query()->where('user_id', $user->id);

        if ($period !== null) {
            $base->whereBetween('date_received', [
                $period['start']->toDateString(),
                $period['end']->toDateString(),
            ]);
        }

        $scope = $period !== null ? 'periode '.$period['label'] : 'SELURUH riwayat';

        $lines = ['=== PEMASUKAN (INCOME) ==='];

        $day = $this->dateExpression('incomes.date_received', 'day');

        // Agregasi di SQL (bukan Income::get()) agar jumlah catatan pemasukan
        // yang bisa mencapai ratusan/ribuan tidak ikut membebani memori.
        $totals = (clone $base)->toBase()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(incomes.amount), 0) as total_amount')
            ->selectRaw('MIN('.$day.') as min_date')
            ->selectRaw('MAX('.$day.') as max_date')
            ->first();

        $count = (int) $totals->transaction_count;

        if ($count === 0) {
            $lines[] = sprintf('Tidak ada data pemasukan %s.', $scope);

            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            'Total pemasukan %s: Rp %s (%d catatan).',
            $scope,
            number_format((float) $totals->total_amount, 0, ',', '.'),
            $count
        );

        if ($totals->min_date !== null) {
            $lines[] = sprintf(
                'Periode data pemasukan: %s s.d. %s.',
                (string) $totals->min_date,
                (string) $totals->max_date
            );
        }

        $yearExpression = $this->dateExpression('incomes.date_received', 'year');

        $byYear = (clone $base)
            ->whereNotNull('date_received')
            ->toBase()
            ->selectRaw($yearExpression.' as bucket')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(incomes.amount), 0) as total_amount')
            ->groupByRaw($yearExpression)
            ->orderByRaw($yearExpression)
            ->get();

        if ($byYear->count() > 1) {
            $lines[] = 'Rincian pemasukan per tahun:';
            foreach ($byYear as $year) {
                $lines[] = sprintf(
                    '  %s: Rp %s (%d catatan)',
                    $year->bucket,
                    number_format((float) $year->total_amount, 0, ',', '.'),
                    (int) $year->transaction_count
                );
            }
        }

        $bySource = (clone $base)->toBase()
            ->selectRaw(self::INCOME_SOURCE_EXPRESSION.' as bucket')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(incomes.amount), 0) as total_amount')
            ->groupByRaw(self::INCOME_SOURCE_EXPRESSION)
            ->orderByRaw('total_amount DESC')
            ->limit(self::TOP_N)
            ->get();

        $lines[] = 'Sumber pemasukan terbesar (top 5):';
        $rank = 0;
        foreach ($bySource as $source) {
            $rank++;
            $lines[] = sprintf(
                '  %d. %s: Rp %s (%d catatan)',
                $rank,
                $source->bucket,
                number_format((float) $source->total_amount, 0, ',', '.'),
                (int) $source->transaction_count
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
        $base = fn (): Builder => Debt::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $filters['debtSubtypes']);

        $lines = ['=== UTANG & PIUTANG (DEBT) ==='];

        if ($base()->toBase()->count() === 0) {
            $lines[] = 'Tidak ada data utang/piutang yang tercatat untuk user ini.';

            return implode("\n", $lines);
        }

        $lines[] = 'Posisi di bawah adalah kondisi TERKINI (bukan rentang periode tertentu).';

        foreach ($filters['debtSubtypes'] as $type) {
            $ofType = fn (): Builder => $base()->where('type', $type);

            $isUtang = $type === Debt::TYPE_UTANG;
            $label = $isUtang ? 'UTANG' : 'PIUTANG';
            $word = $isUtang ? 'lunas' : 'tertagih';

            // Agregasi lewat SQL; hanya daftar rincian yang limited (LIMIT 10)
            // yang masih perlu baris — jadi user dengan ratusan catatan utang
            // tidak memuat semuanya ke memori.
            $active = $ofType()->where('status', '!=', Debt::STATUS_LUNAS);

            $activeTotals = (clone $active)->toBase()
                ->selectRaw('COUNT(*) as active_count')
                ->selectRaw('COALESCE(SUM(debts.amount), 0) as total_amount')
                ->selectRaw('COALESCE(SUM(debts.amount - debts.paid_amount), 0) as total_sisa')
                ->first();

            $activeCount = (int) $activeTotals->active_count;

            if ($activeCount > 0) {
                $overdueCount = (clone $active)->toBase()
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', today())
                    ->count();

                $lines[] = sprintf(
                    '%s AKTIF (belum %s): %d catatan, total sisa Rp %s dari total Rp %s.%s',
                    $label,
                    $word,
                    $activeCount,
                    number_format((float) $activeTotals->total_sisa, 0, ',', '.'),
                    number_format((float) $activeTotals->total_amount, 0, ',', '.'),
                    $overdueCount > 0 ? sprintf(' %d catatan LEBIH JATUH TEMPO.', $overdueCount) : ''
                );

                // Prioritaskan sisa tagihan terbesar — paling relevan buat
                // pertanyaan "ke siapa saya masih punya utang?".
                $details = (clone $active)->toBase()
                    ->orderByRaw('(amount - paid_amount) DESC')
                    ->limit(self::TOP_N * 2)
                    ->get();

                foreach ($details as $debt) {
                    $detail = sprintf(
                        '  - %s: sisa Rp %s dari Rp %s (status: %s',
                        $debt->counterparty_name,
                        number_format((float) $debt->amount - (float) $debt->paid_amount, 0, ',', '.'),
                        number_format((float) $debt->amount, 0, ',', '.'),
                        Debt::statusOptions()[$debt->status] ?? (string) $debt->status
                    );
                    if ($debt->due_date !== null) {
                        $detail .= ', jatuh tempo '.$debt->due_date;
                    }
                    $lines[] = $detail.');';
                }
            }

            $lunasCount = $ofType()->toBase()->where('status', Debt::STATUS_LUNAS)->count();

            if ($lunasCount > 0) {
                $lines[] = sprintf(
                    '%s lunas: %d catatan (tidak dihitung sebagai kewajiban/hak aktif).',
                    $label,
                    $lunasCount
                );
            }
        }

        return implode("\n", $lines);
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
    private function guardAnswer(string $rawAnswer, User $user, string $question): AiInsightResult
    {
        if (AiAnswerSanitizer::hasPathologicalRepetition($rawAnswer)) {
            Log::warning('Jawaban Tanya AI terdeteksi rusak (pengulangan karakter/kata berlebihan) — jawaban tidak ditampilkan ke user.', [
                'user_id' => $user->id,
                // Pertanyaan user = input bebas yang bisa memuat data sensitif; hanyaExcerpt
                // singkat yang disimpan (lihat App\Support\LogSanitizer).
                'question' => LogSanitizer::excerpt($question),
                'answer_length' => mb_strlen($rawAnswer),
                'answer_excerpt' => mb_substr($rawAnswer, 0, 120),
            ]);

            return AiInsightResult::invalidAnswer(self::INVALID_ANSWER_MESSAGE);
        }

        // Pengaman kedua: AI kadang tetap memakai **bold** walau prompt sudah
        // melarangnya. Tanda bintang mentah membingungkan user di notifikasi,
        // jadi dibuang di sini (bukan hanya mengandalkan kepatuhan model).
        $answer = AiAnswerSanitizer::stripMarkdown($rawAnswer);

        // Jawaban yang setelah dibersihkan jadi kosong (mis. isinya hanya "**")
        // sama tidak bergunanya dengan jawaban rusak.
        return $answer === ''
            ? AiInsightResult::invalidAnswer(self::INVALID_ANSWER_MESSAGE)
            : AiInsightResult::answered($answer);
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

        // Ringkasan dipotong SESUDAH tahu berapa karakter yang sudah dipakai
        // system prompt + header + pertanyaan, supaya batasnya benar-benar
        // berlaku untuk prompt UTUH, bukan cuma untuk ringkasannya saja.
        $skeletonLength = mb_strlen($system) + mb_strlen($header) + mb_strlen($question) + self::PROMPT_SKELETON_RESERVE;
        $summary = $this->fitSummaryToBudget($summary, max(self::MIN_SUMMARY_CHARS, self::MAX_PROMPT_CHARS - $skeletonLength));

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
     * Pastikan ringkasan data muat dalam jatah karakter.
     *
     * Bentuk pemotongannya disengaja "tidak merusak struktur":
     *  1. Pemotongan SELALU pada batas baris (tidak pernah memotong di tengah
     *     satu baris angka), jadi tidak ada baris yang jadi tidak terbaca;
     *  2. Baris dipotong dari AKHIR, sedangkan baris paling atas justru yang
     *     paling penting (total keseluruhan, rentang periode, total per
     *     tahun) — jadi data inti tetap utuh;
     *  3. Judul seksi yang isinya sudah habis terpotong ikut dibuang, supaya
     *     AI tidak salah mengira ada rincian yang sengaja disembunyikan;
     *  4. Selalu ditutup penanda eksplisit agar AI tahu datanya ada yang tidak
     *     ditampilkan, alih-alih mengarang angka untuk mengisi bagian yang
     *     hilang.
     *
     * Bentuk ringkas yang sudah dibatasi agregasi SQL + LIMIT membuat
     * pemotongan ini nyaris tidak pernah terjadi; ini jaring pengaman
     * terakhir untuk kasus ekstrem.
     */
    private function fitSummaryToBudget(string $summary, int $budget): string
    {
        if (mb_strlen($summary) <= $budget) {
            return $summary;
        }

        $notice = self::TRUNCATION_NOTICE;
        $lines = explode("\n", $summary);

        // (2) Buang baris dari akhir sampai muat, selalu di batas baris.
        while (count($lines) > 1 && mb_strlen(implode("\n", $lines)) + mb_strlen($notice) + 1 > $budget) {
            array_pop($lines);
        }

        // (3) Buang judul seksi menggantung (tanpa isi lagi).
        while (count($lines) > 1 && $this->isSectionHeading(end($lines))) {
            array_pop($lines);
        }

        // (4) Tutup dengan penanda pemotongan.
        return implode("\n", $lines)."\n".$notice;
    }

    /**
     * Apakah baris ini judul seksi (bukan angka/agregat)?
     *
     * Pola: judul blok '=== ... ===' atau baris yang diakhiri titik dua tanpa
     * angka di dalamnya — misalnya 'Kategori terbesar sepanjang riwayat (top
     * 5):' atau '  2025-03-15: Rp 150.000 (1 transaksi)' yang kedua-tiganya
     * TIDAK boleh dianggap judul (memakai ": " di tengah + ditutup kurung).
     */
    private function isSectionHeading(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '' || preg_match('/\d/', $trimmed) === 1) {
            return false;
        }

        return str_starts_with($trimmed, '===') || str_ends_with($trimmed, ':');
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










