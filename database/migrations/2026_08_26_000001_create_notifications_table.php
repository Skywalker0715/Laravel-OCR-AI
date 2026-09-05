<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mengaktifkan notifikasi database (lonceng Filament).
     *
     * Tabel standar Laravel/Filament untuk menyimpan notifikasi per user
     * (digunakan Notification::sendToDatabase()). Migration baru terpisah —
     * tidak mengubah tabel yang sudah ada — supaya data tetap aman.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};