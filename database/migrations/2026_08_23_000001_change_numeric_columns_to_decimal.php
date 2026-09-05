<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->decimal('qty', 10, 2)->change();
            $table->decimal('price', 10, 2)->change();
            $table->decimal('subtotal', 10, 2)->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('change', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->integer('qty')->change();
            $table->integer('price')->change();
            $table->integer('subtotal')->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->integer('change')->nullable()->change();
        });
    }
};
