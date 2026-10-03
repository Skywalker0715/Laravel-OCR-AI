<?php

use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/* -------------------------------------------------------------------------
 * 1. Unique constraint budgets
 * ---------------------------------------------------------------------- */

test('budgets: duplikat (user + kategori + bulan + tahun) ditolak database', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Kategori Budget Uji', 'user_id' => $user->id]);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 1,
        'year' => 2026,
    ]);

    $row = fn (array $override = []): array => array_merge([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 250000,
        'month' => 1,
        'year' => 2026,
        'created_at' => now(),
        'updated_at' => now(),
    ], $override);

    // Kombinasi sama → ditolak DB (constraint dari migration integritas).
    expect(fn () => DB::table('budgets')->insert($row()))
        ->toThrow(QueryException::class);

    // Bulan berbeda → boleh.
    DB::table('budgets')->insert($row(['month' => 2]));

    // User lain pada kategori & periode yang sama → boleh.
    $otherUser = User::factory()->create();
    DB::table('budgets')->insert($row(['user_id' => $otherUser->id]));

    expect(Budget::withoutGlobalScopes()->count())->toBe(3);
});

test('budgets: dua anggaran umum (category_id NULL) pada periode sama ditolak database', function () {
    $user = User::factory()->create();

    $row = fn (array $override = []): array => array_merge([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 500000,
        'month' => 3,
        'year' => 2026,
        'created_at' => now(),
        'updated_at' => now(),
    ], $override);

    DB::table('budgets')->insert($row());

    // Nullable TIDAK boleh jadi celah duplikat (di PostgreSQL dipakai
    // NULLS NOT DISTINCT, di SQLite partial unique index).
    expect(fn () => DB::table('budgets')->insert($row()))
        ->toThrow(QueryException::class);

    // Periode lain tetap boleh.
    DB::table('budgets')->insert($row(['month' => 4]));

    expect(Budget::withoutGlobalScopes()->count())->toBe(2);
});

/* -------------------------------------------------------------------------
 * 2. Unique constraint categories
 * ---------------------------------------------------------------------- */

test('categories: nama duplikat ditolak per user, tapi boleh antar user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $row = fn (array $override = []): array => array_merge([
        'name' => 'Kategori Unik Uji',
        'icon' => 'o-tag',
        'color' => '#10B981',
        'user_id' => $userA->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $override);

    DB::table('categories')->insert($row());

    // Nama sama + user sama → ditolak.
    expect(fn () => DB::table('categories')->insert($row()))
        ->toThrow(QueryException::class);

    // User lain dengan nama sama → boleh.
    DB::table('categories')->insert($row(['user_id' => $userB->id]));

    // Beda huruf besar/kecil untuk user yang sama → BOLEH: perbandingan nama
    // tetap case-sensitive (perilaku lama dipertahankan, tidak diubah).
    DB::table('categories')->insert($row(['name' => 'kategori unik uji']));

    expect(Category::where('name', 'Kategori Unik Uji')->count())->toBe(2)
        ->and(Category::where('name', 'kategori unik uji')->count())->toBe(1);
});

test('categories: nama duplikat untuk kategori default sistem (user_id NULL) ditolak', function () {
    $row = fn (array $override = []): array => array_merge([
        'name' => 'Kategori Default Uji',
        'icon' => 'o-tag',
        'color' => '#64748B',
        'user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $override);

    DB::table('categories')->insert($row());

    expect(fn () => DB::table('categories')->insert($row()))
        ->toThrow(QueryException::class);

    // Kategori MILIK user dengan nama sama tetap boleh (index terpisah).
    $user = User::factory()->create();
    DB::table('categories')->insert($row(['user_id' => $user->id]));

    expect(Category::where('name', 'Kategori Default Uji')->count())->toBe(2);
});

/* -------------------------------------------------------------------------
 * 3. Index pendukung debts
 * ---------------------------------------------------------------------- */

test('debts: index (user_id, due_date) dan (user_id, status) dibuat', function () {
    expect(Schema::hasIndex('debts', 'debts_user_id_due_date_index'))->toBeTrue()
        ->and(Schema::hasIndex('debts', 'debts_user_id_status_index'))->toBeTrue();
});

/* -------------------------------------------------------------------------
 * 4. Form Budget: pesan ramah (bukan SQL mentah)
 * ---------------------------------------------------------------------- */

test('form Create Budget menolak duplikat dengan pesan ramah, bukan error SQL', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create(['name' => 'Kategori Form Uji', 'user_id' => $user->id]);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 8,
        'year' => 2026,
    ]);

    Livewire::test(CreateBudget::class)
        ->fillForm([
            'category_id' => (string) $category->id,
            'amount' => '150000',
            'month' => '8',
            'year' => '2026',
        ])
        ->call('create')
        ->assertHasFormErrors(['month'])
        ->assertSee('Sudah ada anggaran untuk kategori dan periode (bulan + tahun) ini.')
        ->assertDontSee('SQLSTATE');
});

test('form Create Budget: duplikat yang lolos validasi (balapan) tetap tampil sebagai pesan ramah', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create(['name' => 'Kategori Balapan Form', 'user_id' => $user->id]);

    // Simulasi request lain menyisipkan budget kombinasi sama TEPAT sebelum
    // INSERT dijalankan (saat validasi form, DB masih kosong sehingga rule
    // scopedUnique lolos) — unique constraint yang menangkap, lalu page
    // menerjemahkannya jadi pesan validasi ramah.
    Event::listen('eloquent.creating: '.Budget::class, function () use ($user, $category): void {
        DB::table('budgets')->insert([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 99000,
            'month' => 4,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        Livewire::test(CreateBudget::class)
            ->fillForm([
                'category_id' => (string) $category->id,
                'amount' => '88000',
                'month' => '4',
                'year' => '2026',
            ])
            ->call('create')
            ->assertHasFormErrors(['month'])
            ->assertSee('Sudah ada anggaran untuk kategori dan periode (bulan + tahun) ini.')
            ->assertDontSee('SQLSTATE');
    } finally {
        // Bersihkan listener model supaya tidak bocor ke test lain, lalu minta
        // model boot ulang agar hook bawaan Budget terdaftar kembali.
        Event::forget('eloquent.creating: '.Budget::class);
        Budget::clearBootedModels();
    }

    // Hanya baris "request lain" (amount 99000) yang ada — budget dari form
    // (amount 88000) TIDAK sempat tersimpan setengah jadi: INSERT-nya ditolak
    // unique constraint sebelum menghasilkan baris apa pun.
    // Catatan: panel Filament di project ini TIDAK mengaktifkan
    // databaseTransactions(), jadi tidak ada rollback otomatis Filament; yang
    // terbukti di sini adalah penolakan terjadi di level DB sebelum efek samping.
    expect(Budget::withoutGlobalScopes()->pluck('amount')->map(fn ($v): float => (float) $v)->all())
        ->toBe([99000.0]);
});

/* -------------------------------------------------------------------------
 * 5. Category::findOrCreateByName tahan race
 * ---------------------------------------------------------------------- */

test('findOrCreateByName: dua pemanggilan berurutan menghasilkan satu record', function () {
    $user = User::factory()->create();

    $first = Category::findOrCreateByName('Kategori Idempoten Uji', $user->id);
    $second = Category::findOrCreateByName('Kategori Idempoten Uji', $user->id);

    expect($first)->not->toBeNull()
        ->and($second->getKey())->toBe($first->getKey())
        ->and(Category::where('name', 'Kategori Idempoten Uji')->count())->toBe(1);
});

test('findOrCreateByName: pelanggaran unique (balapan) ditangkap dan tidak menduplikasi', function () {
    $user = User::factory()->create();
    $name = 'Kategori Balapan Kategori';

    // "Proses lain" menyisipkan baris yang sama TEPAT sebelum INSERT di dalam
    // findOrCreateByName dieksekusi (setelah pengecekan internal gagal).
    Event::listen('eloquent.creating: '.Category::class, function () use ($name, $user): void {
        DB::table('categories')->insert([
            'name' => $name,
            'icon' => 'o-tag',
            'color' => '#64748B',
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $category = Category::findOrCreateByName($name, $user->id);
    } finally {
        Event::forget('eloquent.creating: '.Category::class);
        Category::clearBootedModels();
    }

    // Tidak melempar exception & memakai baris yang sudah ada (bukan baris
    // kedua) → tetap SATU record.
    expect($category)->not->toBeNull()
        ->and($category->name)->toBe($name)
        ->and((int) $category->user_id)->toBe($user->id)
        ->and(Category::where('name', $name)->count())->toBe(1);
});

