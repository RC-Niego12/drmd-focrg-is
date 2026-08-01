<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->json('lgu_dromic_review_history')->nullable()->after('lgu_dromic_review_screenshots');
            $table->json('lgu_relief_review_history')->nullable()->after('lgu_relief_review_screenshots');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropColumn([
                'lgu_dromic_review_history',
                'lgu_relief_review_history',
            ]);
        });
    }
};
