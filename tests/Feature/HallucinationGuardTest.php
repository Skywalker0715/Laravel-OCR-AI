<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Guard anti-halusinasi di AIParserJob (jalur AI):
 *
 * MASALAH — AI kadang MEMBUAT item yang tidak ada di teks OCR (halusinasi).
 * Penjumlahan item halusinasi lalu "mendukung" total AI walau baris total final
 * eksplisit (TOTAL BAYAR) jelas berbeda — Guard Total lama menganggap hasil AI
 * konsisten sehingga MEMPERTAHANKAN total yang salah.
 *
 * FIX — ItemHallucinationDetector menandai struk bila mayoritas nama item tidak
 * ditemukan / yatim (tanpa nominal) di OCR. Bila terdeteksi DAN ada baris total
 * final eksplisit, nilai eksplisit menang; tanpa baris eksplisit perilaku lama
 * tetap. Kasus BNI (item SAH + baris eksplisit typo OCR) TIDAK terdeteksi,
 * guard lama tetap dipertahankan.
 *
 * Struk SECURE PARK (id 33, teks OCR mentah dari database):
 */
const GUARD_SECURE_PARK_NOTE = <<<'TXT'
SECURE PARK
Mall Palembang Square
TIKET PARKIR MOBIL
No. Plat: BG 1234 XYZ
Masuk : 17-09-2026 10:15
kKeluar 17-09-2026 13:47
Durasi : 3 Jam 32 Menit
Tarif Jam ke-1 Rp 5.000
Tarif Jam ke-2 dst x3 Rp 12.000
TOTAL BAYAR Rp 17.000
Simpan tiket sampai keluar area
Kehilangan tiket dikenakan denda
TXT;

test('item HALUSINASI + baris TOTAL BAYAR eksplisit → total mengikuti eksplisit (SECURE PARK 29.000 → 17.000)', function () {
    // Replay persis output AI lama: item "Parkir Mobil" (dibuat AI dari header
    // tiket + baris tarif) dengan total AI 29.000 yang sebenarnya KONSISTEN
    // dengan 5.000 + 24.000, padahal TOTAL BAYAR eksplisit = 17.000.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SECURE PARK","date":"2026-09-17","items":[{"name":"Parkir Mobil","qty":1,"price":5000,"subtotal":5000},{"name":"Parkir Mobil","qty":2,"price":12000,"subtotal":24000}],"total":29000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Parkir',
        'note' => GUARD_SECURE_PARK_NOTE,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    // PRIORITAS: baris "TOTAL BAYAR Rp 17.000" menang — JANGAN pertahankan 29.000
    // walau konsisten dengan penjumlahan item halusinasi.
    expect((float) $expense->amount)->toBe(17000.0)
        ->and($expense->items_mismatch)->toBeTrue();
});

test('kasus BNI (item SAH + baris eksplisit typo OCR) TIDAK ikut berubah — guard lama tetap dipertahankan', function () {
    // Struk BNI: item TAG PLN 145.375 + ADMIN BANK 1.600 = 146.975 benar-benar
    // ada di OCR; label "TOTAL BAYAR 2 Rp 148.975" adalah typo OCR. Item sah
    // (bukan halusinasi) → guard TIDAK boleh menimpa 146.975 dengan 148.975.
    // Struk BNI (id 18, teks OCR mentah dari database):
    $bniNote = <<<'TXT'
    ®) rastpay SSBNI
    SYARIAH
    STRUK PEMBAYARAN TAGIHAN LISTRIK
    TANGGAL i 11-04-2012 69:17:56
    RPTAG PLN Rp 145.375
    ‘ADMIN BANK Rp 11600
    TOTAL BAYAR 2 Rp 148.975
    INFORMASI PLN HUB: 123
    TXT;

    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SYARIAH","date":"2012-04-11","items":[{"name":"TAG PLN","qty":1,"price":145375,"subtotal":145375},{"name":"‘ADMIN BANK","qty":1,"price":1600,"subtotal":1600}],"total":146975,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk BNI',
        'note' => $bniNote,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    // Label eksplisit 148.975 (typo OCR) TIDAK menimpa 146.975 — item terbukti sah.
    expect((float) $expense->amount)->toBe(146975.0);
});

test('TANPA baris total final eksplisit → perilaku lama tetap (halusinasi tidak mengubah apa pun)', function () {
    // Struk parkir TANPA baris "TOTAL BAYAR" — tidak ada nilai eksplisit untuk
    // diprioritaskan, jadi hasil AI (29.000) tetap disimpan seperti sebelumnya.
    $noExplicitNote = <<<'TXT'
    SECURE PARK
    Mall Palembang Square
    TIKET PARKIR MOBIL
    No. Plat: BG 1234 XYZ
    Masuk : 17-09-2026 10:15
    kKeluar 17-09-2026 13:47
    Durasi : 3 Jam 32 Menit
    Tarif Jam ke-1 Rp 5.000
    Tarif Jam ke-2 dst x3 Rp 12.000
    Simpan tiket sampai keluar area
    Kehilangan tiket dikenakan denda
    TXT;

    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SECURE PARK","date":"2026-09-17","items":[{"name":"Parkir Mobil","qty":1,"price":5000,"subtotal":5000},{"name":"Parkir Mobil","qty":2,"price":12000,"subtotal":24000}],"total":29000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Parkir Tanpa Total',
        'note' => $noExplicitNote,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    // Tidak ada baris eksplisit → hasil AI 29.000 dibiarkan apa adanya.
    expect((float) $expense->amount)->toBe(29000.0);
});