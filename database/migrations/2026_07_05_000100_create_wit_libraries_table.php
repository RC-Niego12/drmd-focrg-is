<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wit_libraries', function (Blueprint $table): void {
            $table->id();
            $table->string('library_type')->index();
            $table->string('code')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('source')->default('system');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['library_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wit_libraries');
    }
};
