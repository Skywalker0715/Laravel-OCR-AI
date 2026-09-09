<?php

use App\Jobs\AIParserJob;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('inferCategoryName memetakan teks ke kategori kanonik', function () {
    expect(Category::inferCategoryName('Indomaret'))->toBe('Makanan & Minuman');
    expect(Category::inferCategoryName('Nasi dan Ayam'))->toBe('Makanan & Minuman');
    expect(Category::inferCategoryName('Bensin Pertamina'))->toBe('Transportasi');
    expect(Category::inferCategoryName('Apotek Kimia Farma'))->toBe('Kesehatan');
    expect(Category::inferCategoryName('Bingung apa kategorinya'))->toBe('Lainnya');
    expect(Category::inferCategoryName(''))->toBe('Lainnya');
});

test('resolveFromLabel memakai kategori default sistem & membuat yang baru', function () {
    $user = User::factory()->create();

    // Label yang cocok dengan daftar default → instance milik sistem (user_id null).
    $makanan = Category::resolveFromLabel('Makanan & Minuman', $user->id);
    expect($makanan->user_id)->toBeNull();
    expect($makanan->name)->toBe('Makanan & Minuman');

    // Panggilan kedua mengembalikan instance yang sama (tidak dobel).
    $again = Category::resolveFromLabel('Makanan', $user->id);
    expect($again->id)->toBe($makanan->id);
    expect(Category::where('name', 'Makanan & Minuman')->count())->toBe(1);

    // Label yang tidak dikenali → fallback ke kategori default "Lainnya"
    // (bukan membuat kategori sembarang dari tebakan AI).
    $custom = Category::resolveFromLabel('Tidak Mengenal Kategori Ini', $user->id);
    expect($custom->name)->toBe('Lainnya');
    expect($custom->user_id)->toBeNull();
});

test('AIParserJob menetapkan category_id berdasarkan tebak kategori fallback', function () {
    // Pastikan Cohere "gagal" sehingga AIParserService memakai parseWithFallback
    // (regex) dan menginfer kategori dari teks (tanpa panggilan jaringan nyata).
    Http::fake(['https://api.cohere.ai/*' => Http::response('servis tidak tersedia', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Indomaret',
        'note' => <<<'TXT'
        Indomaret
        Jl. Merdeka No 1
        No.03 2023-08-04 15:36
        1 x Nasi Padang Rp15.000
        Total Rp25.000
        Kembalian Rp0
        TXT,
    ]);

    (new AIParserJob($expense))->handle();

    $expense->refresh();

    expect($expense->category_id)->not->toBeNull();
    expect($expense->category->name)->toBe('Makanan & Minuman');
    expect($expense->vendor)->toBe('Indomaret');
    expect((float) $expense->amount)->toBeGreaterThan(0.0);
});

test('AIParserJob TIDAK menimpa category_id yang SUDAH dipilih user manual (jalur AI)', function () {
    // Mock Cohere sukses dengan kategori yang JALURNYA dari pilihan manual user,
    // untuk mem-bukti bahwa tebakan AI tidak boleh langsup menimpa expense.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SPBU Pertamina","date":"2026-09-02","category":"Makanan & Minuman","items":[{"name":"Bensin","qty":1,"price":50000,"subtotal":50000}],"total":50000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();

    // User SUDAH memilih kategori manual "Transportasi" saat create expense.
    $manualCategory = Category::resolveFromLabel('Transportasi', $user->id);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'SPBU Bensin',
        'category_id' => $manualCategory->id,
        'note' => 'SPBU Pertamina\n2026-09-02\nTotal Rp 50.000\n',
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    // Tebakan AI ("Makanan & Minuman") TIDAK boleh menimpa kategori manual.
    expect($expense->used_fallback)->toBeFalse();
    expect($expense->category_id)->toBe($manualCategory->id);
    expect($expense->category->name)->toBe('Transportasi');
});

test('AIParserJob TIDAK menimpa category_id manual saat reprocess "Proses Ulang" (forceReocr)', function () {
    // AI gagal → jalur fallback regex; fallback menginfer "Makanan & Minuman"
    // dari vendor/item Indomaret, tapi kategori manual user "Transportasi"
    // harus tetap dipreserve — reprocess tombol "Proses Ulang" jalan lewat
    // reprocess($record, true) yang same method ini.
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $manualCategory = Category::resolveFromLabel('Transportasi', $user->id);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'SPBU Bensin',
        'category_id' => $manualCategory->id,
        'note' => <<<'TXT'
        Indomaret
        Jl. Merdeka No 1
        2023-08-04 15:36
        1 x Nasi Padang Rp15.000
        Total Rp25.000
        Kembalian Rp0
        TXT,
    ]);

    // forceReocr=true = alur tombol "Proses Ulang OCR & AI" (ViewExpense).
    $result = (new AIParserJob($expense))->reprocess($expense, true);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();
    expect($expense->used_fallback)->toBeTrue();

    // Kategori manual tetap "Transportasi", bukan hasil infer fallback
    // ("Makanan & Minuman" dari vendor Indomaret).
    expect($expense->category_id)->toBe($manualCategory->id);
    expect($expense->category->name)->toBe('Transportasi');
});

test('AIParserJob masih mengisi category_id otomatis bila user BELUMBA memilih kategori (jalur AI)', function () {
    // Expense tanpa kategori manual → tebakan AI tetap diisi otomatis
    // (perilaku lama untuk kasus ini harus tetap jalan).
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SPBU Pertamina","date":"2026-09-03","category":"Transportasi","items":[{"name":"Bensin","qty":1,"price":25000,"subtotal":25000}],"total":25000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'SPBU Bensin',
        'note' => 'SPBU Pertamina\n2026-09-03\nTotal Rp 25.000\n',
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    expect($expense->category_id)->not->toBeNull();
    expect($expense->category->name)->toBe('Transportasi');
});

test('AIParserJob memberi peringatan ketika expense melebihi budget kategori', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('status tidak tersedia', 500)]);

    $user = User::factory()->create();

    // Kategori berjenis Makanan & Minuman + budget khusus bulan 8/2023.
    $category = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        // Sengaja kecil agar amount hasil parse (> 0) sudah melampaui budget.
        'amount' => 100,
        'month' => 8,
        'year' => 2023,
    ]);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Indomaret',
        'note' => "Indomaret\nNo.03 2023-08-04 15:36\n1 x Nasi Padang Rp15.000\nTotal Rp25.000\nKembalian Rp0\n",
    ]);

    $this->actingAs($user);

    (new AIParserJob($expense))->handle();

    $expense->refresh();

    // Ambil judul semua notifikasi milik user (kolom `data` berisi JSON).
    $notificationTitles = fn (): array => collect(
        DB::table('notifications')->where('notifiable_id', $user->id)->get()
    )
        ->map(fn ($n) => (string) (json_decode((string) $n->data, true)['title'] ?? ''))
        ->all();

    // Expense pertama (bulan 8) melebihi budget 100 → notifikasi budget
    // terlampaui harus terkirim. Sejak ada notifikasi hasil parsing (job juga
    // mengabari "struk diproses"), penghitungan dilakukan PER JENIS notifikasi,
    // bukan total semua notifikasi di tabel.
    expect((float) $expense->amount)->toBeGreaterThan(0.0);

    // Ambang 100% tercapai → notif "terlampaui" tepat 1x.
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'terlampaui'))->count())->toBe(1);
    // Pemakaian langsung melewati 100% → ambang 90% ikut terkirim, juga 1x.
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'terpakai 90%+'))->count())->toBe(1);
    // Hasil parsing jalur fallback juga dinotifikasikan ke pemilik expense.
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'estimasi otomatis'))->count())->toBe(1);

    // Budget yang masih cukup → tidak ada notifikasi baru.
    $bigBudget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 50000,
        'month' => 9,
        'year' => 2023,
    ]);

    $other = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja September',
        'note' => "Indomaret\nY.05 2023-09-05 10:00\nTotal Rp30.000\nKembalian Rp0\n",
    ]);

    (new AIParserJob($other))->handle();

    $other->refresh();
    expect((float) $other->amount)->toBeGreaterThan(0.0);
    expect($other->date_shopping->toDateString())->toBe('2023-09-05');

    // Expense September masih dalam budget 50.000 → TIDAK ada notifikasi
    // budget baru (jumlah notifikasi budget tetap seperti sebelumnya).
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'terlampaui'))->count())->toBe(1);
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'terpakai 90%+'))->count())->toBe(1);
    // Notifikasi hasil parsing bertambah 1 untuk struk September (fallback).
    expect(collect($notificationTitles())->filter(fn ($t) => str_contains($t, 'estimasi otomatis'))->count())->toBe(2);
});
