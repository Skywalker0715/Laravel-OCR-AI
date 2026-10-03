<?php

use App\Filament\Widgets\PendingParsingJobsAlert;
use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Payload job queue persis seperti yang dihasilkan DatabaseQueue: satu JSON
 * berisi data.command = job ter-serialize (SerializesModels → ModelIdentifier
 * berisi class + id expense).
 */
function parsingPayloadFor(Expense $expense): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => AIParserJob::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 150,
        'data' => [
            'commandName' => AIParserJob::class,
            'command' => serialize(new AIParserJob($expense)),
        ],
    ]);
}

/**
 * Sisipkan job antrian yang sudah "macet" (created_at & available_at lebih
 * tua dari STALE_MINUTES = 5 menit, belum pernah di-reserve).
 */
function insertStaleParsingJobFor(Expense $expense): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => parsingPayloadFor($expense),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(10)->getTimestamp(),
        'created_at' => now()->subMinutes(10)->getTimestamp(),
    ]);
}

/** Sisipkan job gagal total ke tabel failed_jobs. */
function insertFailedParsingJobFor(Expense $expense): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => parsingPayloadFor($expense),
        'exception' => 'RuntimeException: Cohere API tidak tersedia',
        'failed_at' => now(),
    ]);
}

/* -------------------------------------------------------------------------
 * Privasi: angka antrian/kegagalan tidak pernah bocor antar user
 * ---------------------------------------------------------------------- */

test('user A tidak melihat antrian & job gagal milik user B', function () {
    // canView() skip bila queue sync — pakai driver database supaya widget
    // benar-benar mempertimbangkan untuk tampil.
    config(['queue.default' => 'database']);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // User B: 2 job macet + 1 job gagal.
    insertStaleParsingJobFor(Expense::create(['user_id' => $userB->id, 'title' => 'Struk B1']));
    insertStaleParsingJobFor(Expense::create(['user_id' => $userB->id, 'title' => 'Struk B2']));
    insertFailedParsingJobFor(Expense::create(['user_id' => $userB->id, 'title' => 'Struk B gagal']));

    // Sebagai B: data miliknya sendiri terlihat (bukti job benar-benar ada
    // dan terbaca — jadi kegagalan user A nanti bukan karena query kosong).
    $this->actingAs($userB);

    expect(PendingParsingJobsAlert::canView())->toBeTrue();

    Livewire::test(PendingParsingJobsAlert::class)
        ->assertSee('2 data struk belum diproses')
        ->assertSee('1 job gagal');

    // Sebagai A: tidak melihat APA PUN dari B (angka antrian & kegagalan
    // milik B tidak muncul sama sekali). Heading "Antrian parsing terhambat"
    // sengaja TIDAK diassertDontSee: Livewire::test merender widget langsung
    // sehingga heading generik selalu ada — yang menentukan tampil/tidak di
    // dashboard adalah canView() (di atas: false).
    $this->actingAs($userA);

    expect(PendingParsingJobsAlert::canView())->toBeFalse();

    Livewire::test(PendingParsingJobsAlert::class)
        ->assertDontSee('data struk belum diproses')
        ->assertDontSee('job gagal')
        ->assertDontSee('2 data')
        ->assertDontSee('3 job');
});

test('angka yang tampil hanya milik user login dan tetap tampil untuk data sendiri', function () {
    config(['queue.default' => 'database']);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // User B: 3 antrian + 1 gagal — tidak boleh memengaruhi angka user A.
    foreach (range(1, 3) as $i) {
        insertStaleParsingJobFor(Expense::create(['user_id' => $userB->id, 'title' => "Struk B{$i}"]));
    }
    insertFailedParsingJobFor(Expense::create(['user_id' => $userB->id, 'title' => 'Struk B gagal']));

    // User A: tepat 1 antrian miliknya sendiri (perilaku single user dipertahankan).
    insertStaleParsingJobFor(Expense::create(['user_id' => $userA->id, 'title' => 'Struk A']));

    $this->actingAs($userA);

    expect(PendingParsingJobsAlert::canView())->toBeTrue();

    Livewire::test(PendingParsingJobsAlert::class)
        ->assertSee('1 data struk belum diproses')
        ->assertDontSee('4 data struk')
        ->assertDontSee('job gagal');
});

test('queue sync tetap menyembunyikan widget (perilaku lama dipertahankan)', function () {
    config(['queue.default' => 'sync']);

    $user = User::factory()->create();
    insertStaleParsingJobFor(Expense::create(['user_id' => $user->id, 'title' => 'Struk']));

    $this->actingAs($user);

    expect(PendingParsingJobsAlert::canView())->toBeFalse();
});
