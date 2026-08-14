<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_plans', 'fulfillment_type')) {
            Schema::table('dispatch_plans', function (Blueprint $table) {
                $table->string('fulfillment_type', 40)
                    ->default('field_delivery')
                    ->after('status');
            });
        }

        DB::table('dispatch_plans')
            ->whereNull('fulfillment_type')
            ->orWhere('fulfillment_type', '')
            ->update(['fulfillment_type' => 'field_delivery']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('dispatch_plans', 'fulfillment_type')) {
            Schema::table('dispatch_plans', function (Blueprint $table) {
                $table->dropColumn('fulfillment_type');
            });
        }
    }
};
