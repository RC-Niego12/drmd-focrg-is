<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'lgu_response_letter_advance_path')) {
                $table->string('lgu_response_letter_advance_path')->nullable()->after('lgu_response_letter_acked_by');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_advance_name')) {
                $table->string('lgu_response_letter_advance_name')->nullable()->after('lgu_response_letter_advance_path');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_advance_sent_at')) {
                $table->timestamp('lgu_response_letter_advance_sent_at')->nullable()->after('lgu_response_letter_advance_name');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_advance_acked_at')) {
                $table->timestamp('lgu_response_letter_advance_acked_at')->nullable()->after('lgu_response_letter_advance_sent_at');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_advance_acked_by')) {
                $table->foreignId('lgu_response_letter_advance_acked_by')->nullable()->after('lgu_response_letter_advance_acked_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'lgu_dromic_acked_at')) {
                $table->timestamp('lgu_dromic_acked_at')->nullable()->after('lgu_dromic_seen_by');
            }
            if (! Schema::hasColumn('requests', 'lgu_dromic_acked_by')) {
                $table->foreignId('lgu_dromic_acked_by')->nullable()->after('lgu_dromic_acked_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'lgu_relief_acked_at')) {
                $table->timestamp('lgu_relief_acked_at')->nullable()->after('lgu_relief_seen_by');
            }
            if (! Schema::hasColumn('requests', 'lgu_relief_acked_by')) {
                $table->foreignId('lgu_relief_acked_by')->nullable()->after('lgu_relief_acked_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            foreach ([
                'lgu_response_letter_advance_acked_by',
                'lgu_dromic_acked_by',
                'lgu_relief_acked_by',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach ([
                'lgu_response_letter_advance_path',
                'lgu_response_letter_advance_name',
                'lgu_response_letter_advance_sent_at',
                'lgu_response_letter_advance_acked_at',
                'lgu_dromic_acked_at',
                'lgu_relief_acked_at',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
