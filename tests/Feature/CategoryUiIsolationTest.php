<?php

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Support\ColorHex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Isolasi data kategori pada jalur UI + validasi warna (TASK 7 butir 2 & 4).
 *
 * KEPUTUSAN DESAIN: model Category SENGAJA TIDAK memakai global scope. Alasannya
 * diuji & dikunci pada bagian 3 file ini ("KENAPA TIDAK ADA GLOBAL SCOPE") agar
 * keputusan ini tidak dibatalkan tanpa sadari. Konsekuensinya, setiap jalur UI
 * yang meng-query kategori WAJIB menambahkan filter sendiri — jalur yang sudah
 * ada diuji di bagian 1.
 */

uses(RefreshDatabase::class);

function makePrivateCategory(User $owner, string $name): Category
{
    return Category::create([
        'name' => $name,
        'icon' => 'o-film',
        'color' => '#8B5CF6',
        'user_id' => $owner->id,
    ]);
}

function makeDefaultCategory(string $name): Category
{
    return Category::create([
        'name' => $name,
        'icon' => 'o-shopping-cart',
        'color' => '#10B981',
    ]);
}

/* -------------------------------------------------------------------------
 * 1. Isolasi lintas user pada jalur UI
 * ---------------------------------------------------------------------- */

test('halaman List Kategori tidak menampilkan kategori pribadi milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $kategoriB = makePrivateCategory($userB, 'Rahasia User B');
    $kategoriA = makePrivateCategory($userA, 'Pribadi User A');
    $kategoriDefault = makeDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$kategoriA, $kategoriDefault])
        ->assertCanNotSeeTableRecords([$kategoriB]);
});

test('query resource Kategori menyaring kategori pribadi user lain', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $kategoriB = makePrivateCategory($userB, 'Rahasia User B');
    $kategoriA = makePrivateCategory($userA, 'Pribadi User A');
    $kategoriDefault = makeDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    // Query ini dipakai Filament untuk list, view, edit, delete, route model
    // binding, dan pencarian global — jadi inilah "jalur UI" yang diuji.
    $ids = CategoryResource::getEloquentQuery()->pluck('categories.id')->all();

    expect($ids)->toContain($kategoriA->id)
        ->and($ids)->toContain($kategoriDefault->id)
        ->and($ids)->not->toContain($kategoriB->id);
});

test('kategori default sistem (user_id NULL) tetap terlihat oleh semua user', function () {
    // Sisi lain dari scoping: memfilter HANYA user_id = auth tanpa kilatan ke
    // kategori sistem akan membuat aplikasi kehilangan kategori defaultnya.
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $kategoriDefault = makeDefaultCategory('Kategori Sistem');

    foreach ([$userA, $userB] as $user) {
        $this->actingAs($user);

        expect(CategoryResource::getEloquentQuery()->pluck('categories.id')->all())
            ->toContain($kategoriDefault->id);
    }
});

/* -------------------------------------------------------------------------
 * 2. Validasi warna (form + dipakai di PDF)
 * ---------------------------------------------------------------------- */

test('form kategori menolak kode warna yang bukan hex 6 digit', function (?string $color) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Kategori Warna Uji',
            'icon' => 'o-film',
            'color' => $color,
        ])
        ->call('create')
        ->assertHasFormErrors(['color']);

    $this->assertDatabaseMissing('categories', ['name' => 'Kategori Warna Uji']);
})->with([
    '#FFF',
    '#10B981FF',
    '10B981',
    'red',
    'red; background-image: url(http://contoh.test/a.png)',
]);

test('form kategori menerima kode warna hex yang sah', function (string $color) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Kategori Warna Sah',
            'icon' => 'o-film',
            'color' => $color,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()->where('name', 'Kategori Warna Sah')->value('color'))->toBe($color);
})->with(['#10B981', '#10b981', '#000000', '#FFFFFF']);

test('kategori tanpa warna tetap bisa disimpan (kolom color nullable)', function () {
    // Kolom color nullable: kategori boleh disimpan tanpa warna sama sekali,
    // jadi aturan regex tidak boleh memaksa fill.
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Kategori Tanpa Warna',
            'icon' => 'o-film',
            'color' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()->where('name', 'Kategori Tanpa Warna')->value('color'))->toBeNull();
});

test('warna kategori yang sah tetap dipakai apa adanya di PDF Laporan', function () {
    $html = view('exports.laporan-pdf', [
        'periodLabel' => 'Februari 2026',
        'userName' => 'Uji',
        'generatedAt' => '01/02/2026 10:00',
        'expenses' => collect(),
        'summary' => ['count' => 0, 'total' => 0.0, 'average' => 0.0],
        'categoryBreakdown' => collect([
            ['name' => 'Makanan', 'color' => '#10B981', 'count' => 1, 'total' => 10000.0],
        ]),
        'isTruncated' => false,
        'totalRowCount' => 1,
        'detailLimit' => 1000,
    ])->render();

    expect($html)->toContain('background-color: #10B981');
});

test('warna kategori rusak diganti warna netral saat dirender di PDF Laporan', function () {
    // Lapisan kedua: walau form sudah menolak nilai rusak, kolom color tidak
    // punya constraint isi di database dan bisa berisi data lama/hasil impor.
    // Template PDF harus tetap merender dokumen yang valid.
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create([
        'name' => 'Kategori Rusak',
        'icon' => 'o-film',
        // Lolos batas varchar(20), tapi bukan hex dan mencoba menyuntik aturan
        // CSS lain lewat atribut style.
        'color' => 'red;display:none',
        'user_id' => $user->id,
    ]);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Uji PDF',
        'amount' => 50000,
        'category_id' => $category->id,
    ]);

    $html = view('exports.laporan-pdf', [
        'periodLabel' => 'Februari 2026',
        'userName' => $user->name,
        'generatedAt' => '01/02/2026 10:00',
        'expenses' => collect(),
        'summary' => ['count' => 0, 'total' => 0.0, 'average' => 0.0],
        'categoryBreakdown' => collect([
            ['name' => 'Kategori Rusak', 'color' => $category->color, 'count' => 1, 'total' => 50000.0],
        ]),
        'isTruncated' => false,
        'totalRowCount' => 1,
        'detailLimit' => 1000,
    ])->render();

    expect($html)->toContain('background-color: '.ColorHex::FALLBACK)
        ->and($html)->not->toContain('display:none');
});

test('override warna kategori default milik user lain tidak dipakai user ini', function () {
    // Warna yang tampil = override milik user yang sedang login. Override milik
    // user lain tidak boleh terbaca (dan tidak boleh bocor ke PDF).
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $category = makeDefaultCategory('Kategori Sistem');
    $category->appearanceOverrides()->create(['user_id' => $userB->id, 'color' => '#FF0000']);

    $this->actingAs($userA);

    expect($category->displayColorFor($userA))->toBe($category->color)
        ->and($category->displayColorFor($userB))->toBe('#FF0000');
});

/* -------------------------------------------------------------------------
 * 3. KENAPA TIDAK ADA GLOBAL SCOPE — dokumentasi keputusan (TASK 7 butir 4)
 * ---------------------------------------------------------------------- */

/**
 * Test ini TIDAK menguji fitur — ia mengunci ALASAN kenapa global scope pada
 * model Category tidak dipakai. Kalau suatu saat ada yang menambahkan scope
 * `user_id IS NULL OR user_id = auth` tanpa membaca test ini, test berikut
 * akan gagal dan menjelaskan risikonya.
 *
 * Risiko nyata yang ditemukan saat evaluate:
 *  1. CategorySeeder & AIParserJob berjalan di console/queue TANPA sesi login.
 *     Scope yang hanya aktif saat Auth::check() otomatis OFF di sana,
 *     sedangkan scope yang aktif tanpa Auth akan memblokir pembuatan kategori
 *     default sistem — dua-duanya merusak alur yang sekarang sudah jalan.
 *  2. DeleteUserAccountService menghapus kategori lewat Category::query(),
 *     dan service itu dipanggil saat sesi user yang dihapus masih login.
 *     Scope global membatasi baris yang terhapus menjadi milik user yang
 *     sedang login -> kategori yatim tertinggal & risiko terhapusnya
 *     kategori milik user lain.
 *  3. Halaman & test yang sengaja memanggil Category::find() di luar Filament
 *     ikut berubah perilaku, dari "sempit" menjadi "salah".
 *
 * Karena itu scoping dilakukan EKSPLISIT di tiap jalur UI, dan aturan
 * "wajib ter-scope eksplisit" dicatat di AGENTS.md.
 */
test('Category TIDAK punya global scope; tiap jalur query wajib scoping sendiri', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $kategoriB = makePrivateCategory($userB, 'Rahasia User B');

    $this->actingAs($userA);

    // Sengaja diharapkan mengembalikan record: inilah behavior yang membuat
    // model ini tidak boleh memakai global scope, dan mengapa jalur UI tidak
    // boleh mengandalkan Category::find() tanpa filter eksplisit.
    expect(Category::find($kategoriB->id))->not->toBeNull()
        ->and(CategoryResource::getEloquentQuery()->find($kategoriB->id))->toBeNull();
});