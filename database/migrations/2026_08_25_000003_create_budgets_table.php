<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buat tabel anggaran (budget) bulanan.
     *
     * Setiap baris adalah batas belanja untuk satu periode bulan+tahun,
     * milik satu user. category_id nullable berarti budget bisa berlaku
     * umum (semua kategori) atau khusus untuk satu kategori saja.
     */
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            // Pemilik anggaran; ikut terhapus bersama user-nya.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // Nullable = anggaran umum tanpa kategori spesifik.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->decimal('amount', 10, 2);
            // Bulan (1-12) dan tahun periode anggaran.
            $table->integer('month');
            $table->integer('year');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
