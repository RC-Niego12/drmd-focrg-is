<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_relief_validation_status')->nullable()->after('lgu_dromic_review_note')->index();
            $table->foreignId('lgu_relief_reviewed_by')->nullable()->after('lgu_relief_validation_status')->constrained('users')->nullOnDelete();
            $table->timestamp('lgu_relief_reviewed_at')->nullable()->after('lgu_relief_reviewed_by');
            $table->text('lgu_relief_review_note')->nullable()->after('lgu_relief_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lgu_relief_reviewed_by');
            $table->dropColumn([
                'lgu_relief_validation_status',
                'lgu_relief_reviewed_at',
                'lgu_relief_review_note',
            ]);
        });
    }
};
