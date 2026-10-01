<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->timestamp('revision_deadline')->nullable()->after('reporting_as_of'));
        DB::table('preparedness_reports')->whereNull('revision_deadline')->update(['revision_deadline' => now()->addDays(7)]);
    }

    public function down(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->dropColumn('revision_deadline'));
    }
};
