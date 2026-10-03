<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Jobs\AIParserJob;
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

    /**
     * Jalankan OCR + dispatch AIParserJob setelah expense tersimpan.
     *
     * PENTING: hook ini dipanggil SETELAH record benar-benar tersimpan di
     * database (CreateRecord::create()). Semua kegagalan di sini — Tesseract
     * tidak terpasang, file rusak, Cohere down, timeout — TIDAK berarti data
     * hilang, dan TIDAK boleh dilempar balik ke user: sebelumnya `throw $th`
     * membuat Filament menampilkan halaman error 500 padahal expense-nya sudah
     * aman di database, dan user cenderung mengulang create → data duplikat.
     *
     * Strategi sekarang: catat detail teknisnya di log server (untuk admin
     * menelusuri masalah) lalu beri notifikasi ramah yang menjelaskan kondisinya
     * — expense tetap tersimpan, hanya isi otomatisnya yang belum ada. Detail
     * teknis (exception message & trace) TIDAK PERNAH ditampilkan ke user.
     */
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
                dispatch(new AIParserJob(record: $record));
            }

        } catch (\Throwable $th) {
            // Log server: hanya untuk admin, tidak pernah tampil di UI. Sengaja TIDAK
            // memakai getTraceAsString() (TASK 7): stack trace aplikasi sendiri tidak
            // menambah nilai diagnosa — penyebab kegagalan ini selalu berasal
            // dari Tesseract / Cohere / Storage, bukan dari logika internal —
            // sementara argumen fungsi pada frame yang lebih dalam bisa memuat
            // path server. Pesan exception + id expense sudah cukup untuk
            // menemukan akar masalah.
            Log::error('Gagal memproses OCR/AI pada pembuatan expense', [
                'expense_id' => $this->record->id ?? null,
                'error' => $th->getMessage(),
            ]);

            // Notifikasi ramah: user hanya diberi tahu dampaknya (data manual yang
            // perlu diisi), tanpa jargon teknis. Expense SUDAH tersimpan.
            Notification::make()
                ->title('Belanja tersimpan, tetapi struk belum terbaca')
                ->body('Foto struk tidak berhasil diproses otomatis. Data belanja Anda sudah tersimpan — silakan isi vendor, total, dan daftar item secara manual.')
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
