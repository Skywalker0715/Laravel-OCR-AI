<?php

use App\Exports\DebtsExport;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

test('halaman ListDebts menampilkan action Export PDF dan Export Excel di header', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListDebts::class)
        ->assertActionVisible('exportPdf')
        ->assertActionVisible('exportExcel');
});

test('export PDF utang piutang menghasilkan dokumen PDF yang valid', function () {
    $user = User::factory()->create(['name' => 'Pemilik Toko']);
    $this->actingAs($user);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Tepung',
        'amount' => 450000,
        'due_date' => '2026-10-15',
    ]);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan Kue',
        'amount' => 200000,
        'paid_amount' => 50000,
        'due_date' => '2026-10-20',
    ]);

    $livewire = Livewire::test(ListDebts::class);
    $instance = $livewire->instance();

    $pdf = $instance->buildPdfDocument($instance->getExportQuery());
    $output = $pdf->output();

    expect(str_starts_with($output, '%PDF'))->toBeTrue();
});

test('export PDF utang piutang mengembalikan response unduhan yang benar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Beras',
        'amount' => 100000,
    ]);

    $livewire = Livewire::test(ListDebts::class);
    $instance = $livewire->instance();

    $response = $instance->exportPdf();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('laporan-utang-piutang-')
        ->and($response->headers->get('content-disposition'))->toContain('.pdf');
});
test('export Excel utang piutang menghasilkan file xlsx yang valid dengan summary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Minyak',
        'amount' => 300000,
        'paid_amount' => 100000,
    ]);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Warung Sebelah',
        'amount' => 150000,
    ]);

    $livewire = Livewire::test(ListDebts::class);
    $instance = $livewire->instance();

    $content = (string) Excel::raw(new DebtsExport($instance->getExportQuery()), ExcelWriter::XLSX);

    expect(str_starts_with($content, 'PK'))->toBeTrue();

    $response = $instance->exportExcel();
    expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))->toContain('laporan-utang-piutang-')
        ->and($response->headers->get('content-disposition'))->toContain('.xlsx');
});
test('export utang piutang menghormati filter tabel yang aktif', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $utang1 = Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier A',
        'amount' => 100000,
        'due_date' => '2026-05-10',
    ]);

    $utang2 = Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier B',
        'amount' => 200000,
        'paid_amount' => 200000,
        'due_date' => '2026-06-15',
    ]);

    $piutang1 = Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan C',
        'amount' => 300000,
        'due_date' => '2026-07-20',
    ]);

    // 1. Filter Tipe = piutang
    $test = Livewire::test(ListDebts::class)
        ->filterTable('type', Debt::TYPE_PIUTANG);

    $instance = $test->instance();
    $exportedIds = $instance->getExportQuery()->pluck('id')->all();
    expect($exportedIds)->toBe([$piutang1->id]);

    // 2. Filter Status = lunas
    $test = Livewire::test(ListDebts::class)
        ->filterTable('status', Debt::STATUS_LUNAS);

    $instance = $test->instance();
    $exportedIds = $instance->getExportQuery()->pluck('id')->all();
    expect($exportedIds)->toBe([$utang2->id]);

    // 3. Filter Rentang Jatuh Tempo (due_date)
    $test = Livewire::test(ListDebts::class)
        ->filterTable('due_date', [
            'due_from' => '2026-05-01',
            'due_until' => '2026-05-31',
        ]);

    $instance = $test->instance();
    $exportedIds = $instance->getExportQuery()->pluck('id')->all();
    expect($exportedIds)->toBe([$utang1->id]);
});

test('export utang piutang terisolasi per user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $debtA = Debt::create([
        'user_id' => $userA->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier User A',
        'amount' => 500000,
    ]);

    $debtB = Debt::create([
        'user_id' => $userB->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier User B',
        'amount' => 900000,
    ]);

    $this->actingAs($userA);

    $test = Livewire::test(ListDebts::class);
    $instance = $test->instance();

    $exportedIds = $instance->getExportQuery()->pluck('id')->all();
    expect($exportedIds)->toContain($debtA->id)
        ->and($exportedIds)->not->toContain($debtB->id);
});


