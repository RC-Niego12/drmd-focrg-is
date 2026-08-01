<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->string('lswd_alternate_name')->nullable();
            $table->string('lswd_alternate_position')->nullable();
            $table->text('lswd_alternate_contact_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'lswd_alternate_name',
                'lswd_alternate_position',
                'lswd_alternate_contact_number',
            ]);
        });
    }
};
