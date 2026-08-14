<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_items', function (Blueprint $table): void {
            $table->string('brand_description', 1000)->nullable()->after('item_name');
            $table->string('expiry', 1000)->nullable()->after('brand_description');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_items', function (Blueprint $table): void {
            $table->dropColumn(['brand_description', 'expiry']);
        });
    }
};
