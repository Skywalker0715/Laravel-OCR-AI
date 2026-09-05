<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ImageCompressor;
use App\Services\OCRService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * Hilangkan aksi Create / Create & create another / Cancel bawaan pada
     * footer form (yang dirender full-width mengambang di paling bawah,
     * terpisah dari card manapun). Ketiganya dipindah ke footer Section
     * "Informasi Belanja" — lihat App\Filament\Resources\Expenses\Schemas\
     * ExpenseForm — mengikuti pola yang sama dengan tombol Save/Cancel
     * di halaman Edit.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Setiap expense baru otomatis menjadi milik user yang sedang login.
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        try {
            $record = $this->record;

            if ($record->receipt_image) {
                // Path file di disk privat 'receipts' (temuan audit #2) —
                // Storage::path() menunjuk storage/app/private/... tanpa
                // bergantung pada lokasi fisik manual.
                $path = Storage::disk('receipts')->path($record->receipt_image);

                // Kompres & kecilkan foto struk (lebarkan maks. 1000px, JPEG q75)
                // SEBELUM OCR, supaya file tersimpan ringan namun tetap terbaca.
                // Kompresi dilakukan in-place, jadi path/DB tidak berubah.
                app(ImageCompressor::class)->compressReceipt($path);

                $ocr = new OCRService;
                $text = $ocr->extractTextFromImage(path: $path);

                $record->note = $text;
                $record->save();

                // Dispatch the job to parse the note using AI
                dispatch(new \App\Jobs\AIParserJob(record: $record));

            }

        } catch (\Throwable $th) {
            Log::error('Gagal memproses OCR/AI pada pembuatan expense', [
                'expense_id' => $this->record->id ?? null,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Gagal memproses gambar')
                ->body('Terjadi kesalahan saat memproses OCR/AI. Silakan coba lagi.')
                ->danger()
                ->send();

            throw $th;
        }
    }
}
