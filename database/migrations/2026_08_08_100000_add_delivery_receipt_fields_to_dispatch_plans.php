<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->date('delivered_at')->nullable()->after('receipt_remarks');
            $table->string('release_witnessed_by')->nullable()->after('delivered_at');
            $table->boolean('fully_delivered')->nullable()->after('release_witnessed_by');
            $table->boolean('has_returned_items')->nullable()->after('fully_delivered');
            $table->text('returned_particulars')->nullable()->after('has_returned_items');
            $table->unsignedInteger('returned_quantity')->nullable()->after('returned_particulars');
            $table->text('returned_reason')->nullable()->after('returned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'delivered_at',
                'release_witnessed_by',
                'fully_delivered',
                'has_returned_items',
                'returned_particulars',
                'returned_quantity',
                'returned_reason',
            ]);
        });
    }
};
