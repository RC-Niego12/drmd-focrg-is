<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->string('ldrrmc_logo_path')->nullable()->after('lgu_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->dropColumn('ldrrmc_logo_path');
        });
    }
};
