<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_plans', 'fulfillment_type_confirmed')) {
            Schema::table('dispatch_plans', function (Blueprint $table) {
                $table->boolean('fulfillment_type_confirmed')->default(false)->after('fulfillment_type');
            });
        }

        // Existing non-draft records have already operated under a delivery mode.
        DB::table('dispatch_plans')->where('status', '!=', 'draft')->update(['fulfillment_type_confirmed' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('dispatch_plans', 'fulfillment_type_confirmed')) {
            Schema::table('dispatch_plans', fn (Blueprint $table) => $table->dropColumn('fulfillment_type_confirmed'));
        }
    }
};
