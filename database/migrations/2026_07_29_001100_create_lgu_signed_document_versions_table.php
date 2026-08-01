<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_signed_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_signed_document_versions');
    }
};
