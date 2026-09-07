<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyaji foto struk dari disk privat 'receipts' (audit #2). Hanya pemilik expense
 * yang bisa akses (404 bila bukan pemilik); tamu dialihkan ke login oleh middleware Filament.
 */
class ReceiptImageController extends Controller
{
    public function __invoke(Expense $expense): StreamedResponse
    {
        // Route model binding sudah ter-scope OwnedByUserScope (expense milik
        // user lain tidak pernah resolve), jadi cek eksplisit ini adalah
        // safety net kedua sesuai spesifikasi audit.
        abort_unless((int) $expense->user_id === (int) auth()->id(), 404);
        abort_if(blank($expense->receipt_image), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('receipts');

        abort_unless($disk->exists($expense->receipt_image), 404);

        // response() men-stream file dengan Content-Type sesuai ekstensi
        // (inline), bukan meng-copy file ke folder public.
        return $disk->response($expense->receipt_image);
    }
}