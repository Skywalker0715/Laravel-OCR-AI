<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Regression test untuk 5 struk nyata (expense id 16, 17, 18, 19, 21) yang
 * sebelumnya memicu 5 pola bug sistematis pada fallback regex:
 *  1. Vendor terisi jam/tanggal/alamat/nama barang.
 *  2. Baris non-item (jam, REF, footer setelah "terima kasih") jadi item hantu.
 *  3. Kode produk MR.DIY terbaca sebagai Qty.
 *  4. Digit nyelip: "Rp 1.600" tersimpan Rp 11.600.
 *  5. Tanggal DD-Mon-YYYY ("05-Jan-2021") tidak tertangkap.
 *
 * Teks OCR diambil verbatim dari kolom `note` database, noise apa adanya.
 */
test('BUG #1+#5 — struk PLN (id=16): vendor bukan jam/tanggal, tanggal 05-Jan-2021 terbaca, item hantu jam terbuang', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Listrik',
        'note' => <<<'TXT'
        ajuobauar® , BO
        griyabayar paul a
        ope
        AL
        10:14:01 WIB, 05-Jan-2021
        STRUK PEMBAYARAN TAGIHAN LISTRIK
        IDPEL : 211011471074
        NAMA : YY AN-NURIYAH ASS
        TARIF/DAYA : _S2/450VA
        BL/TH : DES20
        STAND METER : 00014103-00014185
        RP TAG PLN: Rp 27.516
        NO REF :
        ‘OMAS2105095132000000000787258383
        PLN menyatakan struk ini sbg
        bukti pembayaran yg sah.
        ADMIN BANK: Rp 3.000
        TOTAL BAYAR : Rp 30.516
        Anda masih memiliki sisa
        tunggakan 1 bulan
        "Informasi Hubungi Call Center
        123 Atau Hub PLN Terdekat :"
        633906PON220, ppNayuri
        050121/101401 /0000033445/C01
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // Vendor TIDAK boleh baris jam "10:14:01 WIB, 05-Jan-2021"; baris terbaik
    // di region header adalah teks logo "griyabayar paul a" (dibersihkan).
    expect($expense->vendor)->toBe('griyabayar paul');

    // BUG 5: format DD-Mon-YYYY kini terbaca.
    expect($expense->date_shopping->toDateString())->toBe('2021-01-05');
    expect((float) $expense->amount)->toBe(30516.0);

    // Item hantu "10:14:01 WIB, 05-Jan-" (harga ngarang 2021) terbuang;
    // rincian tagihan yang sah tetap: TAG PLN + ADMIN BANK = TOTAL BAYAR.
    $names = $expense->items()->pluck('name');
    expect($expense->items()->count())->toBe(2);
    expect($names)->toContain('TAG PLN');
    expect($names)->toContain('ADMIN BANK');
    expect($names->filter(fn ($n) => str_contains((string) $n, '10:14'))->isEmpty())->toBeTrue();
});

test('BUG #1+#3 — struk elektronik (id=17): vendor PPG (bukan "Pulse Sensor"), format "HARGA xQTY SUBTOTAL" dibaca benar, "Pointer" tidak jadi item hantu "000 x1"', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Elektronik',
        'note' => <<<'TXT'
        13:56:30
        PPG
        500.000 x1 500.000
        Pulse Sensor
        150.000 x1 150,000
        Pointer
        225.000 x1 225.000
        Total Belanja Rp. 875.000
        Tunal RP. 900.000
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // Vendor = teks brand paling atas (setelah baris jam yang di-exclude).
    expect($expense->vendor)->toBe('PPG');

    // Struk ini tidak mencetak tanggal — TIDAK boleh diisi ngarang.
    expect($expense->date_shopping)->toBeNull();

    // Total dari baris "Total Belanja", bukan 1.125.650 hasil gabungan angka.
    expect((float) $expense->amount)->toBe(875000.0);

    // "500.000 x1 500.000" = harga x qty, BUKAN qty=500 @ Rp 1.
    $items = $expense->items()->get();
    expect($items)->toHaveCount(3);

    $ppg = $items->firstWhere('name', 'PPG');
    expect((float) $ppg->qty)->toBe(1.0);
    expect((float) $ppg->price)->toBe(500000.0);
    expect((float) $ppg->subtotal)->toBe(500000.0);

    $names = $items->pluck('name');
    expect($names)->toContain('Pulse Sensor');
    expect($names)->toContain('Pointer');

    // Item hantu lama: "000 x1" (sisa baris detail) & "Tunal" (tunai).
    expect($names->filter(fn ($n) => str_contains((string) $n, 'x1'))->isEmpty())->toBeTrue();
    expect($names)->not->toContain('Tunal');

    // Jumlah subtotal item = total struk.
    expect((float) $items->sum('subtotal'))->toBe(875000.0);
});

test('BUG #2+#4 — struk BNI (id=18): baris WSI REF bukan item, "ADMIN BANK Rp 11600" dikoreksi jadi 1.600 lewat validasi silang total', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk BNI',
        'note' => <<<'TXT'
        ®) rastpay SSBNI
        SYARIAH
        OKARI MULTIPAYMENT
        PERUM TVRI BLOK C ND 30
        O81253112427
        cu
        STRUK PEMBAYARAN TAGIHAN LISTRIK
        TANGGAL i 11-04-2012 69:17:56
        NO. REST : 12656919
        IDPEL 2 231000632715
        NAMA, : DJOKO SURARTO
        TARIF/DAYA : RIL/9e8e VA
        BL/TH : APR1Z
        STAND METER 01578200-01602800
        WSI REF 1 CCAEZDOCESS7C4 270684B5F7174B3E3702
        NON SUBSIDI : Rp @
        RPTAG PLN Rp 145.375
        ‘ADMIN BANK Rp 11600
        TOTAL BAYAR 2 Rp 148.975
        PLN MENYATAKAN STRUK INI SBG BUKTI
        PEMBAYARAN YG SAH, MHN OLTSIMPAN
        TERIMA KASTH.
        INFORMASI PLN HUB: 123
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->vendor)->toBe('SYARIAH');
    expect($expense->date_shopping->toDateString())->toBe('2012-04-11');

    // Baris referensi "WSI REF 1 CCAE...3702" TIDAK boleh jadi item.
    $items = $expense->items()->get();
    expect($items)->toHaveCount(2);
    $names = $items->pluck('name');
    expect($names->filter(fn ($n) => str_contains((string) $n, 'REF'))->isEmpty())->toBeTrue();

    // BUG 4: "Rp 11600" (digit nyelip dari "Rp 1.600") dikoreksi menjadi
    // 1.600 karena hanya varian hapus-1-digit yang paling mendekati total.
    $admin = $items->first(fn ($i) => str_contains((string) $i->name, 'ADMIN BANK'));
    expect($admin)->not->toBeNull();
    expect((float) $admin->price)->toBe(1600.0);
    expect((float) $admin->subtotal)->toBe(1600.0);

    $tagPln = $items->firstWhere('name', 'TAG PLN');
    expect((float) $tagPln->price)->toBe(145375.0);

    // BUG residual (digit nyelip di baris TOTAL): struk mencetak
    // "TOTAL BAYAR : Rp 146.975" tetapi OCR salah baca "148.975". Karena
    // TAG PLN + ADMIN BANK = tepat 146.975 (beda satu digit, bukan digit
    // terdepan), penjumlahan item yang tervalidasi dipakai sebagai Total.
    expect((float) $expense->amount)->toBe(146975.0);
});

test('BUG #3 — struk MR.DIY (id=19): kode produk 7-digit tidak jadi qty dan tidak menempel di nama item', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Diy',
        'note' => <<<'TXT'
        MRIS,

        Always Low Prices:

        - INVOICE -
        SPONS MAKE UP
        9057664, 1X 14,500 14,500
        KACAMATA GAYA
        9058730 1 X 13,500 13,500
        ANTING - ANTING
        9054620 1 X 8,000 8,000
        IKAT RAMBUT
        9051981 1X 4,000 4,000
        ALAT PENCABUT ALIS
        9053831 1 X 10,000 10,000
        Item(s) : 5 Gtyls) : 5
        TOTAL Rp 50,000

        JANGAN BUANG STRUK
        BELANJA MR.DIY-MU!
        urusancuanmrdiy.id
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->vendor)->toBe('MRIS');
    expect($expense->date_shopping)->toBeNull();
    expect((float) $expense->amount)->toBe(50000.0);

    // 5 item dengan nama produk BERSIH dari kode, qty=1 (bukan 9058 dst).
    $items = $expense->items()->get();
    expect($items)->toHaveCount(5);

    $expected = [
        'SPONS MAKE UP' => 14500.0,
        'KACAMATA GAYA' => 13500.0,
        'ANTING - ANTING' => 8000.0,
        'IKAT RAMBUT' => 4000.0,
        'ALAT PENCABUT ALIS' => 10000.0,
    ];

    foreach ($expected as $name => $price) {
        $item = $items->firstWhere('name', $name);
        expect($item)->not->toBeNull();
        expect((float) $item->qty)->toBe(1.0);
        expect((float) $item->price)->toBe($price);
        expect((float) $item->subtotal)->toBe($price);
    }

    // Tidak ada nama item yang memuat kode produk.
    foreach ($items as $item) {
        expect(preg_match('/\d{5,}/', (string) $item->name))->toBe(0);
    }

    // Total = jumlah subtotal item.
    expect((float) $items->sum('subtotal'))->toBe(50000.0);
});

test('BUG #1+#2 — struk resto Pawoon (id=21): vendor "Pawoon Resto" (bukan alamat), "Nama xQty Harga" dibaca qty=2, footer "Pass Wit" terbuang', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Resto Pawang',
        'note' => <<<'TXT'
        Pawoon Resto
        AXA Tower Lt? JL Prof,
        DR. Satrio Kav, 18
        Kuningan, Jakarta Selatan
        12940
        1500-360
        Kode Struk: 9873982342341
        No. Maja: 3
        Tanggal : 2017-07-23 08:45:34
        Kasir : Ibrahim Abdullah
        Pelanggan ; Bilal Fahreda
        Martabak Original x2 40,000
        Es Teh Manis x1 4000
        Martabak Telur xi 33,000
        Subtotal 7,000
        PPN (10%) 7.700
        Total 84,700
        Tunai 100,000
        Kembali 15,300
        Terima kasih
        Pass Wit 173458
        Ei pawoonpos
        . pawoonpas
        paWwoonpos
        fy Pawoon pos tiktok
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // Vendor = baris pertama (nama resto), BUKAN "CV. Satrio Kav, 18" (alamat).
    expect($expense->vendor)->toBe('Pawoon Resto');
    expect($expense->date_shopping->toDateString())->toBe('2017-07-23');
    expect((float) $expense->amount)->toBe(84700.0);
    expect((float) $expense->change)->toBe(15300.0);

    // Footer setelah "Terima kasih" ("Pass Wit 173458", watermark) bukan item.
    $items = $expense->items()->get();
    expect($items)->toHaveCount(3);

    $martabak = $items->firstWhere('name', 'Martabak Original');
    expect((float) $martabak->qty)->toBe(2.0);
    expect((float) $martabak->price)->toBe(20000.0);
    expect((float) $martabak->subtotal)->toBe(40000.0);

    $names = $items->pluck('name');
    expect($names)->toContain('Es Teh Manis');
    expect($names->filter(fn ($n) => str_contains((string) $n, 'Pass Wit'))->isEmpty())->toBeTrue();
});




