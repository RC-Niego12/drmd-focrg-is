<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_psgc_code', 20)->nullable()->after('lgu_level')->index();
        });

        DB::table('requests')
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNull('lgu_psgc_code')
            ->orderBy('id')
            ->eachById(function ($report): void {
                $userId = $report->lgu_submitted_by ?: $report->encoded_by;
                $psgcCode = $userId
                    ? DB::table('users')->where('id', $userId)->value('lgu_psgc_code')
                    : null;

                if ($psgcCode) {
                    DB::table('requests')->where('id', $report->id)->update(['lgu_psgc_code' => $psgcCode]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropIndex(['lgu_psgc_code']);
            $table->dropColumn('lgu_psgc_code');
        });
    }
};
