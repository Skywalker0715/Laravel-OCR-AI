<?php

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Models\Expense;
use App\Models\User;
use App\Services\ImageCompressor;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('halaman Create Expense hanya menampilkan Informasi Belanja & Foto Struk (section hasil OCR disembunyikan)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateExpense::class)
        ->assertSee('Informasi Belanja')
        ->assertSee('Foto Struk')
        // Section "Detail Pembayaran" & "Daftar Item Belanja" (beserta tombol
        // "Tambah Item"-nya) HANYA relevan di halaman Edit: saat Create, data
        // OCR/AI belum ada (OCR baru berjalan setelah save), jadi section
        // tersebut disembunyikan dan tidak boleh ikut ter-render.
        ->assertDontSee('Detail Pembayaran')
        ->assertDontSee('Daftar Item Belanja')
        ->assertDontSee('Tambah Item')
        // Tombol aksi Create kini berada di footer Section "Informasi Belanja"
        // (pola yang sama dengan Save/Cancel di halaman Edit).
        ->assertSee('Create & create another');
});

/*
 * afterCreate() berjalan SETELAH record tersimpan. Kegagalan OCR/AI di sana
 * (Tesseract belum terpasang, file rusak, Cohere down) tidak boleh dilempar
 * balik ke user: sebelumnya `throw $th` membuat Filament menampilkan halaman
 * error padahal expense-nya sudah aman di database — user cenderung mengulang
 * create sehingga data jadi duplikat.
 */
test('gagal OCR saat create tetap menyimpan expense tanpa melempar error ke user', function () {
    Log::spy();

    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('receipts');
    Storage::disk('receipts')->put('receipts/struk.jpg', 'dummy-receipt');

    // Pakai file gambar sungguhan supaya form FileUpload (yang `required`)
    // menerimanya, lalu paksa ImageCompressor melempar exception saat
    // afterCreate() memanggilnya — meniru kegagalan OCR di lingkungan nyata.
    writeRealReceiptImage(Storage::disk('receipts')->path('receipts/struk.jpg'));

    $this->instance(ImageCompressor::class, new class extends ImageCompressor
    {
        public function compressReceipt(string $path, int $maxWidth = self::DEFAULT_MAX_WIDTH, int $quality = self::DEFAULT_QUALITY): bool
        {
            throw new RuntimeException('Tesseract tidak terpasang di server');
        }
    });

    // PENTING: tidak ada ->assertStatus(500) / expectException di sini.
    // Kalau afterCreate() masih melempar, test ini gagal dengan exception.
    Livewire::test(CreateExpense::class)
        ->fillForm([
            'title' => 'Belanja Weekday',
            'receipt_image' => ['receipts/struk.jpg'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Data WAJIB tetap tersimpan — inilah inti fix-nya.
    $this->assertDatabaseHas('expenses', [
        'user_id' => $user->id,
        'title' => 'Belanja Weekday',
    ]);

    // Detail teknis dicatat di log server untuk admin menelusuri masalah...
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Gagal memproses OCR/AI')
            && ($context['error'] ?? null) === 'Tesseract tidak terpasang di server');
});

test('notifikasi gagal OCR ke user tidak membocorkan detail teknis exception', function () {
    Log::spy();

    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('receipts');
    Storage::disk('receipts')->put('receipts/struk.jpg', 'dummy-receipt');

    writeRealReceiptImage(Storage::disk('receipts')->path('receipts/struk.jpg'));

    $this->instance(ImageCompressor::class, new class extends ImageCompressor
    {
        public function compressReceipt(string $path, int $maxWidth = self::DEFAULT_MAX_WIDTH, int $quality = self::DEFAULT_QUALITY): bool
        {
            // Pesan exception & path server yang TIDAK boleh bocor ke layar user.
            throw new RuntimeException('SQLSTATE[22003] numeric field overflow di /var/www/vendor/secret/path.php');
        }
    });

    $html = Livewire::test(CreateExpense::class)
        ->fillForm([
            'title' => 'Belanja Error',
            'receipt_image' => ['receipts/struk.jpg'],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        // Notifikasi ramah: menjelaskan kondisi ke user...
        ->assertNotified('Belanja tersimpan, tetapi struk belum terbaca')
        ->html();

    // Yang tampil di layar user = HTML respons Livewire. Pastikan tidak ada
    // detail teknis yang bocor ke sana: pesan exception (SQLSTATE, pesan
    // RuntimeException), path server, maupun stack trace. Log::error tetap
    // menyimpan semuanya di sisi server untuk admin menelusuri masalah.
    //
    // (Judul notifikasi yang ramah sudah dijamin oleh assertNotified() di atas;
    // isinya sendiri dikirim sebagai event Livewire, bukan masuk ke HTML.)
    expect($html)->not->toContain('SQLSTATE')
        ->and($html)->not->toContain('numeric field overflow')
        ->and($html)->not->toContain('/var/www/')
        ->and($html)->not->toContain('vendor/secret/path.php')
        ->and($html)->not->toContain('stack trace')
        ->and($html)->not->toContain('#0 ');
});

/**
 * Bikin file JPEG valid (teks besar hitam di atas putih) di path yang diberikan
 * supaya FileUpload bisa memvalidasi gambar sungguhan. Fallback ke
 * imagestring() GD internal bila font TTF Windows tidak tersedia.
 */
function writeRealReceiptImage(string $path): void
{
    $dir = dirname($path);

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $image = imagecreatetruecolor(400, 200);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, 400, 200, $white);

    $font = null;
    foreach (['C:\Windows\Fonts\arialbd.ttf', 'C:\Windows\Fonts\arial.ttf'] as $candidate) {
        if (is_file($candidate)) {
            $font = $candidate;
            break;
        }
    }

    $font !== null
        ? imagettftext($image, 20, 0, 20, 110, $black, $font, 'TOKO UJI')
        : imagestring($image, 5, 20, 100, 'TOKO UJI', $black);

    imagejpeg($image, $path, 90);
    imagedestroy($image);
}
