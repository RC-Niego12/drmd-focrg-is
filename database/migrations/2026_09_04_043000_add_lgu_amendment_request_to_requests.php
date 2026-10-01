<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('lgu_amendment_request_status')->nullable()->after('lgu_correction_target');
            $table->text('lgu_amendment_request_reason')->nullable()->after('lgu_amendment_request_status');
            $table->foreignId('lgu_amendment_requested_by')->nullable()->after('lgu_amendment_request_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('lgu_amendment_requested_at')->nullable()->after('lgu_amendment_requested_by');
            $table->foreignId('lgu_amendment_reviewed_by')->nullable()->after('lgu_amendment_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('lgu_amendment_reviewed_at')->nullable()->after('lgu_amendment_reviewed_by');
            $table->text('lgu_amendment_review_note')->nullable()->after('lgu_amendment_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lgu_amendment_requested_by');
            $table->dropConstrainedForeignId('lgu_amendment_reviewed_by');
            $table->dropColumn([
                'lgu_amendment_request_status',
                'lgu_amendment_request_reason',
                'lgu_amendment_requested_at',
                'lgu_amendment_reviewed_at',
                'lgu_amendment_review_note',
            ]);
        });
    }
};
