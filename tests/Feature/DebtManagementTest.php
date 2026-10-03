<?php

use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Debts\Pages\CreateDebt;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Debts\Pages\ViewDebt;
use App\Models\Debt;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Fitur Utang Piutang — resource BARU yang terpisah total dari Expenses:
 *  - CRUD Filament (Create / List / View),
 *  - perhitungan status & pelunasan (belum_lunas → sebagian → lunas),
 *  - isolasi data antar user (mode UMKM multi-user),
 *  - action "Tandai Lunas" & "Catat Pembayaran Sebagian".
 */

/**
 * Buat catatan utang/piutang milik $user dengan nilai default yang wajar.
 *
 * @param  array<string, mixed>  $attributes
 */
function debtFor(User $user, array $attributes = []): Debt
{
    return Debt::create(array_merge([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Beras',
        'amount' => 500000,
    ], $attributes));
}

/**
 * Ambil HTML <td> pertama yang memuat $label pada snapshot tabel.
 *
 * Dipakai untuk membuktikan badge benar-benar dirender dengan warna tertentu
 * pada kolom yang tepat (kelas warna Filament berbentuk "fi-color-*").
 */
function debtTableCell(string $html, string $label): string
{
    preg_match_all('/<td\b.*?<\/td>/s', $html, $matches);

    foreach ($matches[0] as $cell) {
        if (str_contains($cell, $label)) {
            return $cell;
        }
    }

    return '';
}

/** Ambil HTML <tr> yang memuat $needle (nama pihak unik per baris). */
function debtTableRow(string $html, string $needle): string
{
    preg_match_all('/<tr\b.*?<\/tr>/s', $html, $matches);

    foreach ($matches[0] as $row) {
        if (str_contains($row, $needle)) {
            return $row;
        }
    }

    return '';
}

/* -------------------------------------------------------------------------
 * 1. CRUD
 * ---------------------------------------------------------------------- */

test('form Create menyimpan utang baru milik user login dengan status belum lunas', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateDebt::class)
        ->fillForm([
            'type' => Debt::TYPE_UTANG,
            'counterparty_name' => 'Supplier Beras',
            'amount' => 500000,
            'due_date' => '2026-12-31',
            'notes' => 'Pinjaman modal kulakan',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $debt = Debt::query()->sole();

    expect($debt->user_id)->toBe($user->id)
        ->and($debt->type)->toBe(Debt::TYPE_UTANG)
        ->and($debt->counterparty_name)->toBe('Supplier Beras')
        ->and((float) $debt->amount)->toBe(500000.0)
        ->and((float) $debt->paid_amount)->toBe(0.0)
        ->and($debt->status)->toBe(Debt::STATUS_BELUM_LUNAS)
        ->and($debt->due_date?->toDateString())->toBe('2026-12-31')
        ->and($debt->notes)->toBe('Pinjaman modal kulakan');
});

test('form Create menyimpan piutang & menolak tipe di luar utang/piutang', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateDebt::class)
        ->fillForm([
            'type' => Debt::TYPE_PIUTANG,
            'counterparty_name' => 'Toko Kelontong',
            'amount' => 250000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Debt::query()->sole()->type)->toBe(Debt::TYPE_PIUTANG);

    Livewire::test(CreateDebt::class)
        ->fillForm([
            'type' => 'hutang',
            'counterparty_name' => 'Tidak Valid',
            'amount' => 10000,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    // Yang tersimpan tetap hanya catatan valid sebelumnya.
    expect(Debt::query()->count())->toBe(1);
});

test('halaman List & View dapat dibuka lewat HTTP dan menampilkan data catatan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, [
        'counterparty_name' => 'Supplier Beras',
        'amount' => 300000,
        'notes' => 'Kulakan beras',
    ]);

    $list = $this->get('/admin/debts');
    $list->assertOk();
    expect($list->getContent())
        ->toContain('Utang Piutang')
        ->toContain('Supplier Beras');

    $view = $this->get('/admin/debts/'.$debt->getKey());
    $view->assertOk();
    expect($view->getContent())
        ->toContain('Ringkasan Nominal')
        ->toContain('Rp 300.000');
});

test('halaman View menampilkan ringkasan nominal & status sebagian', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, [
        'amount' => 400000,
        'paid_amount' => 100000,
    ]);

    Livewire::test(ViewDebt::class, ['record' => $debt->getKey()])
        ->assertSee('Informasi Utang Piutang')
        ->assertSee('Ringkasan Nominal')
        ->assertSee('Rp 400.000')
        ->assertSee('Rp 100.000')
        ->assertSee('Rp 300.000')
        ->assertSee('Sebagian');
});
/* -------------------------------------------------------------------------
 * 2. Perhitungan status & pelunasan
 * ---------------------------------------------------------------------- */

test('status berpindah belum lunas → sebagian → lunas saat pembayaran dicatat', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 300000]);

    expect($debt->status)->toBe(Debt::STATUS_BELUM_LUNAS)
        ->and($debt->remainingAmount())->toBe(300000.0)
        ->and($debt->paidPercent())->toBe(0);

    $debt->recordPayment(100000);
    $debt->refresh();

    expect($debt->status)->toBe(Debt::STATUS_SEBAGIAN)
        ->and((float) $debt->paid_amount)->toBe(100000.0)
        ->and($debt->remainingAmount())->toBe(200000.0)
        ->and($debt->paidPercent())->toBe(33)
        ->and($debt->isActive())->toBeTrue()
        ->and($debt->isLunas())->toBeFalse();

    $debt->recordPayment(200000);
    $debt->refresh();

    expect($debt->status)->toBe(Debt::STATUS_LUNAS)
        ->and((float) $debt->paid_amount)->toBe(300000.0)
        ->and($debt->remainingAmount())->toBe(0.0)
        ->and($debt->paidPercent())->toBe(100)
        ->and($debt->isLunas())->toBeTrue();
});

test('pembayaran melebihi sisa dipotong ke nominal total & langsung lunas', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 150000]);

    $debt->recordPayment(999999);
    $debt->refresh();

    expect((float) $debt->paid_amount)->toBe(150000.0)
        ->and($debt->status)->toBe(Debt::STATUS_LUNAS)
        ->and($debt->remainingAmount())->toBe(0.0);
});

test('recordPayment membaca ulang baris terkunci — dua instance basi tidak saling menimpa (anti lost-update)', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 500000]);

    // Dua "klien" (mis. 2 tab / 2 request) yang sama-sama memuat baris saat
    // paid_amount masih 0 — snapshot instance $stale tidak pernah diperbarui.
    $stale = Debt::query()->findOrFail($debt->getKey());

    $debt->recordPayment(100000);   // klien 1 membayar lebih dulu
    $stale->recordPayment(200000);  // klien 2 (state basi) membayar sesudahnya

    $stale->refresh();

    // Tanpa lock + pembacaan ulang, hasil lama = 200.000 (update klien 1 hilang).
    expect((float) $stale->paid_amount)->toBe(300000.0)
        ->and($stale->status)->toBe(Debt::STATUS_SEBAGIAN)
        ->and($stale->remainingAmount())->toBe(200000.0);
});

test('pembayaran berurutan dari instance basi tetap ter-clamp & status akhir benar', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 250000]);

    // Instance kedua diambil SEBELUM pembayaran pertama (snapshot basi).
    $stale = Debt::query()->findOrFail($debt->getKey());

    $debt->recordPayment(200000);
    expect($debt->refresh()->status)->toBe(Debt::STATUS_SEBAGIAN)
        ->and((float) $debt->paid_amount)->toBe(200000.0);

    // Klien kedua masih mengira belum ada pembayaran sama sekali; nominal
    // dihitung dari baris terkunci (200.000 + 100.000 = 300.000) lalu di-clamp
    // hook saving ke nominal total 250.000 → status lunas.
    $stale->recordPayment(100000);

    $stale->refresh();

    expect((float) $stale->paid_amount)->toBe(250000.0)
        ->and($stale->status)->toBe(Debt::STATUS_LUNAS)
        ->and($stale->remainingAmount())->toBe(0.0)
        ->and($stale->paidPercent())->toBe(100);
});

test('menandai lunas mengisi paid_amount penuh & mengubah status', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 275000]);

    $debt->markAsPaid();
    $debt->refresh();

    expect((float) $debt->paid_amount)->toBe(275000.0)
        ->and($debt->status)->toBe(Debt::STATUS_LUNAS);
});

test('status & paid_amount tetap dinormalisasi walau diisi manual (anti data tidak konsisten)', function () {
    $user = User::factory()->create();

    // Nilai "lunas" dengan paid_amount 0 dipaksa lewat mass assignment —
    // hook saving model harus mengembalikannya ke belum_lunas.
    $debt = debtFor($user, ['amount' => 100000, 'status' => Debt::STATUS_LUNAS]);

    expect($debt->status)->toBe(Debt::STATUS_BELUM_LUNAS)
        ->and((float) $debt->paid_amount)->toBe(0.0);

    // paid_amount melebihi amount di-clamp, status dihitung ulang.
    $debt->update(['paid_amount' => 500000]);
    expect($debt->fresh()->status)->toBe(Debt::STATUS_LUNAS)
        ->and((float) $debt->fresh()->paid_amount)->toBe(100000.0);

    // Mengubah total setelah lunas menurunkan kembali statusnya.
    $debt->update(['amount' => 400000]);
    expect($debt->fresh()->status)->toBe(Debt::STATUS_SEBAGIAN)
        ->and((float) $debt->fresh()->paid_amount)->toBe(100000.0);
});

test('nominal pembayaran nol atau negatif ditolak', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 100000]);

    expect(fn () => $debt->recordPayment(0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $debt->recordPayment(-5000))
        ->toThrow(InvalidArgumentException::class);

    expect((float) $debt->fresh()->paid_amount)->toBe(0.0)
        ->and($debt->fresh()->status)->toBe(Debt::STATUS_BELUM_LUNAS);
});

test('jumlah total berubah: paid_amount di-clamp ke total baru', function () {
    $debt = debtFor(User::factory()->create(), ['amount' => 200000]);

    $debt->update(['paid_amount' => 200000, 'amount' => 80000]);
    $debt->refresh();

    expect((float) $debt->paid_amount)->toBe(80000.0)
        ->and($debt->status)->toBe(Debt::STATUS_LUNAS);
});

/* -------------------------------------------------------------------------
 * 3. Label & warna badge
 * ---------------------------------------------------------------------- */

test('label & warna tipe dan status mengikuti kontrak tampilan', function () {
    $utang = debtFor(User::factory()->create(), ['type' => Debt::TYPE_UTANG]);
    $piutang = debtFor(User::factory()->create(), ['type' => Debt::TYPE_PIUTANG]);

    expect($utang->typeLabel())->toBe('Utang')
        ->and($utang->typeColor())->toBe('danger')
        ->and($piutang->typeLabel())->toBe('Piutang')
        ->and($piutang->typeColor())->toBe('success');

    expect($utang->statusLabel())->toBe('Belum Lunas')
        ->and($utang->statusColor())->toBe('warning');

    $sebagian = debtFor(User::factory()->create(), ['amount' => 100000, 'paid_amount' => 25000]);
    $lunas = debtFor(User::factory()->create(), ['amount' => 100000, 'paid_amount' => 100000]);

    expect($sebagian->statusColor())->toBe('info')
        ->and($lunas->statusColor())->toBe('success')
        ->and($lunas->statusLabel())->toBe('Lunas');
});

test('badge tipe & status dirender dengan warna berbeda di tabel daftar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    debtFor($user, [
        'counterparty_name' => 'Supplier Beras',
        'type' => Debt::TYPE_UTANG,
        'amount' => 300000,
    ]);
    debtFor($user, [
        'counterparty_name' => 'Toko Kelontong',
        'type' => Debt::TYPE_PIUTANG,
        'amount' => 200000,
        'paid_amount' => 50000,
    ]);
    debtFor($user, [
        'counterparty_name' => 'Karyawan Budi',
        'type' => Debt::TYPE_PIUTANG,
        'amount' => 90000,
        'paid_amount' => 90000,
    ]);

    $html = Livewire::test(ListDebts::class)->html();

    // Kolom Tipe: Utang = merah (danger), Piutang = hijau (success).
    expect(debtTableCell($html, 'Utang'))->toContain('fi-color-danger')
        ->and(debtTableCell($html, 'Piutang'))->toContain('fi-color-success');

    // Kolom Status: belum lunas = warning, sebagian = info, lunas = success.
    expect(debtTableCell($html, 'Belum Lunas'))->toContain('fi-color-warning')
        ->and(debtTableCell($html, 'Sebagian'))->toContain('fi-color-info')
        ->and(debtTableRow($html, 'Karyawan Budi'))->toContain('fi-color-success');
});

/* -------------------------------------------------------------------------
 * 4. Action "Tandai Lunas" & "Catat Pembayaran Sebagian"
 * ---------------------------------------------------------------------- */

test('action Tandai Lunas di baris daftar mengubah status dan paid_amount', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, ['amount' => 250000]);

    Livewire::test(ListDebts::class)
        ->callAction(TestAction::make('markAsPaid')->table($debt))
        ->assertHasNoActionErrors()
        ->assertNotified();

    $debt->refresh();

    expect($debt->status)->toBe(Debt::STATUS_LUNAS)
        ->and((float) $debt->paid_amount)->toBe(250000.0)
        ->and($debt->remainingAmount())->toBe(0.0);
});

test('action Tandai Lunas di header halaman View memperbarui infolist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, ['amount' => 120000]);

    Livewire::test(ViewDebt::class, ['record' => $debt->getKey()])
        ->assertActionVisible('markAsPaid')
        ->callAction('markAsPaid')
        ->assertNotified()
        // Infolist menampilkan sisa terbaru (Rp 0) setelah pelunasan.
        ->assertSee('Rp 0');

    expect($debt->refresh()->status)->toBe(Debt::STATUS_LUNAS);
});

test('action Tandai Lunas disembunyikan untuk catatan yang sudah lunas', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $lunas = debtFor($user, ['amount' => 100000, 'paid_amount' => 100000]);
    $aktif = debtFor($user, ['amount' => 100000]);

    Livewire::test(ListDebts::class)
        ->assertActionHidden(TestAction::make('markAsPaid')->table($lunas))
        ->assertActionHidden(TestAction::make('recordPayment')->table($lunas))
        ->assertActionVisible(TestAction::make('markAsPaid')->table($aktif))
        ->assertActionVisible(TestAction::make('recordPayment')->table($aktif));
});

test('action Catat Pembayaran Sebagian menyimpan nominal sebagian lalu melunasi sisanya', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, ['amount' => 500000]);

    Livewire::test(ListDebts::class)
        ->callAction(
            TestAction::make('recordPayment')->table($debt),
            ['payment_amount' => 150000],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    $debt->refresh();

    expect($debt->status)->toBe(Debt::STATUS_SEBAGIAN)
        ->and((float) $debt->paid_amount)->toBe(150000.0)
        ->and($debt->remainingAmount())->toBe(350000.0);

    // Pembayaran kedua menutup seluruh sisa → status lunas.
    Livewire::test(ListDebts::class)
        ->callAction(
            TestAction::make('recordPayment')->table($debt),
            ['payment_amount' => 350000],
        )
        ->assertHasNoActionErrors();

    $debt->refresh();

    expect($debt->status)->toBe(Debt::STATUS_LUNAS)
        ->and((float) $debt->paid_amount)->toBe(500000.0);
});

test('action Catat Pembayaran menolak nominal melebihi sisa tagihan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debt = debtFor($user, ['amount' => 200000]);

    Livewire::test(ListDebts::class)
        ->callAction(
            TestAction::make('recordPayment')->table($debt),
            ['payment_amount' => 250000],
        )
        ->assertHasActionErrors(['payment_amount']);

    expect((float) $debt->fresh()->paid_amount)->toBe(0.0)
        ->and($debt->fresh()->status)->toBe(Debt::STATUS_BELUM_LUNAS);
});

/* -------------------------------------------------------------------------
 * 5. Isolasi data per user (mode UMKM multi-user)
 * ---------------------------------------------------------------------- */

test('user hanya melihat catatan utang piutang miliknya sendiri', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $debtA = Debt::create([
        'user_id' => $userA->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Beras',
        'amount' => 300000,
    ]);
    $debtB = Debt::create([
        'user_id' => $userB->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan Warung',
        'amount' => 900000,
    ]);

    $this->actingAs($userA);
    expect(Debt::query()->pluck('id')->all())->toBe([$debtA->id]);
    expect(DebtResource::resolveRecordRouteBinding($debtA->id))->not->toBeNull();
    Livewire::test(ListDebts::class)
        ->assertSee('Supplier Beras')
        ->assertDontSee('Pelanggan Warung');

    $this->actingAs($userB);
    expect(Debt::query()->pluck('id')->all())->toBe([$debtB->id]);
    expect(DebtResource::resolveRecordRouteBinding($debtA->id))->toBeNull();
    Livewire::test(ListDebts::class)
        ->assertSee('Pelanggan Warung')
        ->assertDontSee('Supplier Beras');
});

test('catatan milik user lain tidak bisa dibuka lewat halaman View', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $debt = debtFor($owner);

    $this->actingAs($intruder);

    expect(fn () => Livewire::test(ViewDebt::class, ['record' => $debt->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

test('catatan baru selalu memakai user yang sedang login (anti-spoofing)', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(CreateDebt::class)
        ->fillForm([
            'type' => Debt::TYPE_UTANG,
            'counterparty_name' => 'Pihak Lain',
            'amount' => 50000,
        ])
        // user_id palsu dari sisi klien tidak akan dipakai.
        ->fillForm(['user_id' => $other->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Debt::query()->sole()->user_id)->toBe($user->id);
});

/* -------------------------------------------------------------------------
 * 6. Navigasi, filter, & judul record
 * ---------------------------------------------------------------------- */

test('menu sidebar memakai grup Keuangan, label Utang Piutang, & badge catatan aktif', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    debtFor($user, ['type' => Debt::TYPE_UTANG]);
    debtFor($user, ['type' => Debt::TYPE_PIUTANG]);
    // Sudah lunas → tidak dihitung di badge.
    debtFor($user, ['amount' => 100000, 'paid_amount' => 100000]);

    expect(DebtResource::getNavigationGroup())->toBe('Keuangan')
        ->and(DebtResource::getNavigationLabel())->toBe('Utang Piutang')
        ->and(DebtResource::getModelLabel())->toBe('Utang Piutang')
        ->and(DebtResource::getNavigationBadge())->toBe('2');
});

test('judul record menggabungkan tipe & nama pihak', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(DebtResource::hasRecordTitle())->toBeTrue();

    $utang = debtFor($user, ['counterparty_name' => 'Supplier Beras']);
    $piutang = debtFor($user, ['type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'Toko Kelontong']);

    expect(DebtResource::getRecordTitle($utang))->toBe('Utang — Supplier Beras')
        ->and(DebtResource::getRecordTitle($piutang))->toBe('Piutang — Toko Kelontong');
});

test('filter tabel tipe & status menyaring baris yang tampil', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    debtFor($user, ['counterparty_name' => 'Supplier Beras', 'type' => Debt::TYPE_UTANG]);
    debtFor($user, [
        'counterparty_name' => 'Toko Kelontong',
        'type' => Debt::TYPE_PIUTANG,
        'amount' => 80000,
        'paid_amount' => 80000,
    ]);

    Livewire::test(ListDebts::class)
        ->filterTable('type', Debt::TYPE_PIUTANG)
        ->assertSee('Toko Kelontong')
        ->assertDontSee('Supplier Beras');

    Livewire::test(ListDebts::class)
        ->filterTable('status', Debt::STATUS_LUNAS)
        ->assertSee('Toko Kelontong')
        ->assertDontSee('Supplier Beras');
});
