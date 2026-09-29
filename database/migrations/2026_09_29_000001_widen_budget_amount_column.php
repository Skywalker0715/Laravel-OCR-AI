<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perlebar budgets.amount dari decimal(10,2) menjadi decimal(15,2).
 *
 * Latar belakang (bug 2026-09-29): form Create/Edit Budget menerima nominal
 * sebesar apa pun (hanya dibatasi minValue(0)), sedangkan kolomnya masih
 * decimal(10,2) — batas maksimalnya cuma Rp 99.999.999,99. Begitu user
 * mengisi nominal yang wajar untuk UMKM (mis. 300 juta = 10^8, atau
 * 3 miliar = 10^9), PostgreSQL melempar SQLSTATE[22003] "numeric field
 * overflow" sehingga perubahan nominal tidak tersimpan sama sekali.
 *
 * Presisi 15,2 dipilih agar KONSISTEN dengan kolom uang lain di aplikasi
 * (expenses.amount, incomes.amount, debts.amount & debts.paid_amount):
 * batas aman ±9,99 triliun — jauh di atas kebutuhan segmen personal
 * maupun UMKM. Batas input di form sendiri tetap dijaga
 * MoneyFormatter::MAX_INPUT_AMOUNT (1 triliun) supaya user menerima pesan
 * validasi yang ramah, bukan error SQL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            // NOT NULL sengaja dipertahankan (->nullable() tidak dipakai):
            // setiap baris anggaran wajib punya batas nominal.
            $table->decimal('amount', 15, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Presisi dikembalikan ke decimal(10,2) seperti migration awal.
     * Peringatan: rollback ini akan gagal bila sudah ada budget bernominal
     * di atas Rp 99.999.999,99 — perbaiki datanya dulu bila perlu.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->change();
        });
    }
};
