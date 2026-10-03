<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Helper LOKAL (nama sengaja unik — helper global Pest lain seperti
 * notificationTitlesFor() sudah dipakai NotificationTriggersTest).
 */
function aiparserNotificationTitlesFor(int $userId): array
{
    return collect(DB::table('notifications')->where('notifiable_id', $userId)->get())
        ->map(fn ($n) => (string) (json_decode((string) $n->data, true)['title'] ?? ''))
        ->all();
}

function aiparserCountTitlesContaining(array $titles, string $needle): int
{
    return collect($titles)->filter(fn (string $t) => str_contains($t, $needle))->count();
}

/* -------------------------------------------------------------------------
 * (a) Edit user di tengah job → vendor/total milik user TIDAK ditimpa
 * ---------------------------------------------------------------------- */

test('(a) vendor & total yang diedit user saat job berjalan tidak ditimpa hasil parsing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Edit Manual',
        'note' => "Toko AI\n2026-09-02\nBeras 15.000\nTotal Rp 15.000\n",
    ]);

    // Mock Cohere: SAAT request sedang dilayani (job masih berjalan), user
    // mengedit vendor & total lewat "request lain" (query builder, seperti
    // simpan dari form Edit). Job memegang salinan baris dari saat start —
    // tanpa guard, hasil parsing akan menimpa koreksi ini.
    Http::fake([
        'https://api.cohere.ai/*' => function () use ($expense) {
            Expense::withoutGlobalScopes()->whereKey($expense->getKey())->update([
                'vendor' => 'Toko Koreksi User',
                'amount' => 99000,
            ]);

            return Http::response([
                'text' => '{"vendor":"Toko AI","date":"2026-09-02","category":"Makanan & Minuman","items":[{"name":"Beras","qty":1,"price":15000,"subtotal":15000}],"total":15000,"change":0}',
            ], 200);
        },
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);

    $expense->refresh();

    // Koreksi user bertahan; hasil parsing (Toko AI / 15.000) tidak menimpanya.
    expect($expense->vendor)->toBe('Toko Koreksi User')
        ->and((float) $expense->amount)->toBe(99000.0);

    // Job tetap menyelesaikan tugasnya (item diisi, laporan sukses).
    expect($expense->items()->count())->toBe(1)
        ->and($expense->items()->first()->name)->toBe('Beras')
        ->and($result['ok'])->toBeTrue()
        ->and(aiparserCountTitlesContaining(aiparserNotificationTitlesFor($user->id), 'berhasil diproses otomatis'))->toBe(1);
});

/* -------------------------------------------------------------------------
 * (b) save gagal → rollback (item lama utuh) + notifikasi GAGAL
 * ---------------------------------------------------------------------- */

test('(b) save gagal: item lama utuh, record tidak berubah, dan notifikasi GAGAL terkirim', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Gagal Simpan',
        'note' => "Toko Gagal\nTotal Rp 20.000\n",
        'vendor' => 'Vendor Lama',
        'amount' => 11111,
    ]);

    // Item lama hasil pekerjaan sebelumnya — WAJIB tetap ada bila save gagal.
    $expense->items()->create([
        'name' => 'Item Lama',
        'qty' => 1,
        'price' => 11111,
        'subtotal' => 11111,
    ]);

    // Fallback regex (tanpa AI) → hasil parsing beda dari nilai lama sehingga
    // record memang akan di-UPDATE.
    Http::fake(['https://api.cohere.ai/*' => Http::response('servis tidak tersedia', 500)]);

    // Simulasi kegagalan simpan di level DB (padanan error constraint/disk
    // penuh di PostgreSQL): trigger SQLite menolak UPDATE pada expenses.
    DB::statement(
        "CREATE TRIGGER expenses_save_fail BEFORE UPDATE ON expenses "
        ."BEGIN SELECT RAISE(ABORT, 'simulasi gagal simpan'); END;"
    );

    $result = (new AIParserJob($expense))->reprocess($expense);

    DB::statement('DROP TRIGGER expenses_save_fail');

    $expense->refresh();

    expect($result['ok'])->toBeFalse();

    // Rollback: record & item lama tidak berubah / tidak hilang.
    expect($expense->vendor)->toBe('Vendor Lama')
        ->and((float) $expense->amount)->toBe(11111.0)
        ->and($expense->items()->count())->toBe(1)
        ->and($expense->items()->first()->name)->toBe('Item Lama');

    // Notifikasi yang dikirim adalah GAGAL simpan, bukan sukses/estimasi.
    $titles = aiparserNotificationTitlesFor($user->id);

    expect(aiparserCountTitlesContaining($titles, 'gagal disimpan otomatis — data lama tidak diubah'))->toBe(1)
        ->and(aiparserCountTitlesContaining($titles, 'berhasil diproses otomatis'))->toBe(0)
        ->and(aiparserCountTitlesContaining($titles, 'estimasi otomatis'))->toBe(0);
});



/* -------------------------------------------------------------------------
 * (c) Alur normal tetap sama seperti sebelumnya
 * ---------------------------------------------------------------------- */

test('(c) alur normal tetap: hasil parsing tersimpan, item lama diganti, notifikasi sukses', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"Toko Normal","date":"2026-09-02","category":"Makanan & Minuman","items":[{"name":"Beras","qty":1,"price":15000,"subtotal":15000}],"total":15000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Normal',
        'note' => "Toko Normal\n2026-09-02\nBeras 15.000\nTotal Rp 15.000\n",
    ]);

    // Item sisa proses sebelumnya: harus DIGANTI (tidak menumpuk) karena save
    // & penggantian item kini berada di satu transaksi yang sama.
    $expense->items()->create([
        'name' => 'Item Sisa Lama',
        'qty' => 1,
        'price' => 1000,
        'subtotal' => 1000,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);

    $expense->refresh();

    expect($result['ok'])->toBeTrue()
        ->and($expense->vendor)->toBe('Toko Normal')
        ->and((float) $expense->amount)->toBe(15000.0)
        ->and($expense->used_fallback)->toBeFalse()
        ->and((float) $expense->change)->toBe(0.0)
        ->and($expense->items()->count())->toBe(1)
        ->and($expense->items()->first()->name)->toBe('Beras');

    $titles = aiparserNotificationTitlesFor($user->id);

    expect(aiparserCountTitlesContaining($titles, 'Struk Normal berhasil diproses otomatis'))->toBe(1)
        ->and(aiparserCountTitlesContaining($titles, 'gagal disimpan otomatis'))->toBe(0)
        ->and($result['note'])->toContain('Total Rp 15.000');
});
