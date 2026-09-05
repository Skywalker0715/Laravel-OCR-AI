<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kolom category_id (foreign key ke tabel categories) pada
     * tabel expenses. Migration baru dibuat terpisah — tidak mengubah
     * migration lama — agar data yang sudah ada tetap aman. Kolom dibuat
     * nullable, dan nullOnDelete menjaga expense tidak ikut terhapus ketika
     * sebuah kategori dihapus.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('user_id')
                ->constrained('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
