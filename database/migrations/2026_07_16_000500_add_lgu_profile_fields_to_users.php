<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('lgu_psgc_code')->nullable()->after('access_decided_at')->index();
            $table->string('lgu_level')->nullable()->after('lgu_psgc_code')->index();
            $table->string('lgu_name')->nullable()->after('lgu_level')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['lgu_psgc_code', 'lgu_level', 'lgu_name']));
    }
};
