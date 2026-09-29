<?php

namespace App\Support;

/**
 * Penjaga kualitas jawaban fitur "Tanya AI" (FinancialInsightService).
 *
 * Dua failure mode LLM yang merusak tampilan jawaban ke user:
 *
 * 1. LOOP PENGULANGAN — model "nyangkut" mengulang satu token, misalnya
 *    karakter "3" ratusan kali sampai memenuhi layar. Ini sifat dasar model
 *    (biasanya muncul pada temperature rendah) dan TIDAK bisa dicegah 100%
 *    dari sisi aplikasi; karena itu jawaban semacam ini dideteksi lalu
 *    dibuang supaya tidak pernah tampil ke user.
 * 2. MARKDOWN MENTAH — jawaban memakai penekanan tebal/miring (mis. teks
 *    yang dibungkai bintang ganda) sementara jawaban ditampilkan sebagai
 *    teks polos di notifikasi Filament, sehingga tanda bintang muncul apa
 *    adanya dan terlihat seperti salah ketik.
 *
 * Kelas ini sengaja statis & tanpa dependency agar mudah diuji terpisah
 * (lihat tests/Unit/AiAnswerSanitizerTest.php).
 */
final class AiAnswerSanitizer
{
    /**
     * Ambang pengulangan yang masih dianggap wajar. Satu karakter atau satu
     * kata yang sama muncul LEBIH dari 20 kali berturut-turut dianggap rusak.
     */
    public const MAX_CONSECUTIVE_REPEATS = 20;

    /**
     * Deteksi pola pengulangan berlebihan: satu karakter yang sama atau satu
     * kata yang sama muncul > $maxConsecutive kali berturut-turut
     * (mis. "3333333333…" atau "Rp Rp Rp Rp …").
     *
     * Catatan regex: `(.)\1{N,}` / `(?:\s+\1){N,}` cocok saat karakter/kata
     * tersebut muncul N+1 kali atau lebih, jadi N = ambang yang diizinkan.
     */
    public static function hasPathologicalRepetition(string $text, int $maxConsecutive = self::MAX_CONSECUTIVE_REPEATS): bool
    {
        $threshold = max(1, $maxConsecutive);

        // (1) Karakter yang sama berulang-ulang: "3333333…", "aaaa…", "-----…".
        if (preg_match('/(.)\1{'.$threshold.',}/us', $text) === 1) {
            return true;
        }

        // (2) Kata yang sama berulang-ulang (dipisah spasi/newline):
        //     "Rp Rp Rp Rp …" — penanda loop token pada level kata.
        if (preg_match('/\b(\S+)(?:\s+\1){'.$threshold.',}/ius', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Bersihkan sisa format markdown yang tidak diinginkan pada jawaban
     * (penekanan tebal/miring). Dipakai sebagai pengaman kedua — AI tetap
     * kadang memakai markdown walau prompt sudah melarangnya.
     */
    public static function stripMarkdown(string $text): string
    {
        return trim((string) preg_replace('/(\*\*|__)/', '', $text));
    }
}
