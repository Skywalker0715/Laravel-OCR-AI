<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pemasukan (income) — sumber uang masuk seperti "Gaji", "Freelance",
 * atau "Penjualan". Ini fondasi awal sebelum fitur "Kas Arus" (pemasukan
 * dikurangi pengeluaran) dibangun di atasnya.
 *
 * Struktur sengaja sederhana & mirip expenses agar mudah diperbandingkan:
 *  - user_id      : pemilik catatan, ikut terhapus bersama user-nya
 *  - source       : sumber pemasukan (teks bebas, generik untuk personal & UMKM)
 *  - amount       : decimal(15,2) — presisi SAMA dengan expenses.amount,
 *                   sehingga keduanya bisa langsung dijumlah/dibandingskan
 *                   tanpa risiko overflow untuk nominal besar
 *  - date_received: tanggal pemasukan diterima (bukan timestamp input)
 *  - notes        : catatan tambahan, opsional
 *
 * Index (user_id, date_received) melayani query paling sering: daftar &
 * total pemasukan milik satu user, diurutkan per tanggal terima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();

            // Pemilik catatan pemasukan; ikut terhapus bersama user-nya.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Sumber pemasukan dalam teks bebas, mis. "Gaji", "Freelance",
            // "Penjualan" — sengaja bukan enum agar tetap generik.
            $table->string('source');

            // Presisi identik dengan expenses.amount (decimal 15,2).
            $table->decimal('amount', 15, 2);

            // Tanggal pemasukan diterima; wajib diisi (NOT NULL).
            $table->date('date_received');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                ['user_id', 'date_received'],
                'incomes_user_id_date_received_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
