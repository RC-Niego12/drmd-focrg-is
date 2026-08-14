<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stf_sheet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('stf_number')->unique();
            $table->date('stf_date')->nullable()->index();
            $table->string('recipient')->nullable();
            $table->string('delivery_site')->nullable();
            $table->string('purpose')->nullable();
            $table->string('status')->nullable();
            $table->string('source_row_number')->nullable();
            $table->json('tracking_data')->nullable();
            $table->json('items')->nullable();
            $table->timestamp('sheet_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stf_sheet_transactions');
    }
};
