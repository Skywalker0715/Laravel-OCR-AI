<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perlebar kolom uang yang terlibat kegagalan simpan parsing
 * (SQLSTATE[22003] "numeric field overflow", expense id 16-20, 2026-09-03).
 *
 * Latar belakang:
 *  - Penyebab UTAMA overflow adalah regex fallback salah menangkap nomor
 *    identitas struk (IDPEL, no. HP/WA, kode referensi) sebagai nominal.
 *    Itu diperbaiki di AIParserService (batas MAX_PLAUSIBLE_MONEY + keyword
 *    skip) dan AIParserJob (guard sanitizeMoneyForColumn). Migration ini
 *    BUKAN pengganti fix tersebut.
 *  - Kolom tetap diperlebar demi KONSISTENSI schema: expenses.amount sudah
 *    decimal(15,2) (mendukung struk grosir bernilai besar, mis. struk Toko
 *    Abang dengan subtotal item puluhan juta), sedangkan expense_items.price/
 *    subtotal masih decimal(10,2) (maks Rp 99.999.999,99) sehingga item struk
 *    grosir yang SAH bisa overflow padahal nominalnya wajar.
 *  - Kolom item juga dibuat nullable agar guard di AIParserJob bisa menyimpan
 *    NULL (bukan gagal total) untuk nilai yang tetap tidak wajar — sesuai
 *    strategi project "minimal title & foto tetap tersimpan, user koreksi
 *    manual".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->decimal('qty', 14, 2)->nullable()->change();
            $table->decimal('price', 14, 2)->nullable()->change();
            $table->decimal('subtotal', 14, 2)->nullable()->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('change', 14, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Presisi dikembalikan ke decimal(10,2), tetapi nullable DIPERTAHANKAN:
     * menjadikan NOT NULL lagi berisiko gagal bila guard sudah sempat
     * menyimpan NULL, dan menghapus/mengubah data otomatis tidak boleh
     * dilakukan diam-diam.
     */
    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->decimal('qty', 10, 2)->nullable()->change();
            $table->decimal('price', 10, 2)->nullable()->change();
            $table->decimal('subtotal', 10, 2)->nullable()->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('change', 10, 2)->nullable()->change();
        });
    }
};
