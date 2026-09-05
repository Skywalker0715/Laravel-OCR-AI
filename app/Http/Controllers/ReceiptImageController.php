<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyaji foto struk dari disk privat 'receipts' (temuan audit keamanan #2).
 *
 * Foto struk TIDAK lagi disajikan lewat /storage (disk public) — hanya lewat
 * route ini yang menjamin dua hal:
 *  1. Hanya PEMILIK expense yang bisa melihat fotonya. User lain mendapat
 *     404 (bukan 403) supaya keberadaan file tidak bocor lewat kode status.
 *  2. Tamu diarahkan ke halaman login panel oleh middleware Filament
 *     Authenticate (terpasang di definisi route).
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