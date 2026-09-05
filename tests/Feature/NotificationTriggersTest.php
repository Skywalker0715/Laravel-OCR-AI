<?php

use App\Jobs\AIParserJob;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Helper: ambil judul seluruh notifikasi database milik seorang user.
 * Kolom `data` pada tabel notifications berisi JSON milik Filament.
 */
function notificationTitlesFor(int $userId): array
{
    return collect(
        DB::table('notifications')->where('notifiable_id', $userId)->get()
    )
        ->map(fn ($n) => (string) (json_decode((string) $n->data, true)['title'] ?? ''))
        ->all();
}

function countTitlesContaining(array $titles, string $needle): int
{
    return collect($titles)->filter(fn ($t) => str_contains($t, $needle))->count();
}

/**
 * Helper: gabungan isi kolom `data` (JSON) semua notifikasi user, dengan
 * escape `\/` khas json_encode dinormalkan kembali menjadi `/` supaya URL
 * bisa dicari sebagai substring biasa.
 */
function notificationRawDataFor(int $userId): string
{
    return str_replace(
        '\/',
        '/',
        (string) DB::table('notifications')->where('notifiable_id', $userId)->pluck('data')->implode("\n")
    );
}

test('TRIGGER 1: notif sukses via AI terkirim setelah struk berhasil diparsing', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'messages' => [
            ['text' => '{"vendor":"Toko Maju","date":"2026-09-02","category":"Makanan & Minuman","items":[{"name":"Beras","qty":1,"price":15000,"subtotal":15000}],"total":15000,"change":0}'],
        ],
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk AI',
        'note' => 'Toko Maju',
    ]);

    (new AIParserJob($expense))->handle();

    $titles = notificationTitlesFor($user->id);

    expect(countTitlesContaining($titles, 'Struk AI berhasil diproses otomatis'))->toBe(1)
        ->and(countTitlesContaining($titles, 'estimasi otomatis'))->toBe(0)
        ->and(countTitlesContaining($titles, 'gagal diproses otomatis'))->toBe(0);
});

test('TRIGGER 1: notif estimasi (fallback regex) terkirim dengan link ke halaman Edit', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('servis tidak tersedia', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Fallback',
        'note' => "Karis Jaya Shop\n2023-08-02\nTotal Rp 36.000\nKembali Rp 0\n",
    ]);

    (new AIParserJob($expense))->handle();

    $titles = notificationTitlesFor($user->id);

    expect(countTitlesContaining($titles, 'Struk Fallback diproses dengan estimasi otomatis — mohon cek kelengkapannya'))->toBe(1)
        ->and(countTitlesContaining($titles, 'berhasil diproses otomatis'))->toBe(0);

    // Notifikasi fallback membawa link ke halaman Edit expense terkait.
    expect(notificationRawDataFor($user->id))
        ->toContain('/expenses/'.$expense->getKey().'/edit');
});

test('TRIGGER 1: notif gagal total terkirim dengan link ke halaman Edit', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('servis tidak tersedia', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Note kosong & tanpa foto → tidak ada data yang bisa diekstrak sama sekali.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Kosong',
        'note' => '',
    ]);

    (new AIParserJob($expense))->handle();

    $titles = notificationTitlesFor($user->id);

    expect(countTitlesContaining($titles, 'Struk Kosong gagal diproses otomatis — silakan isi manual'))->toBe(1)
        ->and(countTitlesContaining($titles, 'estimasi otomatis'))->toBe(0);

    // Notifikasi gagal juga membawa link ke halaman Edit (isi manual).
    expect(notificationRawDataFor($user->id))
        ->toContain('/expenses/'.$expense->getKey().'/edit');
});

test('TRIGGER 2: notif budget 90% & 100% terkirim sekali per ambang per periode', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Budget umum (category_id NULL) periode September 2026 sebesar Rp10.000.
    Budget::create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 10000,
        'month' => 9,
        'year' => 2026,
    ]);

    // 9.200 / 10.000 = 92% → hanya notifikasi ambang 90%.
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja A',
        'amount' => 9200,
        'date_shopping' => '2026-09-02',
    ]);

    $titles = notificationTitlesFor($user->id);
    expect(countTitlesContaining($titles, 'sudah terpakai 90%+'))->toBe(1)
        ->and(countTitlesContaining($titles, 'sudah terlampaui'))->toBe(0);

    // 10.100 / 10.000 = 101% → notif "terlampaui" terkirim; ambang 90%
    // TIDAK terkirim ulang (flag notified_90_at sudah terisi).
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja B',
        'amount' => 900,
        'date_shopping' => '2026-09-03',
    ]);

    $titles = notificationTitlesFor($user->id);
    expect(countTitlesContaining($titles, 'sudah terpakai 90%+'))->toBe(1)
        ->and(countTitlesContaining($titles, 'sudah terlampaui'))->toBe(1);

    // Tambah expense lagi + update field tak relevan di bulan yang sama
    // → TIDAK ada notifikasi budget baru (anti dobel per bulan).
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja C',
        'amount' => 500,
        'date_shopping' => '2026-09-05',
    ]);

    Expense::query()->where('title', 'Belanja A')->first()->update(['title' => 'Belanja A2']);

    $titles = notificationTitlesFor($user->id);
    expect(countTitlesContaining($titles, 'sudah terpakai 90%+'))->toBe(1)
        ->and(countTitlesContaining($titles, 'sudah terlampaui'))->toBe(1);

    // Bukti state tersimpan di tabel budgets: kedua flag terisi tepat sekali.
    $budget = DB::table('budgets')->where('user_id', $user->id)->first();
    expect($budget->notified_90_at)->not->toBeNull()
        ->and($budget->notified_100_at)->not->toBeNull();
});

test('TRIGGER 2: pemakaian budget di bawah ambang tidak mengirim notifikasi', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 1000000,
        'month' => 9,
        'year' => 2026,
    ]);

    // 10.000 / 1.000.000 = 1% → jauh di bawah ambang 90%.
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Kecil',
        'amount' => 10000,
        'date_shopping' => '2026-09-07',
    ]);

    expect(countTitlesContaining(notificationTitlesFor($user->id), 'Budget'))->toBe(0);
});
