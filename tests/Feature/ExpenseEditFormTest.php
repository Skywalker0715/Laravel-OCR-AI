<?php

use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Verifikasi form Edit Expense yang dilengkapi field koreksi manual:
 *  - Detail Pembayaran (vendor, total, kembalian, tanggal belanja) bisa
 *    diubah user dan tersimpan dengan benar;
 *  - Repeater "Daftar Item Belanja" menyimpan tambah/ubah/hapus item ke
 *    tabel expense_items sesuai perubahan di Repeater;
 *  - mengganti foto struk tetap memicu re-parsing otomatis (OCR + AIParserJob)
 *    seperti sebelumnya.
 */
test('form Edit menyimpan koreksi manual vendor, total, kembalian, dan tanggal belanja', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // FileUpload yang `required` hanya mempertahankan file yang benar-benar ada
    // di disk, jadi sediakan file receipt dummy di disk 'receipts' (fake) —
    // disk privat tempat foto struk disimpan sejak temuan audit #2.
    Storage::fake('receipts');
    Storage::disk('receipts')->put('receipts/sample.jpg', 'dummy-receipt');

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Alpha',
        'vendor' => 'Toko Lama',
        'amount' => 50000,
        'change' => 5000,
        'date_shopping' => '2023-08-01',
        // receipt_image diisi agar field FileUpload yang `required` tetap valid.
        'receipt_image' => 'receipts/sample.jpg',
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->fillForm([
            'title' => 'Belanja Alpha',
            'vendor' => 'Toko Maju Jaya',
            'amount' => 75000,
            'change' => 25000,
            'date_shopping' => '2023-10-15',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $expense->refresh();

    expect($expense->title)->toBe('Belanja Alpha');
    expect($expense->vendor)->toBe('Toko Maju Jaya');
    expect((float) $expense->amount)->toBe(75000.0);
    expect((float) $expense->change)->toBe(25000.0);
    expect($expense->date_shopping->toDateString())->toBe('2023-10-15');
});

test('Repeater item di form Edit menyimpan tambah/ubah/hapus item ke tabel expense_items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('receipts');
    Storage::disk('receipts')->put('receipts/sample.jpg', 'dummy-receipt');

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Beta',
        'receipt_image' => 'receipts/sample.jpg',
    ]);

    // Item yang akan DIHAPUS user lewat Repeater.
    $expense->items()->create([
        'name' => 'Item Lama (dihapus)',
        'qty' => 1,
        'price' => 1000,
        'subtotal' => 1000,
    ]);

    // Item yang akan DIUBAH user lewat Repeater (qty, harga, subtotal).
    $itemDiubah = $expense->items()->create([
        'name' => 'Gula 1kg',
        'qty' => 1,
        'price' => 14000,
        'subtotal' => 14000,
    ]);

    // Simulasikan isi Repeater setelah user mengoreksi — `set` mengganti
    // SELURUH data.items (seperti markup Repeater yang dikirim browser saat
    // submit) sehingga item yang dihapus benar-benar hilang dari state:
    //  - item "Item Lama" tidak dikirim lagi  → harus terhapus;
    //  - item "Gula 1kg" pakai key `record-{id}` → harus di-UPDATE pada baris
    //    yang sama (bukan membuat baris baru);
    //  - satu item baru (tanpa key record)   → harus di-CREATE.
    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->set('data.items', [
            "record-{$itemDiubah->id}" => [
                'name' => 'Gula 1kg',
                'qty' => 2,
                'price' => 14500,
                'subtotal' => 29000,
            ],
            [
                'name' => 'Kopi 1 bungkus',
                'qty' => 1,
                'price' => 5000,
                'subtotal' => 5000,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $expense->refresh();

    $items = $expense->items()->get();

    expect($items)->toHaveCount(2);

    // Item yang tidak lagi ada di Repeater harus terhapus.
    expect($expense->items()->where('name', 'Item Lama (dihapus)')->exists())->toBeFalse();

    // Item yang diubah harus ter-update pada baris yang sama.
    $updated = $expense->items()->find($itemDiubah->id);
    expect($updated)->not->toBeNull();
    expect((float) $updated->qty)->toBe(2.0);
    expect((float) $updated->price)->toBe(14500.0);
    expect((float) $updated->subtotal)->toBe(29000.0);

    // Item baru harus tersimpan.
    expect($items->pluck('name')->all())->toContain('Kopi 1 bungkus');
});

test('halaman Edit Expense menampilkan section baru tanpa mengganggu section lama', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('public');
    Storage::disk('public')->put('receipts/sample.jpg', 'dummy-receipt');

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Gamma',
        'receipt_image' => 'receipts/sample.jpg',
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Informasi Belanja')
        ->assertSee('Foto Struk')
        ->assertSee('Detail Pembayaran')
        ->assertSee('Daftar Item Belanja')
        ->assertSee('Judul Belanja')
        ->assertSee('Kategori')
        ->assertSee('Vendor')
        ->assertSee('Total')
        ->assertSee('Kembalian')
        ->assertSee('Tanggal Belanja')
        // Tombol tambah item Repeater (label field "Nama Item"/"Subtotal" baru
        // muncul saat minimal ada satu item, jadi cukup cek tombol Add-nya).
        ->assertSee('Tambah Item');
});

test('mengganti foto struk di form Edit tetap memicu re-parsing otomatis (OCR + AIParserJob)', function () {
    Queue::fake();
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Catatan: foto struk kini di disk privat 'receipts' (temuan audit #2).
    // Test memakai Storage::fake('receipts') sehingga file uji ditulis ke
    // temp-test, bukan ke storage aplikasi yang sesungguhnya.
    Storage::fake('receipts');

    // Foto lama hanya disimpan sebagai path di DB; file-nya TIDAK perlu ada —
    // FileUpload membuang file yang tidak ada di disk saat mount, sehingga
    // state receipt_image mulai kosong dan bisa diganti bersih lewat fillForm.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Delta',
        'receipt_image' => 'receipts/original.jpg',
    ]);

    // Foto baru yang asli dan bisa dibaca Tesseract (teks besar hitam di atas putih).
    $newImage = Storage::disk('receipts')->path('receipts/baru.jpg');
    writeReceiptImage($newImage);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->fillForm([
            'title' => 'Belanja Delta',
            'receipt_image' => ['receipts/baru.jpg'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $expense->refresh();

    // Foto terbaru tersimpan...
    expect($expense->receipt_image)->toBe('receipts/baru.jpg');

    // ...dan alur re-parsing otomatis tetap jalan: foto baru di-OCR ke kolom
    // `note`, lalu AIParserJob di-dispatch tanpa perlu user menekan tombol apa pun.
    Queue::assertPushed(AIParserJob::class);
    expect($expense->note)->not->toBeEmpty();

    // Bersihkan file yang dibuat test dari disk fake 'receipts'.
    Storage::disk('receipts')->delete(['receipts/baru.jpg', 'receipts/original.jpg']);
});

/**
 * Bikin file JPEG berisi teks sederhana memakai GD (huruf besar hitam di atas
 * putih) supaya Tesseract bisa membacanya — dipakai untuk menguji alur OCR
 * saat foto struk diganti. Font memakai TTF bawaan Windows; bila tidak
 * tersedia, fallback ke imagestring() bawaan GD (gambar tetap valid sehingga
 * OCRService tidak pernah melempar exception).
 */
function writeReceiptImage(string $path): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $width = 800;
    $height = 300;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);

    // Pilih font TTF bawaan Windows (jika ada), kalau tidak pakai font internal.
    $font = null;
    foreach (['C:\Windows\Fonts\arialbd.ttf', 'C:\Windows\Fonts\arial.ttf'] as $candidate) {
        if (is_file($candidate)) {
            $font = $candidate;
            break;
        }
    }

    $texts = ['TOKO MAJU JAYA', '2026-01-05', 'TOTAL 20000'];
    $y = 50;

    foreach ($texts as $text) {
        if ($font !== null) {
            imagettftext($image, 28, 0, 40, $y, $black, $font, $text);
        } else {
            imagestring($image, 5, 40, $y - 10, $text, $black);
        }
        $y += 75;
    }

    imagejpeg($image, $path, 90);
    imagedestroy($image);
}
