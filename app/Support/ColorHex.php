<?php

namespace App\Support;

/**
 * Validasi & sanitasi kode warna heksadesimal kategori.
 *
 * Warna kategori (`categories.color` dan `category_appearance_overrides.color`)
 * bertipe varchar(20) nullable: kolom database hanya membatasi PANJANG, bukan
 * isi. Nilainya berasal dari form Filament (ColorPicker) maupun override per
 * user, lalu dipakai ulang sebagai nilai CSS `background-color` di beberapa
 * tempat — termasuk di dalam atribut `style` pada template PDF (lihat
 * resources/views/exports/laporan-pdf.blade.php).
 *
 * Tanpa validasi, satu nilai rusak (mis. string berisi `;` atau karakter
 * non-heksadesimal) ikut diteruskan ke CSS. Blade meng-escape kutip sehingga
 * nilainya tidak bisa "menembus" atribut HTML, tetapi CSS yang tidak valid
 * tetap membuat PDF gagal dirender atau tampil tanpa warna sama sekali.
 *
 * Karena itu aturan yang sama dipakai dua kali:
 *  1. Form — validasi memakai ColorHex::PATTERN menolak input buruk lebih awal;
 *  2. Tampilan — ColorHex::safe() mengganti nilai di luar pola dengan warna
 *     netral, sehingga satu baris rusak tidak merusak seluruh dokumen.
 *
 * POLA_HEX sengaja disimpan di sini (bukan ditulis ulang di tiap tempat)
 * supaya form, PDF, dan test tidak pernah berbeda pendapat soal format yang
 * dianggap sah.
 */
final class ColorHex
{
    /**
     * Pola kode warna yang dianggap sah: `#` + tepat 6 digit heksadesimal
     * (mis. `#10B981`). Alias warna seperti `red` TIDAK diterima — kolom ini
     * menyimpan hex dan semua tempat pemakainya mengharapkan format hex.
     */
    public const PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** Warna netral yang dipakai saat nilai tidak lolos pola di atas. */
    public const FALLBACK = '#CBD5E1';

    /**
     * True bila $value adalah string hex 6 digit yang sah. Nilai non-string
     * (mis. array dari ColorPicker yang formatnya diubah) selalu ditolak.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, trim($value)) === 1;
    }

    /**
     * Kembalikan $value bila hex sah, bila tidak $fallback. Dipakai tepat di
     * sebelum nilai masuk ke CSS/HTML supaya render tidak pernah gagal.
     */
    public static function safe(mixed $value, string $fallback = self::FALLBACK): string
    {
        return self::isValid($value) ? trim((string) $value) : $fallback;
    }
}