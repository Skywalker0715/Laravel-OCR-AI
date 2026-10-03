<?php

use App\Support\LogSanitizer;

/*
 * Guard pemotongan isi log (TASK 7 butir 1).
 *
 * Isi struk, respons AI mentah, dan pertanyaan "Tanya AI" adalah data
 * pribadi pengguna. Kode memindahkannya ke Log::debug() dengan potongan
 * singkat supaya file log di server tidak menjadi salinan data keuangan
 * pengguna. Test ini mengunci sifat itu: excerpt tidak boleh bocor isi penuh,
 * dan tidak boleh gagal untuk input byte non-UTF-8 dari hasil OCR.
 */

test('potongan pendek dikembalikan apa adanya tanpa noise "dipotong"', function () {
    expect(LogSanitizer::excerpt('Total Rp 70.000'))->toBe('Total Rp 70.000');
});

test('isi panjang dipotong pada batas dan ditandai eksplisit', function () {
    $excerpt = LogSanitizer::excerpt(str_repeat('A', 1000));

    expect(mb_strlen($excerpt))->toBeLessThan(LogSanitizer::EXCERPT_LIMIT + 20)
        ->and($excerpt)->toContain('[dipotong]');
});

test('batas panjang kustom dihormati', function () {
    $excerpt = LogSanitizer::excerpt(str_repeat('B', 500), limit: 50);

    expect(mb_strlen($excerpt))->toBeLessThan(70);
});

test('baris baru diratakan menjadi spasi agar muat dalam satu baris log', function () {
    // Teks struk selalu multi-baris; tanpa perataan, satu entri log bisa
    // jadi puluhan baris dan menyulitkan pencarian log.
    $excerpt = LogSanitizer::excerpt("Karis Jaya Shop\nJl. Dr. Ir. H. Soekarno\nNo. Telp 0812345678");

    expect($excerpt)->toBe('Karis Jaya Shop Jl. Dr. Ir. H. Soekarno No. Telp 0812345678');
});

test('string kosong & null dilambangkan jelas, bukan menjadi blank', function () {
    expect(LogSanitizer::excerpt(''))->toBe('(kosong)')
        ->and(LogSanitizer::excerpt(null))->toBe('(kosong)');
});

test('jsonExcerpt meng-encode array hasil parsing lalu memotongnya', function () {
    // Susunan key diuji apa adanya: field panjang (vendor) sengaja DI AWAL
    // supaya excerpt memotong sebelum field di belakangnya — persis seperti
    // keadaan nyata saat hasil OCR memuat baris vendor yang sangat panjang.
    $excerpt = LogSanitizer::jsonExcerpt(['vendor' => str_repeat('X', 500), 'total' => 70000]);

    expect($excerpt)->toStartWith('{"vendor":"XXX')
        ->and($excerpt)->toContain('[dipotong]')
        // Panjang excerpt tetap terkendali meski JSON-nya jauh lebih besar.
        ->and(mb_strlen($excerpt))->toBeLessThan(LogSanitizer::EXCERPT_LIMIT + 20);
});

test('jsonExcerpt tidak kehilangan isi saat input memuat byte non-UTF-8 hasil OCR', function () {
    // Tanpa JSON_INVALID_UTF8_SUBSTITUTE, json_encode() mengembalikan false
    // dan cuplikan hilang seluruhnya — justamente saat paling dibutuhkan.
    $excerpt = LogSanitizer::jsonExcerpt(['vendor' => "Toko \xB1\x1A harboring", 'total' => 1000]);

    expect($excerpt)->toContain('"total":1000');
});

test('excerpt tidak melempar exception pada byte rusak (jalur OCR tidak rapi)', function () {
    $broken = "Struk \xB1\x1A \xFF data korup";

    expect(fn () => LogSanitizer::excerpt($broken))->not->toThrow(Throwable::class)
        ->and(LogSanitizer::excerpt($broken))->not->toBe('');
});