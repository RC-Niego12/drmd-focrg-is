<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('lgu_directory_role', 40)->nullable()->after('lgu_name');
        });

        Schema::table('lgu_directory_officials', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id_number')->constrained('users')->nullOnDelete();
            $table->string('login_username', 120)->nullable()->after('user_id');
            $table->unique('user_id');
        });

        Schema::table('lgu_directory_lswdo_alternates', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('id_number');
            $table->foreignId('user_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $table->string('login_username', 120)->nullable()->after('user_id');
            $table->unique('user_id');
        });

        Schema::table('lgu_directory_ldrrmo_officers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('facebook')->constrained('users')->nullOnDelete();
            $table->string('login_username', 120)->nullable()->after('user_id');
            $table->unique('user_id');
        });

        Schema::table('lgu_directory_staff_members', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('contact_number');
            $table->foreignId('user_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $table->string('login_username', 120)->nullable()->after('user_id');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_staff_members', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email', 'login_username']);
        });

        Schema::table('lgu_directory_ldrrmo_officers', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['login_username']);
        });

        Schema::table('lgu_directory_lswdo_alternates', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email', 'login_username']);
        });

        Schema::table('lgu_directory_officials', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['login_username']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('lgu_directory_role');
        });
    }
};
