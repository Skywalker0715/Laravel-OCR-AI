<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buat tabel kategori belanja.
     *
     * Kategori bisa bersifat default sistem (user_id NULL) maupun buatan
     * user ("custom"). Kolom user_id sengaja nullable agar seeder kategori
     * default bisa dibuat tanpa pemilik, dan setiap user bisa menambahkan
     * kategori pribadinya sendiri.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nama ikon Heroicon yang dipakai di menu/daftar (opsional).
            $table->string('icon')->nullable();
            // Warna hex (misal "#10B981") untuk badge/warna kategori.
            $table->string('color', 20)->nullable();
            // Nullable = kategori default sistem; terisi = kategori milik user.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
