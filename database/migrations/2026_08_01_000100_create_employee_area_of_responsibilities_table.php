<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_area_of_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('level', 40);
            $table->string('psgc_code', 32);
            $table->string('name')->nullable();
            $table->string('parent_code', 32)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'level', 'psgc_code'], 'user_aor_level_code_unique');
            $table->index(['user_id', 'level']);
            $table->index(['level', 'psgc_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_area_of_responsibilities');
    }
};
