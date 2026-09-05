<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Jobs\AIParserJob;
use App\Services\ImageCompressor;
use App\Services\OCRService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Hilangkan aksi simpan/batal default pada footer form (yang biasanya
     * ditempel paling bawah, full-width, sehingga berjarak jauh dari
     * "Informasi Belanja" bila card "Foto Struk" tingginya jauh lebih tinggi).
     * Aksi Save/Cancel dipindah ke footer Section "Informasi Belanja" — lihat
     * App\Filament\Resources\Expenses\Schemas\ExpenseForm — agar tombol
     * berada tepat di bawah isi card tersebut dan tidak menunggu tinggi card
     * "Foto Struk".
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Kompres foto struk yang baru diunggah saat edit (konsisten dengan alur
     * Create). Bila foto struk benar-benar DIGANTI pada simpan ini, jalankan
     * ulang OCR + dispatch AIParserJob secara otomatis agar field
     * vendor/total/tanggal/item mengikuti foto TERBARU — bukan teks OCR lama
     * yang tersimpan di kolom `note`.
     */
    protected function afterSave(): void
    {
        $record = $this->record;

        if (! $record->receipt_image) {
            return;
        }

        // Path file di disk privat 'receipts' (temuan audit #2) —
        // Storage::path() menunjuk storage/app/private/... .
        $path = Storage::disk('receipts')->path($record->receipt_image);

        // Kompres & kecilkan foto struk (sama seperti alur Create). Di-skip
        // bila tidak ada gambar.
        app(ImageCompressor::class)->compressReceipt($path);

        // Kalau foto tidak berubah pada simpan ini, berhenti di sini — tidak
        // perlu OCR ulang (menghemat waktu & tidak menimpa `note` yang masih
        // sesuai foto saat ini).
        if (! $record->wasChanged('receipt_image')) {
            return;
        }

        try {
            // OCR foto terbaru, lalu timpa `note`. Foto sudah diganti, jadi teks
            // OCR lama sudah tidak relevan.
            $text = (new OCRService)->extractTextFromImage($path);

            $record->note = $text;
            // saveQuietly: menyimpan `note` tanpa memicu event model 'updated'
            // (event tersebut dipakai untuk cleanup file lama saat receipt_image
            // berubah dan sudah terpanggil pada simpan utama di atas).
            $record->saveQuietly();

            // Parse ulang secara async lewat queue (AIParserJob::handle masih
            // memakai teks `note` yang baru saja disimpan).
            dispatch(new AIParserJob($record));
        } catch (\Throwable $th) {
            Log::error('Gagal memproses OCR/AI saat mengganti foto struk (Edit)', [
                'expense_id' => $record->id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Gagal memproses gambar')
                ->body('Terjadi kesalahan saat memproses OCR/AI. Silakan coba lagi.')
                ->danger()
                ->send();
        }
    }
}
