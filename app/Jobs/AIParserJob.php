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

    public $timeout = 60;

    public $backoff = [10, 30, 60];

    /**
     * Batas nilai kolom uang setelah migration 2026_09_03_000001
     * (widen_expense_money_columns):
     *  - expense_items.qty / price / subtotal → decimal(14,2)
     *  - expenses.change                      → decimal(14,2)
     *  - expenses.amount                      → decimal(15,2) (tidak berubah)
     * Nilai hasil parsing yang melewati batas ini di-NULL-kan oleh
     * sanitizeMoneyForColumn(), bukan dibiarkan membuat query gagal
     * SQLSTATE[22003] "numeric field overflow".
     */
    private const MAX_ITEM_MONEY = 99999999999999.99;

    private const MAX_EXPENSE_AMOUNT = 999999999999999.99;

    private const MAX_EXPENSE_CHANGE = 99999999999999.99;

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

    /**
     * Proses ulang (parse + simpan) sebuah expense dari teks OCR di kolom `note`.
     * Dipakai oleh job berantre (handle) maupun command `expenses:reprocess` /
     * tombol "Proses Ulang" agar perilakunya selalu sama.
     *
     * Tujuannya: TIDAK PERNAH meninggalkan expense dalam keadaan kosong total
     * secara diam-diam. Jika memang tidak ada data yang bisa diekstrak, tetap
     * dikirim notifikasi danger ke pemilik expense dan status 'ok' false.
     *
     * @return array{ok: bool, note: string}
     */
    public function reprocess(Expense $record, bool $forceReocr = false): array
    {
        Log::info('AIParserJob started for record id: '.$record->id.' forceReocr='.var_export($forceReocr, true));

        $this->helper = app(Helper::class);

        // Pastikan ada teks untuk diparse. Bila belum ada (expense lama yang
        // sempat gagal), coba OCR ulang dari foto struk yang sudah tersimpan —
        // tanpa perlu user mengunggah foto dari awal.
        //
        // forceReocr dipaksakan tombol "Proses Ulang OCR & AI" di ViewExpense
        // agar selalu memakai foto struk TERBARU dari kolom receipt_image,
        // bukan teks OCR yang sudah kadaluarsa (stale note). Akibatnya sebelumnya:
        // ganti foto lewat form Edit tidak mengubah vendor/total, karena parsing
        // masih memakai `note` lama yang tidak pernah di-refresh.
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

        // 2) Normalisasi field dengan "best-effort": bila AI/fallback memberi
        //    nilai kosong, isi dengan nilai paling mendekati (baris "Total
        //    Belanja" / angka terbesar di teks OCR) agar amount tidak pernah
        //    tertinggal kosong.
        $amount = (float) ($parsed['total'] ?? 0);
        if ($amount <= 0) {
            $amount = $this->helper->extractBestTotal($lines, $items);
        }
        $amount = round(max(0, $amount), 2);

        $vendor = trim((string) ($parsed['vendor'] ?? ($lines[0] ?? '')));
        $date = $this->normalizeDate($parsed['date'] ?? null);
        $change = (float) ($parsed['change'] ?? 0);

        $categoryLabel = $parsed['category'] ?? Category::inferCategoryName($vendor);
        $category = Category::resolveFromLabel($categoryLabel, $record->user_id);

        // 3) Simpan di kesempatan pertama agar partial result tidak hilang.
        // Nilai uang di-guard dulu terhadap batas kolom (lihat migration
        // 2026_09_03_000001): angka tidak masuk akal hasil parsing — umumnya
        // regex/AI salah menangkap nomor IDPEL/NPWP/no. HP/kode referensi
        // sebagai nominal — di-NULL-kan + log warning, BUKAN dibiarkan membuat
        // seluruh save() gagal. Sesuai strategi project: title & foto struk
        // harus tetap tersimpan agar user bisa mengoreksi manual.
        $record->vendor = $vendor !== '' ? $vendor : null;
        $record->date_shopping = $date;
        $record->amount = $this->sanitizeMoneyForColumn(
            $amount, 'expenses.amount', self::MAX_EXPENSE_AMOUNT, $record->id
        );
        $record->change = $this->sanitizeMoneyForColumn(
            $change, 'expenses.change', self::MAX_EXPENSE_CHANGE, $record->id
        );
        $record->parsed_data = $items;
        $record->category_id = $category?->id;
        // Simpan juga jalur parsing yang dipakai (true = fallback regex,
        // false = AI Cohere). Dipakai halaman View Expense untuk menampilkan
        // notice informasi "diproses otomatis" tanpa memanggil API lagi.
        // Cast (bool) eksplisit: nilai yang terikat ke PostgreSQL harus
        // boolean asli, bukan integer 1/0 (kolom bertipe boolean).
        $record->used_fallback = (bool) $parser->usedFallback;

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

        // Notifikasi hasil parsing ke pemilik expense (lonceng Filament):
        // sukses via AI, sukses via fallback regex, atau gagal total.
        // Peringatan budget TIDAK dikirim dari sini — model event
        // Expense::saved sudah memanggil BudgetAlertService setiap kali
        // amount/kategori/tanggal belanja expense berubah, sehingga jalur
        // manual (form Create/Edit) pun ikut terpantau.
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

    /**
     * Guard nilai uang hasil parsing terhadap batas kolom numerik PostgreSQL.
     *
     * Angka di luar batas (hampir selalu regex/AI salah menangkap nomor
     * identitas struk — IDPEL, NPWP, no. HP/WA, kode referensi — sebagai
     * nominal rupiah) TIDAK boleh membuat seluruh penyimpanan gagal dengan
     * SQLSTATE[22003] "numeric field overflow". Sesuai strategi project,
     * nilai ekstrem disimpan sebagai NULL + log warning: title & foto struk
     * tetap tersimpan dan user bisa mengoreksi manual.
     *
     * @return float|null Nilai yang aman disimpan, atau NULL bila di luar batas.
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
     * Notifikasi hasil parsing ke pemilik expense lewat lonceng Filament.
     *
     * Pesannya bergantung pada jalur & hasil parsing:
     *  - Sukses via AI Cohere       → sukses (info).
     *  - Sukses via fallback regex  → warning + tombol menuju halaman Edit
     *    expense, karena hasil regex mungkin tidak lengkap/salah.
     *  - Gagal total                → danger + tombol menuju halaman Edit,
     *    agar user mengisi data struk secara manual.
     *
     * Notifikasi database dipakai (bukan toast) karena job berjalan dari
     * worker queue — tidak ada request browser yang bisa menerima toast.
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
     * URL halaman Edit Expense untuk tombol aksi pada notifikasi.
     *
     * Dibungkus try-catch: bila route/route name resource berubah di masa
     * depan, kegagalan membuat URL TIDAK boleh menggagalkan pengiriman
     * notifikasinya — cukup tombolnya yang tidak muncul.
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
