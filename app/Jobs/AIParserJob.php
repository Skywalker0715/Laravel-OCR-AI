<?php

namespace App\Jobs;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Services\AIParserService;
use App\Services\Helper;
use App\Services\OCRService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AIParserJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // Timeout job diperpanjang (60 -> 150 detik) mengikuti kenaikan timeout
    // request HTTP ke Cohere di AIParserService (60s + max 2 retry + jeda
    // 500ms) plus waktu OCR & simpan DB - job tidak boleh di-kill duluan
    // sebelum request AI yang lebih panjang itu sempat selesai.
    public $timeout = 150;

    public $backoff = [10, 30, 60];

    /**
     * Batas nilai kolom uang (migration widen_expense_money_columns: qty/price/subtotal
     * decimal(14,2), change decimal(14,2), amount decimal(15,2)). Nilai di luar batas
     * di-NULL-kan oleh sanitizeMoneyForColumn(), bukan membuat query gagal SQLSTATE[22003].
     */
    private const MAX_ITEM_MONEY = 99999999999999.99;

    private const MAX_EXPENSE_AMOUNT = 999999999999999.99;

    private const MAX_EXPENSE_CHANGE = 99999999999999.99;

    /** Ambang selisih ABSOLUT (Rp) SUM(subtotal item) vs Total yang dianggap
     * signifikan untuk flag items_mismatch. Di bawah ini dianggap noise
     * pembulatan OCR, bukan salah baca item. */
    private const MISMATCH_DIFF_ABSOLUTE = 1000.0;

    /** Ambang selisih RELATIF (fraksi dari Total) yang dianggap signifikan.
     * Signifikan bila selisih melewati ambang absolut ATAU relatif (OR) —
     * salah baca satu item pada struk bernilai besar bisa berupa persentase
     * kecil, sedangkan pada struk kecil selisih Rp1.000+ sudah mencurigakan. */
    private const MISMATCH_DIFF_RELATIVE = 0.05;

    private Expense $record;

    private Helper $helper;

    /**
     * Create a new job instance.
     */
    public function __construct(Expense $record)
    {
        $this->record = $record;
    }

    public function handle(): void
    {
        $result = $this->reprocess($this->record);

        Log::info('AIParserJob finished for record id: '.$this->record->id.' → ok='.var_export($result['ok'], true));
    }

    /** Proses ulang (parse + simpan) expense dari `note` — dipakai job queue, command
     * expenses:reprocess, dan tombol "Proses Ulang" agar perilaku seragam; selalu
     * mengirim notifikasi hasil (ok=false bila gagal) agar tidak gagal diam-diam.
     *
     * @return array{ok: bool, note: string}
     */
    public function reprocess(Expense $record, bool $forceReocr = false): array
    {
        Log::info('AIParserJob started for record id: '.$record->id.' forceReocr='.var_export($forceReocr, true));

        $this->helper = app(Helper::class);

        // Pastikan ada teks untuk diparse: bila `note` kosong atau forceReocr,
        // jalankan OCR ulang dari foto struk yang tersimpan.
        $note = (string) ($record->note ?? '');
        if (($forceReocr || trim($note) === '') && $record->receipt_image) {
            $note = $this->tryReocr($record);
        }
        $record->note = $note ?: null;

        Log::info('Raw note text: '.$note);

        // 1) Parse lewat AI (Cohere) dengan fallback regex otomatis di dalamnya.
        // Instance disimpan agar jalur yang dipakai (AI vs Fallback) bisa
        // dicatat ke log untuk diagnosa.
        $parser = new AIParserService;
        $parsed = null;
        try {
            $parsed = $parser->parseWithAI(text: $note);
        } catch (\Throwable $e) {
            Log::error('AIParserService melempar exception untuk record '.$record->id.': '.$e->getMessage());
        }

        $parsingPath = $parser->usedFallback ? 'FALLBACK REGEX' : 'AI (COHERE)';
        Log::info('Parsed data (jalur: '.$parsingPath.') untuk record '.$record->id.': '.json_encode($parsed));
        $parsed = is_array($parsed) ? $parsed : [];

        $lines = array_values(array_filter(array_map('trim', explode("\n", $note))));
        $items = $parsed['items'] ?? [];

        // 2) Guard Total (jalur AI & fallback) — struk dengan diskon punya
        //    beberapa kandidat total ("Jumlah" sebelum diskon vs "TOTAL BAYAR"
        //    sesudah diskon) dan AI kadang memilih pra-diskon. Lapisan validasi
        //    memakai baris total FINAL eksplisit ("TOTAL BAYAR"/"GRAND TOTAL"/dll.)
        //    sebagai pembanding ber-confidence tinggi. Timpa nilai parsing hanya
        //    bila memang tepat (lihat explicitTotalOverrideJustified): label
        //    eksplisit TIDAK boleh menimpa parsing yang didukung penjumlahan
        //    item — bisa jadi labelnya yang salah baca OCR (kasus BNI: OCR baca
        //    "TOTAL BAYAR Rp 148.975" padahal TAG PLN + ADMIN BANK = 146.975).
        $amount = (float) ($parsed['total'] ?? 0);

        $explicitFinalTotal = $this->extractExplicitFinalTotal($lines);
        if ($explicitFinalTotal !== null) {
            if ($this->explicitTotalOverrideJustified($amount, $items, $explicitFinalTotal['value'], $lines)) {
                if (abs($explicitFinalTotal['value'] - $amount) > 0.01) {
                    Log::warning(sprintf(
                        'Guard Total (record %d): baris total final eksplisit "%s" = %.2f dipakai menggantikan nilai parsing %.2f (kandidat total pra-diskon atau kosong).',
                        $record->id,
                        $explicitFinalTotal['label'],
                        $explicitFinalTotal['value'],
                        $amount,
                    ));
                }
                $amount = $explicitFinalTotal['value'];
            } else {
                Log::warning(sprintf(
                    'Guard Total (record %d): nilai parsing %.2f konsisten dengan penjumlahan item, sedangkan baris "%s" = %.2f beda tanpa penjelasan diskon/biaya — parsing dipertahankan (label kemungkinan salah baca OCR). Mohon cek kelengkapan.',
                    $record->id,
                    $amount,
                    $explicitFinalTotal['label'],
                    $explicitFinalTotal['value'],
                ));
            }
        } elseif ($amount <= 0) {
            $amount = $this->helper->extractBestTotal($lines, $items);
        } else {
            // Tanpa label eksplisit, bandingan longgar dengan extractBestTotal:
            // nilai AI tetap dipakai (signal confidence rendah), namun selisih
            // signifikan dicatat ke log agar user bisa cek kelengkapan.
            $bestTotal = $this->helper->extractBestTotal($lines, $items);
            if ($bestTotal > 0 && abs($bestTotal - $amount) > 0.01) {
                Log::warning(sprintf(
                    'Guard Total (record %d): nilai total %.2f beda signifikan dari extractBestTotal %.2f - nilai parsing tetap dipakai, mohon cek kelengkapan.',
                    $record->id,
                    $amount,
                    $bestTotal,
                ));
            }
        }
        $amount = round(max(0, $amount), 2);

        $vendor = trim((string) ($parsed['vendor'] ?? ($lines[0] ?? '')));
        $date = $this->normalizeDate($parsed['date'] ?? null);

        // 3) Guard Kembalian (jalur AI & fallback) - AI kadang memilih angka baris
        //    yang BUKAN kembalian tunai (mis. DP/Bayar, Uang Muka, Sisa Bayar) saat
        //    struk tidak punya baris kembalian. Change hanya dipreserve bila teks
        //    OCR memuat kata kunci kembalian di baris yang berisi angka.
        $change = (float) ($parsed['change'] ?? 0);
        if ($change != 0.0 && ! $this->noteMentionsChangeKey($note)) {
            Log::warning(sprintf(
                'Guard Kembalian (record %d): teks OCR tidak memuat kata kunci kembali/change tetapi hasil parsing memberi change=%.2f (kemungkinan DP/Uang Muka/Sisa Bayar) - change dipaksa 0.',
                $record->id,
                $change,
            ));
            $change = 0.0;
        }

        $categoryLabel = $parsed['category'] ?? Category::inferCategoryName($vendor);
        $category = Category::resolveFromLabel($categoryLabel, $record->user_id);

        // 3) Simpan di kesempatan pertama agar partial result tidak hilang; nilai uang
        // di-guard batas kolom (migration 2026_09_03_000001) — angka tak wajar hasil
        // parsing di-NULL-kan + warning agar save() tetap sukses (title & foto tersimpan).
        $record->vendor = $vendor !== '' ? $vendor : null;

        // Tanggal belanja hasil parse hanya diisi bila kolom masih kosong/NULL -
        // tanggal yang SUDAH ditetapkan user manual (saat create, form Edit, atau
        // reprocess "Proses Ulang") tidak ditimpa oleh hasil parse. Prinsip
        // manual-field override, konsisten dengan kategori di bawah: job TIDAK
        // menimpa field yang sudah punya nilai dari user.
        if (blank($record->date_shopping)) {
            $record->date_shopping = $date;
        }
        $record->amount = $this->sanitizeMoneyForColumn(
            $amount, 'expenses.amount', self::MAX_EXPENSE_AMOUNT, $record->id
        );
        $record->change = $this->sanitizeMoneyForColumn(
            $change, 'expenses.change', self::MAX_EXPENSE_CHANGE, $record->id
        );
        $record->parsed_data = $items;

        // Kategori hasil parse (tebakan AI "category" maupun fallback regex) hanya
        // diisi bila kolom category_id masih kosong/NULL. User yang SUDAH memilih
        // kategori manual saat create, form Edit, atau reprocess "Proses Ulang"
        // TIDAK boleh ditimpa oleh tebakan AI. Prinsip sama dengan date_shopping:
        // job tidak menimpa field yang sudah punya nilai dari user.
        if (blank($record->category_id)) {
            $record->category_id = $category?->id;
        }
        // Simpan juga jalur parsing yang dipakai (true = fallback regex,
        // false = AI Cohere). Dipakai halaman View Expense untuk menampilkan
        // notice informasi "diproses otomatis" tanpa memanggil API lagi.
        // Cast (bool) eksplisit: nilai yang terikat ke PostgreSQL harus
        // boolean asli, bukan integer 1/0 (kolom bertipe boolean).
        $record->used_fallback = (bool) $parser->usedFallback;

        // Guard mismatch item vs Total (jalur AI & fallback): OCR kadang salah
        // membaca item (mis. dua baris terbaca identik) sehingga SUM(subtotal
        // item) tidak cocok dengan Total. Flag items_mismatch menandai struk
        // tersebut agar halaman View Expense menampilkan peringatan cek manual.
        // Nilai pembanding memakai amount final yang tersimpan di record
        // (hasil Guard Total di atas, sudah lewat sanitize kolom) — bila
        // sanitize meng-NULL-kan amount, tidak ada pembanding berarti → false.
        $record->items_mismatch = $this->detectItemsTotalMismatch(
            items: $items,
            amount: (float) ($record->amount ?? 0),
            lines: $lines,
            expenseId: $record->id,
        );

        try {
            $record->save();
            Log::info('Record saved for id: '.$record->id.' (amount='.$amount.')');
        } catch (\Throwable $e) {
            Log::error('Gagal menyimpan record id '.$record->id.': '.$e->getMessage());
        }

        // 4) Simpan item struktur — hapus dulu yang lama agar reprocess tidak
        //    menumpuk baris dobel. Baris tidak valid/negatif dilewati.
        $record->items()->delete();
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['name'])) {
                continue;
            }
            try {
                // Guard qty/price/subtotal terhadap batas kolom decimal(14,2)
                // (migration 2026_09_03_000001): nilai tidak wajar disimpan
                // NULL + warning, bukan membuat INSERT item gagal total.
                $record->items()->create([
                    'name' => (string) ($item['name'] ?? 'Item'),
                    'qty' => $this->sanitizeMoneyForColumn(
                        max(0, (float) ($item['qty'] ?? 1)), 'expense_items.qty', self::MAX_ITEM_MONEY, $record->id
                    ),
                    'price' => $this->sanitizeMoneyForColumn(
                        max(0, (float) ($item['price'] ?? 0)), 'expense_items.price', self::MAX_ITEM_MONEY, $record->id
                    ),
                    'subtotal' => $this->sanitizeMoneyForColumn(
                        max(0, (float) ($item['subtotal'] ?? 0)), 'expense_items.subtotal', self::MAX_ITEM_MONEY, $record->id
                    ),
                ]);
            } catch (\Throwable $e) {
                Log::error('Gagal membuat item untuk record '.$record->id.': '.$e->getMessage());
            }
        }

        // 5) Indikator kegagalan total: tidak ada nominal maupun info tersisa.
        $extractable = $amount > 0 || ($vendor !== null && $vendor !== '') || $date !== null || count($items) > 0;

        // Notifikasi hasil parsing (sukses AI / fallback / gagal total). Peringatan
        // budget tidak dikirim dari sini — Expense::saved memanggil BudgetAlertService,
        // sehingga jalur manual (Create/Edit) pun ikut terpantau.
        $this->notifyParsingResult($record, $extractable);

        return [
            'ok' => $extractable,
            'note' => $extractable
                ? 'Selesai. Total '.MoneyFormatter::format($amount).
                    ($vendor !== '' ? ' di '.$vendor : '').
                    ($date ? ' ('.$date.')' : '')
                : 'Parsing gagal total — tidak ada data yang bisa diekstrak.',
        ];
    }

    /**
     * Coba jalankan OCR ulang dari foto struk yang sudah tersimpan di disk.
     * Dipakai ketika kolom `note` masih kosong (mis. expense lama yang gagal).
     */
    private function tryReocr(Expense $record): string
    {
        try {
            // Path file di disk privat 'receipts' (temuan audit #2) —
            // Storage::path() tetap valid di konteks queue (tanpa auth).
            $path = Storage::disk('receipts')->path($record->receipt_image);
            if (! is_file($path)) {
                Log::warning('Foto struk tidak ditemukan saat OCR ulang untuk record '.$record->id);

                return '';
            }

            $text = (new OCRService)->extractTextFromImage($path);
            Log::info('OCR ulang sukses untuk record '.$record->id);
            $record->note = $text;
            $record->saveQuietly();

            return (string) $text;
        } catch (\Throwable $e) {
            Log::error('OCR ulang gagal untuk record '.$record->id.': '.$e->getMessage());

            return '';
        }
    }

    /**
     * Validasi & normalisasi tanggal ke 'Y-m-d' sebelum disimpan; null bila
     * tidak valid. Menerima hasil AI maupun format dd-mm-YYYY.
     */
    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $str = trim((string) $value);
        $flexible = $this->helper->parseFlexibleDate($str);

        if ($flexible) {
            return $flexible;
        }

        // Nilai dari AI kadang "2023-08-04 15:36" — ambil bagian tanggalnya.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $str, $m)) {
            return $this->helper->parseFlexibleDate($m[1]);
        }

        return null;
    }

    /** Guard nilai uang terhadap batas kolom numerik PostgreSQL: angka di luar batas
     * (biasanya regex/AI salah tangkap nomor IDPEL/NPWP/no. HP sebagai nominal)
     * disimpan NULL + warning, bukan menggagalkan penyimpanan (SQLSTATE[22003]).
     *
     * @return float|null Nilai aman untuk disimpan, atau NULL bila di luar batas.
     */
    private function sanitizeMoneyForColumn(float $value, string $column, float $max, int $expenseId): ?float
    {
        if ($value > $max) {
            Log::warning(sprintf(
                'Nilai hasil parsing di luar batas kolom %s (maks %.2f) untuk record %d: %.4e — disimpan NULL agar data struk lain tetap tersimpan.',
                $column,
                $max,
                $expenseId,
                $value
            ));

            return null;
        }

        return $value;
    }

    /**
     * Cari baris total FINAL eksplisit (pasca-diskon/pajak) ber-confidence tinggi:
     * "TOTAL BAYAR", "GRAND TOTAL", "TOTAL TAGIHAN", "TOTAL PEMBAYARAN",
     * "TOTAL AKHIR", atau "JUMLAH BAYAR". Baris pembayaran (DP/Bayar, Sisa Bayar,
     * Uang Muka, Dibayar) di-skip agar nominalnya tidak tertangkap sebagai total.
     *
     * @param  array<int, string>  $lines  Baris teks OCR yang sudah di-trim.
     * @return array{label: string, value: float}|null  null bila tidak ditemukan.
     */
    private function extractExplicitFinalTotal(array $lines): ?array
    {
        $finalPatterns = [
            '/\btotal\s+bayar\b/iu',
            '/\bgrand\s+total\b/iu',
            '/\btotal\s+tagihan\b/iu',
            '/\btotal\s+pembayaran\b/iu',
            '/\btotal\s+akhir\b/iu',
            '/\bjumlah\s+bayar\b/iu',
        ];

        // Skip baris pembayaran yang mengandung kata bayar/dp tapi bukan total final.
        $paymentPattern = '/\b(dibayar|dp\s*\/\s*bayar|sisa\s+bayar|uang\s+muka|\bdp\b)\b/i';

        foreach ($lines as $line) {
            $lower = mb_strtolower($line);

            if (preg_match($paymentPattern, $lower) === 1) {
                continue;
            }

            foreach ($finalPatterns as $pattern) {
                if (preg_match($pattern, $lower) === 1) {
                    $value = $this->helper->extractLargestNumber($line);
                    if ($value > 0) {
                        return ['label' => trim($line), 'value' => $value];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Putuskan apakah baris total final eksplisit layak menimpa nilai parsing.
     *
     * Eksplisit menimpa parsing bila salah satu terpenuhi:
     *  1. parsing kosong/nol — tidak ada nilai yang perlu dipertahankan;
     *  2. parsing TIDAK didukung penjumlahan subtotal item (berdiri sendiri,
     *     biasanya AI menangkap baris "Jumlah" pra-diskon — kasus struk laundry);
     *  3. parsing didukung item, tetapi selisihnya ke nilai eksplisit dijelaskan
     *     baris diskon/biaya (mis. "Diskon Member 10% -Rp 6.600" menjelaskan
     *     66.000 → 59.400).
     *
     * Bila parsing didukung penjumlahan item DAN selisih tidak dijelaskan baris
     * apapun, label eksplisit kemungkinan salah baca OCR (kasus BNI: "TOTAL
     * BAYAR Rp 148.975" padahal item berjumlah tepat 146.975) — parsing
     * dipertahankan dan mismatch hanya dilaporkan ke log.
     *
     * @param  array<int, array<string, mixed>>  $items  Item hasil parsing.
     * @param  array<int, string>  $lines  Baris teks OCR yang sudah di-trim.
     */
    private function explicitTotalOverrideJustified(float $parsedTotal, array $items, float $explicitValue, array $lines): bool
    {
        if ($parsedTotal <= 0) {
            return true;
        }

        $itemSum = 0.0;
        foreach ($items as $item) {
            $itemSum += max(0, (float) ($item['subtotal'] ?? 0));
        }

        $itemSumCorroborates = $itemSum > 0 && abs($itemSum - $parsedTotal) <= 0.01;
        if (! $itemSumCorroborates) {
            return true;
        }

        return $this->differenceExplainedByAdjustmentLine(
            abs($explicitValue - $parsedTotal),
            $parsedTotal,
            $lines
        );
    }

    /**
     * Deteksi ketidakcocokan antara penjumlahan subtotal item dan Total struk
     * (kolom `amount`). True bila:
     *  1. Ada item dan total yang bisa dibandingkan (struk tagihan tanpa item
     *     seperti listrik/PDAM tidak dinilai mismatch);
     *  2. Selisih signifikan: > Rp1.000 ATAU > 5% dari Total (OR, lihat
     *     konstanta MISMATCH_DIFF_*);
     *  3. Selisih TIDAK bisa dijelaskan baris diskon/PPN/biaya admin dsb. yang
     *     sudah dikenali Guard Total — termasuk penjelasan dengan residual
     *     kecil (mis. PPN sah 8.789 + noise salah baca item Rp500), karena
     *     noise item di bawah toleransi tetap dianggap wajar.
     *
     * Jalurnya netral terhadap used_fallback: masalah ini bisa terjadi pada
     * struk yang berhasil lewat AI sekalipun (kasus struk laundry).
     *
     * @param  array<int, mixed>  $items  Item hasil parsing (AI/fallback).
     * @param  array<int, string>  $lines  Baris teks OCR yang sudah di-trim.
     */
    private function detectItemsTotalMismatch(array $items, float $amount, array $lines, int $expenseId): bool
    {
        // Tanpa total yang valid, tidak ada pembanding yang berarti.
        if ($amount <= 0) {
            return false;
        }

        // Kriteria validitas item SAMA dengan loop penyimpanan item agar
        // flag mencerminkan item yang benar-benar tersimpan di database.
        $itemSum = 0.0;
        $itemCount = 0;
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['name'])) {
                continue;
            }
            $itemSum += max(0, (float) ($item['subtotal'] ?? 0));
            $itemCount++;
        }

        // Struk tanpa item (mis. tagihan listrik/PDAM) memang tidak punya
        // penjumlahan item — bukan kasus mismatch.
        if ($itemCount === 0 || $itemSum <= 0) {
            return false;
        }

        $diff = abs($amount - $itemSum);
        $relativeDiff = self::MISMATCH_DIFF_RELATIVE * $amount;
        if ($diff <= self::MISMATCH_DIFF_ABSOLUTE && $diff <= $relativeDiff) {
            return false;
        }

        // Selisih dijelaskan pola yang dikenali (diskon/PPN/biaya)? Toleransi
        // residual memakai ambang absolut flag: baris penjelasan tidak harus
        // persis sama dengan selisih, sisa selisih <= ambang (atau 1% dari
        // total, mana yang lebih besar) masih dianggap noise pembacaan item.
        $baseAmount = max($amount, $itemSum);
        $residualTolerance = max(self::MISMATCH_DIFF_ABSOLUTE, 0.01 * $baseAmount);
        if ($this->differenceExplainedByAdjustmentLine($diff, $baseAmount, $lines, $residualTolerance)) {
            return false;
        }

        Log::warning(sprintf(
            'Guard Mismatch Item (record %d): SUM(subtotal %d item) = %.2f tidak cocok dengan Total %.2f (selisih %.2f) tanpa penjelasan diskon/PPN/biaya — flag items_mismatch = true, mohon periksa manual.',
            $expenseId,
            $itemCount,
            $itemSum,
            $amount,
            $diff,
        ));

        return true;
    }

    /**
     * True bila selisih antara dua kandidat total dijelaskan oleh baris
     * diskon/biaya di teks struk — selisih cocok dengan nominal di baris
     * tersebut (mis. "-Rp 6.600") atau dengan persentase diskon terhadap
     * total pra-diskon (mis. "Diskon 10%" dari 66.000 = 6.600). False bila
     * selisih tidak punya penjelasan, yang menandakan salah satu kandidat
     * salah baca OCR.
     *
     * @param  float  $tolerance  Toleransi kecocokan nominal baris terhadap
     *                            selisih (default 0.01 = harus persis, dipakai
     *                            Guard Total; deteksi mismatch item memakai
     *                            toleransi lebih besar untuk residual noise).
     * @param  array<int, string>  $lines
     */
    private function differenceExplainedByAdjustmentLine(float $diff, float $baseAmount, array $lines, float $tolerance = 0.01): bool
    {
        if ($diff <= 0.01) {
            return true;
        }

        $adjustmentPattern = '/disc|diskon|potong|hemat|rabat|biaya|admin|ongkir|ongkos|service|charge|fee|pajak|tax|ppn/iu';

        foreach ($lines as $line) {
            if (preg_match($adjustmentPattern, $line) !== 1) {
                continue;
            }

            // Nominal eksplisit di baris diskon/biaya (mis. "Diskon ... -Rp 6.600").
            $number = $this->helper->extractLargestNumber($line);
            if ($number > 0 && abs($number - $diff) <= $tolerance) {
                return true;
            }

            // Persentase diskon (mis. "Diskon Member 10%") terhadap total
            // pra-diskon; toleransi minimum Rp 1 untuk pembulatan.
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $line, $m) === 1) {
                $percent = (float) str_replace(',', '.', $m[1]);
                if (abs($baseAmount * $percent / 100 - $diff) <= max(1.0, $tolerance)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True bila teks OCR memuat kata kunci kembalian ("kembali"/"change") di baris
     * yang juga mengandung angka - baris kembalian yang sah. False bila kata kunci
     * hanya muncul pada naratif (mis. "tidak dapat dikembalikan").
     */
    private function noteMentionsChangeKey(string $note): bool
    {
        foreach (explode("\n", $note) as $line) {
            if (preg_match('/kembal|change/iu', $line) === 1 && preg_match('/\d/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Notifikasi hasil parsing ke pemilik expense lewat lonceng Filament: sukses AI
     * → success; sukses fallback regex → warning + tombol Edit; gagal total → danger
     * + tombol Edit. Database notification (bukan toast) karena job berjalan di queue.
     */
    private function notifyParsingResult(Expense $record, bool $extractable): void
    {
        $user = $record->user_id ? User::find($record->user_id) : null;
        if (! $user) {
            Log::warning('Tidak dapat mengirim notifikasi hasil parsing (user tidak ditemukan) untuk record '.$record->id);

            return;
        }

        $title = $record->title ?: ($record->vendor ?: 'Struk');
        $notification = Notification::make();

        if (! $extractable) {
            // Gagal total: tidak ada nominal, vendor, tanggal, maupun item
            // yang bisa diekstrak. Jaring pengaman agar kegagalan TIDAK pernah
            // terjadi dalam diam — user diarahkan mengisi manual.
            Log::error('Parsing gagal total (tidak ada data yang bisa diekstrak) untuk record id: '.$record->id);

            $notification
                ->danger()
                ->title("Struk {$title} gagal diproses otomatis — silakan isi manual")
                ->body('Tidak ada data (vendor, tanggal, total, item) yang bisa dibaca dari struk ini. Mohon lengkapi datanya secara manual.')
                ->persistent()
                ->actions([
                    Action::make('edit')
                        ->label('Isi Manual')
                        ->button()
                        ->url(self::editExpenseUrl($record)),
                ]);
        } elseif ($record->used_fallback) {
            // Sukses tapi lewat jalur fallback regex — hasil kemungkinan
            // tidak seteliti jalur AI, jadi user diminta mengecek.
            $notification
                ->warning()
                ->title("Struk {$title} diproses dengan estimasi otomatis — mohon cek kelengkapannya")
                ->body('Struk ini diproses lewat parser cadangan (bukan AI). Data yang terisi mungkin kurang lengkap, mohon periksa dan koreksi jika perlu.')
                ->actions([
                    Action::make('edit')
                        ->label('Periksa & Edit')
                        ->button()
                        ->url(self::editExpenseUrl($record)),
                ]);
        } else {
            // Sukses penuh lewat AI Cohere.
            $notification
                ->success()
                ->title("Struk {$title} berhasil diproses otomatis")
                ->body('Data struk sudah tersimpan otomatis: '
                    .MoneyFormatter::format($record->amount)
                    .(($record->vendor ?? '') !== '' ? ' di '.$record->vendor : '').'.');
        }

        $notification->sendToDatabase($user);
    }

    /**
     * URL halaman Edit Expense untuk tombol notifikasi. Dibungkus try-catch agar
     * perubahan route di masa depan tidak menggagalkan pengiriman notifikasi.
     */
    private static function editExpenseUrl(Expense $record): ?string
    {
        try {
            return ExpenseResource::getUrl('edit', ['record' => $record]);
        } catch (\Throwable $e) {
            Log::warning('Gagal membuat URL Edit Expense untuk notifikasi record '.$record->id.': '.$e->getMessage());

            return null;
        }
    }
}
