<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_insight_queries');
    }

    public function down(): void
    {
        // Tidak ada rollback: tabel dihapus secara permanen bersama fitur "Tanya AI".
    }
};
