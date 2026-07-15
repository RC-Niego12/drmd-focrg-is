<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('epirma_status', 24)->nullable()->index();
            $table->uuid('epirma_transaction_id')->nullable()->unique();
            $table->string('epirma_callback_token', 64)->nullable();
            $table->string('epirma_signature_reference')->nullable();
            $table->timestamp('epirma_signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropUnique(['epirma_transaction_id']);
            $table->dropIndex(['epirma_status']);
            $table->dropColumn([
                'epirma_status',
                'epirma_transaction_id',
                'epirma_callback_token',
                'epirma_signature_reference',
                'epirma_signed_at',
            ]);
        });
    }
};
