<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan penanda ketidakcocokan antara penjumlahan item dan total struk.
     *
     * items_mismatch = true artinya SUM(subtotal item) TIDAK sama dengan kolom
     * `amount` (Total) dan selisihnya signifikan (> Rp1.000 atau > 5% dari total)
     * serta TIDAK bisa dijelaskan baris diskon/PPN/biaya yang dikenali. Ini
     * terjadi ketika OCR salah membaca salah satu item (mis. dua baris item
     * terbaca identik), sehingga user diharuskan memeriksa manual lewat banner
     * di halaman View Expense.
     *
     * Flag ini ditulis oleh AIParserJob untuk SEMUA jalur parsing (AI maupun
     * fallback regex), dan di-reset setiap kali parsing dijalankan ulang.
     * Expense lama sebelum migration ini dianggap items_mismatch = false.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('items_mismatch')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('items_mismatch');
        });
    }
};
