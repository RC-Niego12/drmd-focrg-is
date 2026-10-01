<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_delivery_updates', function (Blueprint $table): void {
            $table->decimal('accuracy_meters', 8, 1)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_delivery_updates', function (Blueprint $table): void {
            $table->dropColumn('accuracy_meters');
        });
    }
};
