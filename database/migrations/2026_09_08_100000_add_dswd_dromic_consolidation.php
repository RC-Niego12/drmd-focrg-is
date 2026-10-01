<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dromic_reports', function (Blueprint $table) {
            $table->json('consolidation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dromic_reports', fn (Blueprint $table) => $table->dropColumn('consolidation'));
    }
};
