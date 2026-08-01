<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('lgu_directory_entries', 'lgu_logo_path')) {
                $table->string('lgu_logo_path')->nullable()->after('office_address');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_name')) {
                $table->string('ldrrmo_name')->nullable()->after('lgu_logo_path');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_position')) {
                $table->string('ldrrmo_position')->nullable()->after('ldrrmo_name');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_contact')) {
                $table->string('ldrrmo_contact')->nullable()->after('ldrrmo_position');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_email')) {
                $table->string('ldrrmo_email')->nullable()->after('ldrrmo_contact');
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'ldrrmo_payload')) {
                $table->json('ldrrmo_payload')->nullable()->after('ldrrmo_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            foreach ([
                'ldrrmo_payload',
                'ldrrmo_email',
                'ldrrmo_contact',
                'ldrrmo_position',
                'ldrrmo_name',
                'lgu_logo_path',
            ] as $column) {
                if (Schema::hasColumn('lgu_directory_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
