<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->boolean('vehicles_needed')->nullable()->after('number_of_vehicles');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->dropColumn('vehicles_needed');
        });
    }
};
