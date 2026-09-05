<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ubah tipe kolom expenses.date_shopping dari string (varchar) menjadi date.
     *
     * Budget::spentAmount() memakai extract(year from date_shopping) dan
     * extract(month from ...) yang di PostgreSQL hanya valid untuk kolom
     * bertipe date/timestamp — pada varchar muncul error
     * "function pg_catalog.extract(unknown, character varying) does not exist".
     *
     * Langkah penting (agar tidak menghapus data):
     * 1. Data lama yang nilainya kosong (''), tidak berformat YYYY-MM-DD, atau
     *    tanggal kalender tidak valid (mis. 2023-02-30) dinormalisasi menjadi
     *    NULL dulu — kalau tidak, cast ::date akan gagal.
     * 2. Baru lakukan ALTER ... TYPE date USING date_shopping::date (hanya pada
     *    driver pgsql; sqlite/mysql di test tidak memakai sintaks ini).
     */
    public function up(): void
    {
        $this->normalizeInvalidDates();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE expenses ALTER COLUMN date_shopping TYPE date USING date_shopping::date');
        }
    }

    /**
     * Rollback: kembalikan kolom date menjadi string (sama seperti sebelumnya).
     * Cast kembali ke text tidak menghapus data apa pun.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE expenses ALTER COLUMN date_shopping TYPE varchar(255) USING date_shopping::text');
        }
    }

    /**
     * Set NULL untuk seluruh date_shopping yang tidak bisa di-cast ke date.
     *
     * Memakai query builder (bukan model Expense) supaya global scope
     * per-user milik Expanses tidak ikut menyaring baris — migration harus
     * melihat SEMUA baris di tabel.
     */
    private function normalizeInvalidDates(): void
    {
        $rows = DB::table('expenses')
            ->whereNotNull('date_shopping')
            ->get(['id', 'date_shopping']);

        $invalidIds = $rows
            ->filter(fn ($row): bool => ! $this->isValidDate((string) $row->date_shopping))
            ->pluck('id');

        if ($invalidIds->isNotEmpty()) {
            DB::table('expenses')
                ->whereIn('id', $invalidIds)
                ->update(['date_shopping' => null]);
        }
    }

    /**
     * Cek apakah string adalah tanggal valid YYYY-MM-DD (termasuk valid secara
     * kalender, mis. tolak 2023-02-30 dan 2023-13-01).
     */
    private function isValidDate(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
};