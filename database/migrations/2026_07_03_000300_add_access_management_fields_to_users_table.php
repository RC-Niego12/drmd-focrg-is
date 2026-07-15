<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('access_status')->default('approved')->index()->after('is_active');
            $table->string('requested_role')->nullable()->after('access_status');
            $table->timestamp('access_requested_at')->nullable()->after('requested_role');
            $table->timestamp('access_approved_at')->nullable()->after('access_requested_at');
            $table->foreignId('access_approved_by')->nullable()->after('access_approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_approved_by');
            $table->dropColumn([
                'access_approved_at',
                'access_requested_at',
                'requested_role',
                'access_status',
            ]);
        });
    }
};
