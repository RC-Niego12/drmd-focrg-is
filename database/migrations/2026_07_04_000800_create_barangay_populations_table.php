<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangay_populations', function (Blueprint $table): void {
            $table->id();
            $table->string('barangay_psgc_code', 20);
            $table->unsignedInteger('population')->nullable();
            $table->unsignedInteger('census_year')->nullable();
            $table->unsignedInteger('poor_families')->nullable();
            $table->unsignedInteger('poor_individuals')->nullable();
            $table->string('source')->nullable();
            $table->date('last_updated')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['barangay_psgc_code', 'census_year']);
            $table->index(['census_year', 'population']);
            $table->foreign('barangay_psgc_code')
                ->references('code')
                ->on('psgc_addresses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangay_populations');
    }
};
