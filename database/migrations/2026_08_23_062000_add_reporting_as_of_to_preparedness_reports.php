<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->timestamp('reporting_as_of')->nullable()->after('status'));
        DB::table('preparedness_reports')->whereNull('reporting_as_of')->update(['reporting_as_of' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('preparedness_reports', fn (Blueprint $table) => $table->dropColumn('reporting_as_of'));
    }
};
