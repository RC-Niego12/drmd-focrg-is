<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_dromic_correction_scope')->nullable()->after('lgu_dromic_review_note');
            $table->timestamp('lgu_dromic_correction_resolved_at')->nullable()->after('lgu_dromic_correction_scope');
            $table->string('lgu_relief_correction_scope')->nullable()->after('lgu_relief_review_note');
            $table->timestamp('lgu_relief_correction_resolved_at')->nullable()->after('lgu_relief_correction_scope');
            $table->foreignId('lgu_correction_of_id')->nullable()->after('lgu_relief_correction_resolved_at')->constrained('requests')->nullOnDelete();
            $table->string('lgu_correction_target')->nullable()->after('lgu_correction_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lgu_correction_of_id');
            $table->dropColumn([
                'lgu_dromic_correction_scope',
                'lgu_dromic_correction_resolved_at',
                'lgu_relief_correction_scope',
                'lgu_relief_correction_resolved_at',
                'lgu_correction_target',
            ]);
        });
    }
};
