<?php

use App\Exports\DebtsExport;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Maatwebsite\Excel\Concerns\FromQuery;
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



/* -------------------------------------------------------------------------
 * Isi & total export (TASK 4B): DebtsExport kini memakai FromQuery + SUM di
 * SQL. Test ini mengunci bahwa ISI FILE dan TOTAL-nya sama persis dengan
 * perhitungan versi lama (sum per baris di PHP), jadi optimasi tidak mengubah
 * angka yang dilihat pengguna.
 * ---------------------------------------------------------------------- */

test('export utang piutang berisi baris & total yang identik dengan perhitungan PHP', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Campuran status & tipe supaya CASE di SQL benar-benar diuji:
    // ada yang lunas (harus TIDAK ikut total), ada yang sebagian, dan ada
    // piutang.
    $debts = [
        Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier A', 'amount' => 500000, 'paid_amount' => 200000]),
        Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier B', 'amount' => 300000, 'paid_amount' => 300000]),
        Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier C', 'amount' => 150000, 'paid_amount' => 0]),
        Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'Pelanggan X', 'amount' => 800000, 'paid_amount' => 250000]),
        Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'Pelanggan Y', 'amount' => 400000, 'paid_amount' => 400000]),
    ];

    $instance = Livewire::test(ListDebts::class)->instance();

    // Ekspektasi dihitung dengan cara versi LAMA: ambil semua model, lalu
    // jumlahkan remainingAmount() di PHP per tipe.
    $all = $instance->getExportQuery()->get();
    $active = $all->where('status', '!=', Debt::STATUS_LUNAS);

    $expectedUtang = (float) $active->where('type', Debt::TYPE_UTANG)->sum(fn (Debt $d): float => $d->remainingAmount());
    $expectedPiutang = (float) $active->where('type', Debt::TYPE_PIUTANG)->sum(fn (Debt $d): float => $d->remainingAmount());

    $export = new DebtsExport($instance->getExportQuery());

    // --- Baris data: semua 5 catatan ikut, nomornya 1..5, urutan mengikuti query.
    $rows = collect($debts)->map(fn (Debt $d): array => $export->map($d));

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck(0)->all())->toBe([1, 2, 3, 4, 5])
        ->and($rows->pluck(2)->all())->toBe($all->pluck('counterparty_name')->all());

    // Kolom Sisa = remainingAmount(), kolom Jumlah/Dibayar tetap float.
    expect($rows[0][3])->toBe(500000.0)
        ->and($rows[0][4])->toBe(200000.0)
        ->and($rows[0][5])->toBe(300000.0)
        ->and($rows[1][5])->toBe(0.0);

    // --- Total hasil SUM di SQL harus sama dengan penjumlahan PHP.
    $totals = (new ReflectionMethod($export, 'activeTotalsSummary'))->invoke($export);

    expect((float) $totals->total_utang)->toBe($expectedUtang)
        ->and((float) $totals->total_piutang)->toBe($expectedPiutang)
        ->and((int) $totals->total_rows)->toBe(5);

    // Nilai konkretnya supaya regresi langsung terbaca, bukan hanya "sama dengan
    // perhitungan lain yang sama salah".
    expect($expectedUtang)->toBe(450000.0)
        ->and($expectedPiutang)->toBe(550000.0);
});

test('export utang piutang tidak meng-hidrate semua model sekaligus (pakai chunk)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'A', 'amount' => 1000]);
    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'B', 'amount' => 2000]);

    $export = new DebtsExport(Debt::query());

    // FromQuery + chunkSize: baris dibaca per chunk, bukan collection utuh.
    expect($export)->toBeInstanceOf(FromQuery::class)
        ->and($export->chunkSize())->toBeGreaterThan(0);

    // Numbering baris dimulai ulang setiap kali query() dipanggil (dipakai
    // writer tepat sekali per ekspor), jadi tidak pernahskip/duplikat.
    $first = $export->map(Debt::query()->orderBy('id')->first());
    expect($first[0])->toBe(1);
});

/* -------------------------------------------------------------------------
 * Batas baris detail di PDF (TASK 4B butir 5): kartu ringkasan tetap dihitung
 * dari SQL atas SELURUH data hasil filter, bukan dari baris yang dicetak.
 * ---------------------------------------------------------------------- */

test('PDF Utang Piutang: ringkasan tetap dari seluruh data meski baris dipotong', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier A', 'amount' => 500000, 'paid_amount' => 200000]);
    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'Pelanggan X', 'amount' => 800000, 'paid_amount' => 250000]);

    $instance = Livewire::test(ListDebts::class)->instance();

    $summary = (new ReflectionMethod($instance, 'activeTotals'))
        ->invoke($instance, $instance->getExportQuery());

    // Sisa utang = 300.000, sisa piutang = 550.000.
    expect($summary['utang'])->toBe(300000.0)
        ->and($summary['piutang'])->toBe(550000.0)
        ->and($summary['count'])->toBe(2);
});

test('PDF Utang Piutang: menampilkan catatan truncation hanya saat baris dipotong', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $debts = Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier A', 'amount' => 500000]);

    $base = [
        'userName' => $user->name,
        'generatedAt' => '01/01/2026 00:00',
        'totalUtangBelumLunas' => 500000.0,
        'totalPiutangBelumLunas' => 0.0,
        'detailLimit' => 1000,
    ];

    $withoutNote = view('exports.utang-piutang-pdf', $base + [
        'debts' => collect([$debts]),
        'totalRowCount' => 1,
        'isTruncated' => false,
    ])->render();

    $withNote = view('exports.utang-piutang-pdf', $base + [
        'debts' => collect([$debts]),
        'totalRowCount' => 1500,
        'isTruncated' => true,
    ])->render();

    expect($withoutNote)->not->toContain('<strong>Catatan:</strong>')
        ->and($withNote)->toContain('<strong>Catatan:</strong>')
        ->and($withNote)->toContain('1.500');
});

test('isi file xlsx utang piutang dibaca kembali sama persis dengan data & total', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier A', 'amount' => 500000, 'paid_amount' => 200000, 'due_date' => '2026-10-15']);
    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_PIUTANG, 'counterparty_name' => 'Pelanggan X', 'amount' => 800000, 'paid_amount' => 250000]);
    Debt::create(['user_id' => $user->id, 'type' => Debt::TYPE_UTANG, 'counterparty_name' => 'Supplier B', 'amount' => 300000, 'paid_amount' => 300000]);

    $instance = Livewire::test(ListDebts::class)->instance();

    $content = (string) Excel::raw(new DebtsExport($instance->getExportQuery()), ExcelWriter::XLSX);

    // Tulis ke file sementara lalu baca balik dengan PhpSpreadsheet, supaya isi
    // file benar-benar diverifikasi (bukan cuma signature 'PK').
    $path = tempnam(sys_get_temp_dir(), 'utang-piutang-');
    file_put_contents($path, $content);

    try {
        $sheet = IOFactory::load($path)->getActiveSheet();
    } finally {
        @unlink($path);
    }

    // Baris 1 = judul kolom, baris 2..4 = 3 catatan, baris 5 = pemisah kosong,
    // baris 6 & 7 = blok ringkasan.
    // A=No, B=Tipe, C=Nama Pihak, D=Jumlah, E=Dibayar, F=Sisa
    expect($sheet->getCell('A2')->getValue())->toBe(1)
        ->and($sheet->getCell('B2')->getValue())->toBe('Utang')
        ->and($sheet->getCell('C2')->getValue())->toBe('Supplier A')
        ->and($sheet->getCell('D2')->getValue())->toBe(500000.0)
        ->and($sheet->getCell('E2')->getValue())->toBe(200000.0)
        ->and($sheet->getCell('F2')->getValue())->toBe(300000.0)
        ->and($sheet->getCell('C4')->getValue())->toBe('Supplier B')
        ->and($sheet->getCell('A4')->getValue())->toBe(3);

    // Blok ringkasan: Sqlutang 300.000 (A lunas tidak ikut), piutang 550.000.
    expect($sheet->getCell('B6')->getValue())->toBe('Total Utang Belum Lunas')
        ->and($sheet->getCell('F6')->getValue())->toBe(300000.0)
        ->and($sheet->getCell('B7')->getValue())->toBe('Total Piutang Belum Lunas')
        ->and($sheet->getCell('F7')->getValue())->toBe(550000.0);

    // Format angka tetap '#,##0' pada kolom Sisa, termasuk baris ringkasan
    // (versi lama men imposition format ini lewat columnFormats()).
    expect($sheet->getStyle('F6')->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($sheet->getStyle('F7')->getNumberFormat()->getFormatCode())->toBe('#,##0');
});
