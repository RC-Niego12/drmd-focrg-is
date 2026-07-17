<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('access_response_message')->nullable()->after('access_approved_by');
            $table->timestamp('access_decided_at')->nullable()->after('access_response_message');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['access_response_message', 'access_decided_at']);
        });
    }
};
