<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integritas data: unique constraint pencegah duplikat + index pendukung query.
 *
 * PRASYARAT — migration ini TIDAK menghapus/mengubah data apa pun. Bila ternyata
 * masih ada baris duplikat, CREATE UNIQUE INDEX akan GAGAL dan seluruh migration
 * di-rollback (fail-safe). Audit Task 0 (dicek ulang sebelum migration dibuat)
 * menunjukkan 0 duplikat untuk budgets & categories, jadi aman dijalankan.
 *
 * Driver-aware:
 *  - PostgreSQL 15+ : `UNIQUE NULLS NOT DISTINCT` untuk budgets, sehingga NULL
 *    (anggaran umum tanpa kategori) juga dianggap nilai yang sama.
 *  - SQLite / PostgreSQL <15 : dua PARTIAL UNIQUE INDEX terpisah
 *    (category_id IS NULL dan IS NOT NULL) — padanan perilaku yang sama.
 *  - Driver lain (mis. MySQL): partial index tidak didukung → dipakai UNIQUE
 *    biasa; kombinasi tanpa kategori (category_id NULL) TIDAK dijamin DB.
 *
 * Nama index dijaga tetap sama antar driver supaya down() bisa membersihkan
 * jalur mana pun yang sempat dipakai.
 */
return new class extends Migration
{
    /** Unique kombinasi periode anggaran per user untuk kategori TERISI. */
    private const BUDGET_UNIQUE = 'budgets_user_category_period_unique';

    /** Unique anggaran UMUM (category_id NULL) per user + periode. */
    private const BUDGET_UNIQUE_WITHOUT_CATEGORY = 'budgets_user_period_without_category_unique';

    /** Unique nama kategori untuk kategori MILIK user (user_id terisi). */
    private const CATEGORY_UNIQUE_USER = 'categories_user_name_unique';

    /** Unique nama kategori DEFAULT SISTEM (user_id NULL). */
    private const CATEGORY_UNIQUE_DEFAULT = 'categories_default_name_unique';

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $this->addBudgetUniqueIndex($driver);
        $this->addCategoryUniqueIndexes($driver);

        // Index pendukung daftar utang/piutang per user: urut/filter jatuh
        // tempo (overdue & mendatang) dan filter status (aktif vs lunas).
        Schema::table('debts', function (Blueprint $table): void {
            $table->index(['user_id', 'due_date'], 'debts_user_id_due_date_index');
            $table->index(['user_id', 'status'], 'debts_user_id_status_index');
        });
    }

    public function down(): void
    {
        // Untuk index yang dibuat lewat raw SQL (partial index) dipakai
        // DROP INDEX IF EXISTS agar down() tetap aman walau jalur up() yang
        // dipakai berbeda (mis. versi PostgreSQL berbeda).
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->dropPostgresIndexes(),
            'sqlite' => $this->dropSqliteIndexes(),
            default => $this->dropFallbackIndexes(),
        };

        Schema::table('debts', function (Blueprint $table): void {
            $table->dropIndex('debts_user_id_due_date_index');
            $table->dropIndex('debts_user_id_status_index');
        });
    }

    /**
     * Unique kombinasi (user_id, category_id, month, year) pada budgets.
     */
    private function addBudgetUniqueIndex(string $driver): void
    {
        if ($driver === 'pgsql' && $this->postgresSupportsNullsNotDistinct()) {
            // PostgreSQL 15+: satu constraint sudah mencakup kategori NULL
            // (NULLS NOT DISTINCT membuat NULL = NULL saat pengecekan unik).
            Schema::table('budgets', function (Blueprint $table): void {
                $table->unique(['user_id', 'category_id', 'month', 'year'], self::BUDGET_UNIQUE)
                    ->nullsNotDistinct();
            });

            return;
        }

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            // Partial unique index dipisah karena unique index biasa tidak
            // pernah menganggap NULL sama (termasuk di PostgreSQL <15).
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON budgets (user_id, category_id, month, year) WHERE category_id IS NOT NULL',
                self::BUDGET_UNIQUE,
            ));
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON budgets (user_id, month, year) WHERE category_id IS NULL',
                self::BUDGET_UNIQUE_WITHOUT_CATEGORY,
            ));

            return;
        }

        // Fallback driver tanpa partial index (mis. MySQL): hanya kombinasi
        // ber-kategori yang dijamin DB. Dicatat sebagai batasan, bukan bug.
        Schema::table('budgets', function (Blueprint $table): void {
            $table->unique(['user_id', 'category_id', 'month', 'year'], self::BUDGET_UNIQUE);
        });
    }

    /**
     * Cegah duplikat nama kategori per user; dipisah antara kategori default
     * sistem (user_id NULL) dan kategori milik user.
     *
     * Perbandingan nama tetap PERSIS (case-sensitive) mengikuti perilaku
     * aplikasi sekarang (findOrCreateByName & rule form memakai pencocokan
     * exact) — case-sensitivity sengaja TIDAK diubah.
     */
    private function addCategoryUniqueIndexes(string $driver): void
    {
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON categories (user_id, name) WHERE user_id IS NOT NULL',
                self::CATEGORY_UNIQUE_USER,
            ));
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON categories (name) WHERE user_id IS NULL',
                self::CATEGORY_UNIQUE_DEFAULT,
            ));

            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(['user_id', 'name'], self::CATEGORY_UNIQUE_USER);
            $table->unique('name', self::CATEGORY_UNIQUE_DEFAULT);
        });
    }

    /**
     * PostgreSQL 15+ mendukung UNIQUE NULLS NOT DISTINCT (server_version_num
     * ≥ 150000; mis. PostgreSQL 18.6 → 180006).
     *
     * Penting: `php artisan migrate --pretend` membungkus seluruh up() dalam mode
     * pretend, sehingga setiap query TIDAK dieksekusi dan selectOne() mengembalikan
     * null. withoutPretending() membuat pemeriksaan versi membaca server sungguhan
     * supaya output --pretend mencerminkan jalur yang benar-benar akan dijalankan.
     */
    private function postgresSupportsNullsNotDistinct(): bool
    {
        try {
            $connection = Schema::getConnection();

            $version = $connection->withoutPretending(
                fn (): ?object => $connection->selectOne('show server_version_num')
            );

            return (int) ($version?->server_version_num ?? 0) >= 150000;
        } catch (\Throwable) {
            return false;
        }
    }

    private function dropPostgresIndexes(): void
    {
        // Bila up() memakai NULLS NOT DISTINCT, index dibuat sebagai CONSTRAINT —
        // PostgreSQL menolak DROP INDEX untuk index milik constraint, jadi harus
        // lewat ALTER TABLE ... DROP CONSTRAINT.
        DB::statement('ALTER TABLE "budgets" DROP CONSTRAINT IF EXISTS '.self::BUDGET_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::BUDGET_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::BUDGET_UNIQUE_WITHOUT_CATEGORY);
        DB::statement('DROP INDEX IF EXISTS '.self::CATEGORY_UNIQUE_USER);
        DB::statement('DROP INDEX IF EXISTS '.self::CATEGORY_UNIQUE_DEFAULT);
    }

    private function dropSqliteIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::BUDGET_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::BUDGET_UNIQUE_WITHOUT_CATEGORY);
        DB::statement('DROP INDEX IF EXISTS '.self::CATEGORY_UNIQUE_USER);
        DB::statement('DROP INDEX IF EXISTS '.self::CATEGORY_UNIQUE_DEFAULT);
    }

    private function dropFallbackIndexes(): void
    {
        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropUnique(self::BUDGET_UNIQUE);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique(self::CATEGORY_UNIQUE_USER);
            $table->dropUnique(self::CATEGORY_UNIQUE_DEFAULT);
        });
    }
};
