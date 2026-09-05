<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Regression test BERBASIS TEKS OCR MENTAH ASLI (verbatim) — diambil
 * LANGSUNG dari kolom `note` expense yang benar-benar ada di database,
 * bukan teks buatan/disusun ulang:
 *  - "Struk Indomaret"  (id=15, vendor CV. ANUGERAH / Indomaret)
 *  - "Struk Toko Abang" (id=14, GROSIR SEMBAKO DAN BERAS)
 *
 * Karakter noise OCR (kurung terputus "(9,700", "«", "@", "~", em-dash "—",
 * blank line berlebih, "90, 700", dst.) sengaja dipertahankan apa adanya agar
 * test mencerminkan kondisi parsing nyata. Parser mem-trim tiap baris persis
 * seperti produksi (array_map('trim')), sehingga indentasi heredoc di bawah
 * tidak mengubah hasil.
 */
test('BUG #1 — OCR asli Indomaret: berat "24G" yang terbaca "246" tetap bagian nama produk (qty=1), token menempel "COKLAT200"/"oRS140" juga bagian nama, baris kurung-terputus "(9,700" tidak dibuang', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Indomaret (OCR asli verbatim)',
        'note' => <<<'TXT'
        CV, ANUGERAH
        JL.RAYA WALIKUKUN WO.9 ey
        RT: 82RW:03 WALIKUKUN, Wi (Jndomancl)
        WIDODAREN NGAWI JAWA TIMUR
        NPWP: @b25862640646000
        GORANG GARENG MAGETAN
        JL. BHAYANGKARA NO.6@ KEL REJOSARI
        KEC KAWEDANAN, KAB MAGETAN, 63382
        10.05.25-12:22/3.0.26/FLOS~35372/HASAN/02
        IDM KTG PLSTK 1M BSR 1-300 308
        SFTX CLN MENST M-L28. 2 23308 46,600
        Vo PT STX : (13,600)
        POCART SWEAT Se@ML 1 7988 7,900
        You C1886 DRK oRS140 7908 7,900
        MLKITA CNOY BTES 246 5200 5,200
        ULTRA SLIM COKLAT200 6600 6,600
        ROMA WFR CHO BLS97.6 9700 (9,700
        VETACIMIN STRIP 2'S 2300 4,600
        PISANG CAVENDISH WHL 658 24.=«15,500
        TOTAL BELANJA 90, 700
        TUNAE : 100,000
        KEMBALE : 9,300,
        ANDA HEMAT : 13,600
        PPN DPP= 73,333 PPN= 8,800
        PPN DIBEBASKAN : DPP= 14,208
        PPN= 1,705
        HARGA JUAL : 95,500
        ID POINKU : xxxxxxxxB9SS/NUXX
        CEK POIN/STAMP/LUCKY DI APPS POINKU
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();
    expect($expense->vendor)->toBe('CV. ANUGERAH');
    expect($expense->date_shopping->toDateString())->toBe('2025-05-10');
    expect((float) $expense->amount)->toBe(90700.0);
    expect((float) $expense->change)->toBe(9300.0);

    $names = $expense->items()->pluck('name');
    expect($expense->items()->count())->toBe(9);

    // FIX #1: token berat "24G" yang terbaca OCR "246" TIDAK boleh jadi kolom
    // qty. Nama produk tetap utuh "... BTES 246" dan qty = 1 (bukan 246).
    $mlkita = $expense->items()->where('name', 'like', 'MLKITA%')->first();
    expect($mlkita)->not->toBeNull();
    expect($mlkita->name)->toBe('MLKITA CNOY BTES 246');
    expect((float) $mlkita->qty)->toBe(1.0);
    expect((float) $mlkita->price)->toBe(5200.0);
    expect((float) $mlkita->subtotal)->toBe(5200.0);

    // Token ukuran yang MENEMPEL pada nama (tanpa spasi) ikut dipertahankan.
    expect($names)->toContain('ULTRA SLIM COKLAT200');
    expect($names)->toContain('You C1886 DRK oRS140');

    $ultra = $expense->items()->where('name', 'like', 'ULTRA%')->first();
    expect((float) $ultra->qty)->toBe(1.0);
    expect((float) $ultra->price)->toBe(6600.0);
    expect((float) $ultra->subtotal)->toBe(6600.0);

    // Baris dengan kurung yang TIDAK tertutup di depan subtotal positif
    // (artefak OCR "ROMA ... 9700 (9,700") TIDAK boleh ikut dibuang sebagai
    // baris retur/negatif — item tetap tertangkap dengan subtotal 9.700.
    $roma = $expense->items()->where('name', 'like', 'ROMA%')->first();
    expect($roma)->not->toBeNull();
    expect($roma->name)->toBe('ROMA WFR CHO BLS97.6');
    expect((float) $roma->qty)->toBe(1.0);
    expect((float) $roma->price)->toBe(9700.0);
    expect((float) $roma->subtotal)->toBe(9700.0);

    // Baris retur "Vo PT STX : (13,600)" (kurung TERTUTUP) tetap di-skip.
    expect($names)->not->toContain('Vo PT STX');
});
test('BUG #2 — OCR asli Toko Abang: tanggal "10.01.2023-10:11:07" yang MENEMPEL pada baris yang sama dengan "No. Struk : 211" terbaca 2023-01-10', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Toko Abang (OCR asli verbatim)',
        'note' => <<<'TXT'
        GROSIR SEMBAKO DAN BERAS

        Jl. Rorojonggrang Raya B1 No.13 Kel. Melong — Cimahi

        No. Struk : 211 10.01.2023-10:11:07

        Beras

        4.000 Kg X 12.500 Rp. 50.000.000

        Minyak Goreng

        1600 Kg x 27.500 Rp. 44.000.000

        Gula Pasir

        1600 Kg x 15.000 Rp. 24,000,000

        The Celup Isi 25.

        800 Box X 7.500

        Mie Instan

        8.000 Pcs X 3.000 Rp. 24.000.000

        Susu Kaleng

        1600 Klg X 14.000 Rp. 22.400.000

        Sarden

        1600 Klg X 14.000 Rp. 22.400.000

        Kardus Packing

        800 Pcs X 9.000 Rp. 7.800.000
        Subtotal Rp. 200.000.000

        Bayar Rp. 200,.000.000
        Kembali Rp. o
        TERIMA KASIH
        ATAS KUNJUNGAN ANDA
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();
    expect($expense->vendor)->toBe('GROSIR SEMBAKO DAN BERAS');

    // FIX #2: tanggal DD.MM.YYYY-HH:MM:SS yang menempel di baris lain.
    expect($expense->date_shopping->toDateString())->toBe('2023-01-10');

    $names = $expense->items()->pluck('name');
    expect($expense->items()->count())->toBe(8);
    expect($names)->not->toContain('000 Kg X');

    $beras = $expense->items()->where('name', 'Beras')->first();
    expect((float) $beras->qty)->toBe(4.0);
    expect((float) $beras->price)->toBe(12500.0);
    expect((float) $beras->subtotal)->toBe(50000.0);

    $gula = $expense->items()->where('name', 'Gula Pasir')->first();
    expect((float) $gula->qty)->toBe(1.6);
    expect((float) $gula->subtotal)->toBe(24000.0);

    // Total = SUM subtotal item (baris Subtotal/Bayar di OCR mentah korup).
    expect((float) $expense->amount)->toBe(200000.0);
    expect((float) $expense->change)->toBe(0.0);
});