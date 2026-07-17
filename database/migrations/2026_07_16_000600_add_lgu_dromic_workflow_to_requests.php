<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->foreignId('lgu_submitted_by')->nullable()->after('encoded_by')->constrained('users')->nullOnDelete();
            $table->string('lgu_routing_status')->nullable()->after('status')->index();
            $table->json('lgu_dromic_payload')->nullable()->after('assessment_form_data');
            $table->text('lgu_dromic_narrative')->nullable()->after('lgu_dromic_payload');
            $table->text('drmd_aa_remarks')->nullable()->after('lgu_dromic_narrative');
            $table->foreignId('drmd_aa_routed_by')->nullable()->after('drmd_aa_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('drmd_aa_routed_at')->nullable()->after('drmd_aa_routed_by');
            $table->text('drmd_chief_remarks')->nullable()->after('drmd_aa_routed_at');
            $table->foreignId('drmd_chief_routed_by')->nullable()->after('drmd_chief_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('drmd_chief_routed_at')->nullable()->after('drmd_chief_routed_by');
            $table->foreignId('drmd_assigned_to')->nullable()->after('drmd_chief_routed_at')->constrained('users')->nullOnDelete();
            $table->string('drmd_assigned_section')->nullable()->after('drmd_assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lgu_submitted_by');
            $table->dropConstrainedForeignId('drmd_aa_routed_by');
            $table->dropConstrainedForeignId('drmd_chief_routed_by');
            $table->dropConstrainedForeignId('drmd_assigned_to');
            $table->dropColumn([
                'lgu_routing_status',
                'lgu_dromic_payload',
                'lgu_dromic_narrative',
                'drmd_aa_remarks',
                'drmd_aa_routed_at',
                'drmd_chief_remarks',
                'drmd_chief_routed_at',
                'drmd_assigned_section',
            ]);
        });
    }
};
