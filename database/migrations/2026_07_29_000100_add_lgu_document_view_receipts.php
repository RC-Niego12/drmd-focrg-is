<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->timestamp('lgu_dromic_seen_at')->nullable()->after('lgu_dromic_review_note');
            $table->foreignId('lgu_dromic_seen_by')->nullable()->after('lgu_dromic_seen_at')->constrained('users')->nullOnDelete();
            $table->timestamp('lgu_relief_seen_at')->nullable()->after('lgu_relief_review_note');
            $table->foreignId('lgu_relief_seen_by')->nullable()->after('lgu_relief_seen_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lgu_dromic_seen_by');
            $table->dropColumn('lgu_dromic_seen_at');
            $table->dropConstrainedForeignId('lgu_relief_seen_by');
            $table->dropColumn('lgu_relief_seen_at');
        });
    }
};
