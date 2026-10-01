<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->json('data_snapshot')->nullable()->after('reporting_as_of'));
    }

    public function down(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->dropColumn('data_snapshot'));
    }
};
