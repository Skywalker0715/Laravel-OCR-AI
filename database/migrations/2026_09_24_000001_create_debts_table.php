<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pencatatan Utang Piutang — relevan untuk segmen UMKM (pinjam-meminjam
 * modal/barang dengan supplier, karyawan, atau relasi usaha).
 *
 * Satu baris = satu catatan untuk satu pihak:
 *  - type            : 'utang'   = uang yang kita PINJAM dari pihak lain
 *                      'piutang' = uang yang kita PINJAMKAN ke pihak lain
 *  - counterparty_name : nama orang/pihak terkait (supplier, karyawan, dll.)
 *  - amount          : nominal awal (decimal(15,2), konsisten dengan kolom
 *                      uang lain di aplikasi ini)
 *  - due_date        : jatuh tempo, opsional
 *  - status          : 'belum_lunas' | 'sebagian' | 'lunas' (diturunkan dari
 *                      paid_amount vs amount oleh model Debt)
 *  - paid_amount     : nilai yang sudah dibayar/diterima (untuk pelunasan
 *                      sebagian)
 *
 * Index gabungan (user_id, type, status) melayani query paling sering:
 * daftar & total utang/piutang aktif milik satu user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();

            // Pemilik catatan; ikut terhapus bersama user-nya.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('type', ['utang', 'piutang']);

            // Nama orang/pihak terkait (boleh nama supplier, karyawan, dsb.).
            $table->string('counterparty_name');

            $table->decimal('amount', 15, 2);

            // Jatuh tempo bersifat opsional.
            $table->date('due_date')->nullable();

            $table->enum('status', ['belum_lunas', 'lunas', 'sebagian'])
                ->default('belum_lunas');

            $table->decimal('paid_amount', 15, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                ['user_id', 'type', 'status'],
                'debts_user_id_type_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
