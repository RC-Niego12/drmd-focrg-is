<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('parent_code', 20)->nullable()->index();
            $table->string('level', 40)->index();
            $table->string('name');
            $table->string('short_name')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('district')->nullable();
            $table->string('zip_code')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('source')->default('psgc.cloud');
            $table->string('source_version')->nullable();
            $table->string('sync_batch')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['level', 'parent_code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_addresses');
    }
};
