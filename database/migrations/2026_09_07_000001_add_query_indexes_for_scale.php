<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan index database untuk query yang paling sering dijalankan.
     *
     * Dengan data kecil aplikasi tetap terasa cepat walau tanpa index, tapi
     * begitu data mencapai ribuan baris, query yang menyaring per user,
     * per tanggal, per kategori, atau per notifikasi akan melambat karena
     * database harus memindai seluruh tabel (full table scan).
     *
     * Index berikut tidak mengubah behavior aplikasi sama sekali — hanya
     * mempercepat query dengan membuat database mencari langsung ke baris
     * yang relevan.
     */
    public function up(): void
    {
        // expenses: query utama panel & laporan (filter per user + rentang
        // tanggal) dan statistik per user + kategori.

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['user_id', 'date_shopping'], 'expenses_user_id_date_shopping_index');
            $table->index(['user_id', 'category_id'], 'expenses_user_id_category_id_index');
        });

        // expense_items: JOIN dari expenses (mengambil item per struk, dan
        // cascade delete saat expense dihapus.
        Schema::table('expense_items', function (Blueprint $table) {
            $table->index('expenses_id', 'expense_items_expenses_id_index');
        });

        // budgets: pencarian anggaran milik satu user untuk satu periode
        // (tahun+bulan) dan satu kategori — dipakai BudgetAlertService.


        Schema::table('budgets', function (Blueprint $table) {
            $table->index(['user_id', 'year', 'month', 'category_id'], 'budgets_user_id_year_month_category_id_index');
        });

        // categories: daftar kategori pribadi milik satu user (prioritas
        // rendah, sekalian ditambahkan di sini.
        Schema::table('categories', function (Blueprint $table) {
            $table->index('user_id', 'categories_user_id_index');
        });

        // notifications: query lonceng notifikasi Filament — daftar notifikasi
        // milik satu user yang belum dibaca (prioritas rendah,i sekalian saja).
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_type_notifiable_id_read_at_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_user_id_date_shopping_index');
            $table->dropIndex('expenses_user_id_category_id_index');
        });

        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropIndex('expense_items_expenses_id_index');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('budgets_user_id_year_month_category_id_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_user_id_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_type_notifiable_id_read_at_index');
        });
    }
};