<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('area_of_assignment')->nullable()->after('designation');
            $table->string('employment_status')->nullable()->after('area_of_assignment');
            $table->json('sso_profile_payload')->nullable()->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['area_of_assignment', 'employment_status', 'sso_profile_payload']);
        });
    }
};
