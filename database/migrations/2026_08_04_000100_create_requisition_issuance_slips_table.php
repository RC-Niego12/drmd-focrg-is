<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_issuance_slips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('requests')->cascadeOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ris_number')->unique();
            $table->date('ris_date');
            $table->string('purpose_of_release');
            $table->string('recipient');
            $table->string('delivery_site')->nullable();
            $table->string('receiving_representative')->nullable();
            $table->string('contact_number', 80)->nullable();
            $table->text('remarks')->nullable();
            $table->json('items');
            $table->string('status', 30)->default('draft')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_issuance_slips');
    }
};
