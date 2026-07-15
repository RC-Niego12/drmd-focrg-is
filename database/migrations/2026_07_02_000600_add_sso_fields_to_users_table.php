<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('sso_sub')->nullable()->unique()->after('id');
            $table->string('username')->nullable()->index()->after('email');
            $table->string('id_number')->nullable()->unique()->after('username');
            $table->string('mobile_no')->nullable()->after('contact_number');
            $table->string('avatar')->nullable()->after('mobile_no');
            $table->boolean('mfa_enabled')->default(false)->after('avatar');
            $table->boolean('mfa_verified')->default(false)->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'sso_sub',
                'username',
                'id_number',
                'mobile_no',
                'avatar',
                'mfa_enabled',
                'mfa_verified',
            ]);
        });
    }
};
