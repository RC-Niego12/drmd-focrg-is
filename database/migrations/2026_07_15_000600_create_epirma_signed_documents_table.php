<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epirma_signed_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assistance_request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->uuid('document_uuid')->unique();
            $table->string('document_name');
            $table->string('document_path');
            $table->text('description_subject')->nullable();
            $table->string('encoded_by')->nullable()->index();
            $table->timestamp('timestamp')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epirma_signed_documents');
    }
};
