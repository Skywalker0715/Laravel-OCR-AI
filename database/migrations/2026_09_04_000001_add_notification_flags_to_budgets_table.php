<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kolom penanda (flag) notifikasi budget pada tabel budgets.
     *
     * Peringatan budget (terpakai 90%+ dan terlampaui 100%+) hanya boleh
     * terkirim SEKALI per ambang batas per budget per bulan — bukan berulang
     * setiap kali user menambah expense baru di bulan yang sama. Timestamp
     * ini berfungsi sebagai state "sudah dikirim": NULL = belum, terisi =
     * sudah. Karena setiap periode (bulan+tahun) punya baris budget sendiri,
     * flag otomatis "reset" untuk periode berikutnya.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->timestamp('notified_90_at')->nullable()->after('year');
            $table->timestamp('notified_100_at')->nullable()->after('notified_90_at');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['notified_90_at', 'notified_100_at']);
        });
    }
};
