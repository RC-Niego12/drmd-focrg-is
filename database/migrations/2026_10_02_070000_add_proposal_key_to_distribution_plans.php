<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->uuid('proposal_key')->nullable()->after('id')->index();
        });

        foreach (DB::table('distribution_plans')->whereNull('proposal_key')->pluck('id') as $id) {
            DB::table('distribution_plans')->where('id', $id)->update([
                'proposal_key' => (string) Str::uuid(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('distribution_plans', function (Blueprint $table): void {
            $table->dropIndex(['proposal_key']);
            $table->dropColumn('proposal_key');
        });
    }
};
