<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ubah tipe kolom "data" pada tabel notifications dari text menjadi jsonb.
     *
     * Filament database notifications butuh operator PostgreSQL ->> pada kolom
     * `data`, yang hanya tersedia jika kolom bertipe jsonb (bukan text). Secara
     * teknis Laravel/Filament sudah menyediakan JSON di tabel notifications bawaan
     * Laravel, tetapi migration "pintu masuk" aplikasi ini menciptakannya sebagai
     * `<text>`. Migration baru tidak mengedit migration lama — hanya memperbaiki
     * skema yang sudah ada — dan TIDAK menghapus data yang mungkin sudah tersimpan
     * (`USING data::jsonb` meng-cast nilai yang ada).
     *
     * Statement sengaja hanya dijalankan untuk driver pgsql; driver lain (sqlite/
     * mysql) tidak memakai sintaks `ALTER COLUMN ... TYPE jsonb` dan tidak
     * membutuhkan operasi ini. Ini juga membuat test (sqlite :memory:) tetap jalan.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    /**
     * Kembalikan kolom data menjadi text bila migration di-rollback. Tetap
     * meng-cast data yang ada agar tidak rusak.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};