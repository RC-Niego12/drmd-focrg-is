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
            $table->string('lgu_relief_request_reference', 40)->nullable()->unique()->after('lgu_dromic_report_classification');
        });

        DB::table('requests')
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereRaw("JSON_EXTRACT(lgu_dromic_payload, '$.has_relief_request') = true")
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row): void {
                $date = $row->created_at ? date('Ymd', strtotime($row->created_at)) : now()->format('Ymd');
                DB::table('requests')->where('id', $row->id)->update([
                    'lgu_relief_request_reference' => 'LGU-RA-'.$date.'-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropUnique(['lgu_relief_request_reference']);
            $table->dropColumn('lgu_relief_request_reference');
        });
    }
};
