<?php

use App\Support\AiAnswerSanitizer;

/**
 * Guard kualitas jawaban "Tanya AI" (regresi bug: jawaban cuma karakter "3"
 * diulang ribuan kali, dan markdown **bold** muncul mentah di notifikasi).
 */
it('menganggap jawaban rusak bila satu karakter diulang lebih dari 20 kali berturut-turut', function (): void {
    // Replay persis bug "3333…": 400 karakter "3" berturut-turut.
    expect(AiAnswerSanitizer::hasPathologicalRepetition(str_repeat('3', 400)))->toBeTrue()
        ->and(AiAnswerSanitizer::hasPathologicalRepetition(str_repeat('a', 21)))->toBeTrue()
        // Persis 20 kali masih dianggap wajar (ambang "lebih dari 20").
        ->and(AiAnswerSanitizer::hasPathologicalRepetition(str_repeat('a', 20)))->toBeFalse();

    // Jawaban yang mengandung loop di tengah kalimat juga harus tertangkap.
    expect(AiAnswerSanitizer::hasPathologicalRepetition('Total: '.str_repeat('9', 60)))->toBeTrue();
});

it('menganggap jawaban rusak bila satu kata diulang lebih dari 20 kali berturut-turut', function (): void {
    expect(AiAnswerSanitizer::hasPathologicalRepetition(trim(str_repeat('Rp ', 25))))->toBeTrue()
        ->and(AiAnswerSanitizer::hasPathologicalRepetition(trim(str_repeat('kategori ', 21))))->toBeTrue()
        ->and(AiAnswerSanitizer::hasPathologicalRepetition(trim(str_repeat('Rp ', 20))))->toBeFalse();
});

it('tidak menandai jawaban normal (termasuk yang menyebut tahun berulang) sebagai rusak', function (): void {
    $normal = "Total pengeluaran seluruh riwayat Anda Rp 2.704.455 (23 transaksi).\n"
        ."Rincian per tahun:\n  2017: Rp 84.700\n  2024: Rp 9.400\n  2026: Rp 2.179.664";

    expect(AiAnswerSanitizer::hasPathologicalRepetition($normal))->toBeFalse()
        ->and(AiAnswerSanitizer::hasPathologicalRepetition(''))->toBeFalse()
        ->and(AiAnswerSanitizer::hasPathologicalRepetition('Kategori terbesar: Makanan & Minuman Rp 400.000.'))->toBeFalse();
});

it('menghapus penanda markdown **bold**/__bold__ dari jawaban', function (): void {
    expect(AiAnswerSanitizer::stripMarkdown('Total pengeluaran Anda **Rp 15.000**.'))
        ->toBe('Total pengeluaran Anda Rp 15.000.')
        ->and(AiAnswerSanitizer::stripMarkdown('**Rp 50.000** untuk __Makanan__'))
        ->toBe('Rp 50.000 untuk Makanan')
        ->and(AiAnswerSanitizer::stripMarkdown('  **Rp 9.400**  '))
        ->toBe('Rp 9.400');
});
