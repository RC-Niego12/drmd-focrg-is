<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'assessment_acted_by')) {
                $table->foreignId('assessment_acted_by')->nullable()->after('assigned_social_worker')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'assessment_on_behalf_of')) {
                $table->foreignId('assessment_on_behalf_of')->nullable()->after('assessment_acted_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'assessment_on_behalf_reason')) {
                $table->text('assessment_on_behalf_reason')->nullable()->after('assessment_on_behalf_of');
            }
            if (! Schema::hasColumn('requests', 'assessment_acted_at')) {
                $table->timestamp('assessment_acted_at')->nullable()->after('assessment_on_behalf_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (Schema::hasColumn('requests', 'assessment_acted_at')) {
                $table->dropColumn('assessment_acted_at');
            }
            if (Schema::hasColumn('requests', 'assessment_on_behalf_reason')) {
                $table->dropColumn('assessment_on_behalf_reason');
            }
            if (Schema::hasColumn('requests', 'assessment_on_behalf_of')) {
                $table->dropConstrainedForeignId('assessment_on_behalf_of');
            }
            if (Schema::hasColumn('requests', 'assessment_acted_by')) {
                $table->dropConstrainedForeignId('assessment_acted_by');
            }
        });
    }
};
