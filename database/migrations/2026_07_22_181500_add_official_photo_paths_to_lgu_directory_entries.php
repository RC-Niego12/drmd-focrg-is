<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('lgu_directory_entries', 'lce_photo_path')) {
                $table->string('lce_photo_path')->nullable()->after('lgu_logo_path');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'lswd_photo_path')) {
                $table->string('lswd_photo_path')->nullable()->after('lce_photo_path');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_photo_path')) {
                $table->string('ldrrmo_photo_path')->nullable()->after('lswd_photo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            foreach (['ldrrmo_photo_path', 'lswd_photo_path', 'lce_photo_path'] as $column) {
                if (Schema::hasColumn('lgu_directory_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
