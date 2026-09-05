<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Verifikasi bahwa fallback regex (dipakai saat Cohere gagal) tetap mampu
 * mem-parse struk dengan format kompleks: beberapa kolom item, diskon, PPN,
 * dan kembalian — setidaknya daftar item tidak boleh kosong total.
 */
test('fallback regex mem-parse struk kompleks: vendor benar, 9 item terisi, total & kembalian akurat', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Indomaret Kompleks',
        'note' => <<<'TXT'
        CV. ANUGERAH
        JL. GATOT SUBROTO
        NPWP: 73.892.378.8.533.000
        Tanggal: 2024-11-19 15:04

        TUNA KALENG 150GR   2    8.500   17.000
        KOPI ABC 10S        3    3.600   10.800
        MINYAK GORENG 1L    2   18.500   37.000
        BERAS PREMIUM 5KG   1   78.000   78.000
        TELUR AYAM 1/2KG    1   15.500   15.500
        SUSU UHT 1L         4    9.250   37.000
        GULA PASIR 1KG      2   14.800   29.600
        ROTI TAWAR          1   12.500   12.500
        SABUN MANDI 3PCS    5    7.450   37.250

        Disc. 10%          -2.500
        PPN 11%             2.731
        Total Belanja     302.595
        Kembalian            4.405
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);

    $expense->refresh();

    expect($result['ok'])->toBeTrue();
    expect($expense->vendor)->toBe('CV. ANUGERAH');
    expect((float) $expense->amount)->toBe(302595.0);
    expect((float) $expense->change)->toBe(4405.0);
    expect($expense->date_shopping->toDateString())->toBe('2024-11-19');
    expect($expense->items()->count())->toBe(9);
});

test('fallback regex membersihkan fragment vendor OCR dan tidak menjadikan baris retur sebagai item', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk retur & diskon',
        'note' => <<<'TXT'
        ev, ANUGERAK
        JL. GATOT SUBROTO RT.01 RW.03
        NPUP : 73.892.378.8.533.000
        Tanggal: 2024-11-19 15:04

        SUSU ULTRA 1L       2   13.500   27.000
        RETUR SUSU         -1   13.500  -13.500
        DISKON MEMBER     -2.500
        PPN 11%              2.431
        Total Belanja      23.197
        Tunai              30.000
        Kembalian           6.803
        TXT,
    ]);

    (new AIParserJob($expense))->handle();

    $expense->refresh();

    // Fragmen "ev," kini direkonstruksi menjadi "CV." (bentuk baku badan usaha);
    // typo OCR "ANUGERAK" vs "ANUGERAH" tidak bisa diperbaiki oleh regex (tugas AI).
    expect($expense->vendor)->toBe('CV. ANUGERAK');
    expect((float) $expense->amount)->toBe(23197.0);
    expect($expense->items()->count())->toBe(1);
});

test('fallback regex mem-parse TEKS OCR ASLI yang penuh noise (struk CV. Anugerah id=7)', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    // Teks ini diambil LANGSUNG dari kolom `note` record expense "Struk Cv
    // Anugrah" (id=7) di database — bukan teks bersih yang disusun ulang.
    // Karakter noise OCR ("eV,", "——", "‘", "«", dash "-300", dst) sengaja
    // dipertahankan apa adanya agar test mencerminkan kondisi nyata.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk CV. Anugerah (OCR asli)',
        'note' => <<<'TXT'
        eV, ANUGERAK

        LLRAYA WALEKUKUN HO. -——"2

        GORANG GARENG MAGETAN
        ‘JL. BHAYANGKARA NO.60 KEL REJOSART
        KEC KAWEDANAN, KAB MAGETAN, 63382

        10.05.25-12:22/3.0.26/FLOS~35372/HASAN/02

        IDM KTG PLSTK IW BSR 1-300 308
        SFTX CLN MENST N-L28. 2 23308 46,600

        Vo PT STX : (13,600)
        POCART SWEAT Se@ML 1 7988 7,908
        You C186 DRK oRS140 7908 7,900
        MLKITA CNOY BTES 246 5200 5,200
        ULTRA SLIM COKLAT200, 6600 6,600
        ROMA WFR CHO BLS97.6 9700 9,700
        VETACIMIN STRIP 2'S 2300 4,600
        PISANG CAVENDISH WHL 658 _24.=«15,500

        TOTAL BELANJA : 98,700

        1
        1
        1
        1
        2
        6

        TUNAE : 100,000

        KEMBALE : 9,300
        ANDA HEMAT : 13,600
        PPN DPP= 73,333 PPN= 8,800
        PPN DIBEBASKAN : DPP= 14,208
        PPN= 1,705

        HARGA JUAL : 95,500
        ID POINKU. xxxxxxx0939/NUXx
        EK POIN/STAMP/LUCKY DI APPS POINKU
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();

    // Vendor kini dikenali sebagai nama toko (bukan baris alamat).
    expect($expense->vendor)->toBe('CV. ANUGERAK');

    // Total adalah nilai literal OCR "TOTAL BELANJA : 98,700".
    expect((float) $expense->amount)->toBe(98700.0);
    expect((float) $expense->change)->toBe(9300.0);

    // Seluruh 9 baris item berhasil ditangkap meski penuh noise OCR.
    $names = $expense->items()->pluck('name')->map(fn ($n) => strtoupper($n));
    expect($expense->items()->count())->toBe(9);
    expect($names)->toContain('IDM KTG PLSTK IW BSR');
    expect($names)->toContain('SFTX CLN MENST N-L28');
    expect($names)->toContain('POCART SWEAT SE@ML');
    expect($names)->toContain('YOU C186 DRK ORS140');
    expect($names)->toContain('MLKITA CNOY BTES 246');
    expect($names)->toContain('ULTRA SLIM COKLAT200');
    expect($names)->toContain('ROMA WFR CHO BLS97.6');
    expect($names)->toContain('VETACIMIN STRIP 2\'S');
    expect($names)->toContain('PISANG CAVENDISH WHL');

    // Nilai penting yang benar diturunkan dari teks OCR.
    $sftx = $expense->items()->where('name', 'like', 'SFTX%')->first();
    expect((float) $sftx->qty)->toBe(2.0);
    expect((float) $sftx->subtotal)->toBe(46600.0);

    $ultra = $expense->items()->where('name', 'like', 'ULTRA%')->first();
    expect((float) $ultra->qty)->toBe(1.0);
    expect((float) $ultra->price)->toBe(6600.0);

    $vit = $expense->items()->where('name', 'like', 'VETACIMIN%')->first();
    expect((float) $vit->qty)->toBe(2.0);
    expect((float) $vit->subtotal)->toBe(4600.0);

    // BUG REGRESI (fix #1): "MLKITA CNOY BTES 24G" terbaca OCR "MLKITA CNOY
    // BTES 246" — token "24G/246" adalah satuan gram produk, BUKAN qty. Parser
    // sekarang mendeteksinya (qty>1 tapi subtotal ≈ harga satuan) sehingga
    // qty benar = 1 dan token tetap menjadi bagian nama produk.
    $mlk = $expense->items()->where('name', 'like', 'MLKITA%')->first();
    expect($mlk)->not->toBeNull();
    expect((float) $mlk->qty)->toBe(1.0);
    expect((float) $mlk->price)->toBe(5200.0);
    expect((float) $mlk->subtotal)->toBe(5200.0);

    $pisang = $expense->items()->where('name', 'like', 'PISANG%')->first();
    expect((float) $pisang->price)->toBe(15500.0);
    expect((float) $pisang->subtotal)->toBe(15500.0);
});

test('fallback regex mem-parse struk format 2-baris (Karis Jaya Shop, expense id=10): nama item dari baris bernomor, qty/harga dari baris detail', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    // Teks ini diambil LANGSUNG dari kolom `note` record expense "Struk Jaya"
    // (id=10, vendor "Karis Jaya Shop") di database. Struk ini memakai format
    // 2-baris: nama produk di baris bernomor urut ("1. Indomie Goreng"),
    // qty/harganya di BARIS BERIKUTNYA ("1 lusin x 36,000"), kadang diikuti
    // "Rp subtotal". Noise OCR di atas/bawah item sengaja dipertahankan.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Jaya (format 2-baris)',
        'note' => <<<'TXT'
        y TC'
        Led
        Karis Jaya Shop
        JI. Dr. Ir. H. Soekarno No.19,Medokan Semampir
        Surabaya
        No. Telp 0812345678
        16413520230802084636
        2023-08-02 karis
        08:46:36 Sheila
        Jl. Diponegoro 1, Sby
        No.0-3
        1. Indomie Goreng
        1 lusin x 36,000
        2. Fruit Tea Apple
        1 500 ml x 7,000 Rp 7.000
        3. Belfood Sosis Bakar
        1 x 27,000 Rp 27.000
        Total QTY : 14
        Sub Total Rp 70.000
        Total Rp 70.000
        Bayar (Cash) Rp 70.000
        Kembali Rp 0
        Terimakasih Telah Berbelanja
        Link Kritik dan Saran:
        com/e-receipt/S-00D39U-07G344G
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();

    expect($expense->vendor)->toBe('Karis Jaya Shop');
    expect((float) $expense->amount)->toBe(70000.0);
    expect((float) $expense->change)->toBe(0.0);
    expect($expense->date_shopping->toDateString())->toBe('2023-08-02');

    // Nama item diambil dari teks SETELAH nomor urut (baris pertama), bukan
    // dari baris detail "qty satuan x harga" di bawahnya.
    $names = $expense->items()->pluck('name');
    expect($expense->items()->count())->toBe(3);
    expect($names)->toContain('Indomie Goreng');
    expect($names)->toContain('Fruit Tea Apple');
    expect($names)->toContain('Belfood Sosis Bakar');

    // Fragment baris detail TIDAK boleh tertangkap sebagai nama item lagi
    // (ini bug sebelumnya: "1 lusin x", "1 500 ml x", "1 x").
    expect($names)->not->toContain('1 lusin x');
    expect($names)->not->toContain('1 500 ml x');
    expect($names)->not->toContain('1 x');

    $indomie = $expense->items()->where('name', 'Indomie Goreng')->first();
    expect((float) $indomie->qty)->toBe(1.0);
    expect((float) $indomie->price)->toBe(36000.0);
    expect((float) $indomie->subtotal)->toBe(36000.0);

    // Baris detail "1 500 ml x 7,000 Rp 7.000" memuat Rp subtotal eksplisit.
    $fruitTea = $expense->items()->where('name', 'Fruit Tea Apple')->first();
    expect((float) $fruitTea->qty)->toBe(1.0);
    expect((float) $fruitTea->price)->toBe(7000.0);
    expect((float) $fruitTea->subtotal)->toBe(7000.0);

    $sosis = $expense->items()->where('name', 'Belfood Sosis Bakar')->first();
    expect((float) $sosis->qty)->toBe(1.0);
    expect((float) $sosis->price)->toBe(27000.0);
    expect((float) $sosis->subtotal)->toBe(27000.0);
});

test('fallback regex mem-parse struk TOKO ABANG (expense id=14, OCR asli verbatim): qty titik-ribuan dibaca sebagai qty kecil, subtotal dihitung ulang, total = SUM subtotal item, nama item tanpa fragmen "000 Kg"', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    // Teks ini diambil VERBATIM dari kolom `note` record expense "Struk Toko
    // Abang" (id=14) — termasuk blank line berlebih antar baris dan em-dash
    // "—" pada alamat. Struk grosir ini memakai format 2-baris tanpa nomor
    // urut, plus baris detail "qty ber-skala ribuan": "4.000 Kg X 12.500" =
    // 4 kg @ 12.500, dst. Tanggal "10.01.2023-10:11:07" MENEMPEL pada baris
    // yang sama dengan "No. Struk : 211" (bukan baris terpisah).
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Toko Abang',
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

    // Vendor: baris "GROSIR SEMBAKO DAN BERAS" (bukan "No. Struk : 211").
    expect($expense->vendor)->toBe('GROSIR SEMBAKO DAN BERAS');

    // Nama item diambil dari baris nama produk, BUKAN fragmen baris detail.
    $names = $expense->items()->pluck('name');
    expect($expense->items()->count())->toBe(8);
    expect($names)->toContain('Beras');
    expect($names)->not->toContain('000 Kg X');

    // qty titik-ribuan diterjemahkan jadi qty kecil, subtotal dihitung ulang.
    $beras = $expense->items()->where('name', 'Beras')->first();
    expect((float) $beras->qty)->toBe(4.0);
    expect((float) $beras->price)->toBe(12500.0);
    expect((float) $beras->subtotal)->toBe(50000.0);

    // Total WAJIB dari jumlah subtotal item (~200.000), bukan korup 200 juta.
    expect((float) $expense->amount)->toBe(200000.0);
    expect((float) $expense->change)->toBe(0.0);

    // BUG REGRESI (fix #2): tanggal format DD.MM.YYYY-HH:MM:SS yang MENEMPEL
    // pada baris yang sama dengan teks lain ("No. Struk : 211 10.01.2023-
    // 10:11:07") harus terbaca menjadi 2023-01-10.
    expect($expense->date_shopping->toDateString())->toBe('2023-01-10');

    // Semua item punya subtotal yang konsisten dengan qty x harga.
    foreach ($expense->items as $item) {
        expect((float) $item->subtotal)->toBe(round((float) $item->qty * (float) $item->price, 2));
    }
});

test('fallback regex mem-parse struk INDOMARET/CV. ANUGERAH (expense id=7): total koma-ribuan dengan spasi "90, 700" menjadi 90.700, subtotal item dash-join "1-300 308" jadi 300', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    // Rekonstruksi baris-baris kunci dari struk CV. Anugerah (id=7):
    //  - Total di baris TOTAL BELANJA terbaca OCR "90, 700" (koma + spasi)
    //    = sembilan puluh ribu tujuh ratus, BUKAN 700.
    //  - Item "IDM KTG PLSTK IW BSR 1-300 308": OCR menyambung qty=1 & harga=300
    //    dengan dash, subtotal yang benar adalah qty x harga = 300, bukan 308.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk CV. Anugerah (regresi id=7)',
        'note' => <<<'TXT'
        eV, ANUGERAK
        GORANG GARENG MAGETAN
        JL. BHAYANGKARA NO.60 KEL REJOSART
        IDM KTG PLSTK IW BSR 1-300 308
        SFTX CLN MENST N-L28. 2 23308 46,600
        PISANG CAVENDISH WHL 658 _24.=«15,500
        TOTAL BELANJA 90, 700
        TUNAE : 100,000
        KEMBALE : 9,300
        TXT,
    ]);

    $result = (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($result['ok'])->toBeTrue();
    expect($expense->vendor)->toBe('CV. ANUGERAK');
    expect((float) $expense->amount)->toBe(90700.0);
    expect((float) $expense->change)->toBe(9300.0);

    $idm = $expense->items()->where('name', 'like', 'IDM KTG PLSTK%')->first();
    expect($idm)->not->toBeNull();
    expect((float) $idm->qty)->toBe(1.0);
    expect((float) $idm->price)->toBe(300.0);
    expect((float) $idm->subtotal)->toBe(300.0);
});

