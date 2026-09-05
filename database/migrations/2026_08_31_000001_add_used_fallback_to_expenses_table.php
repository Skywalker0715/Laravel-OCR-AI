<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan penanda jalur parsing yang dipakai pada sebuah expense.
     *
     * used_fallback = true artinya data struk ini dihasilkan oleh parser regex
     * lokal (jalur fallback) karena AI Cohere gagal/tidak tersedia, sedangkan
     * false berarti diproses oleh AI. Flag ini disimpan agar halaman View
     * Expense bisa menampilkan notice informasi kecil tanpa harus memanggil
     * API eksternal lagi.
     *
     * Kolom ini hanya mengubah perilaku untuk expense yang diproses SETELAH
     * migration ini dipasang; expense lama dianggap used_fallback = false.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('used_fallback')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('used_fallback');
        });
    }
};