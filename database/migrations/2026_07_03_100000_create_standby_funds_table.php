<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standby_funds', function (Blueprint $table): void {
            $table->id();
            $table->string('office')->default('DSWD Field Office Caraga');
            $table->decimal('amount', 15, 2)->default(3000000);
            $table->string('source')->nullable();
            $table->string('google_sheet_url')->nullable();
            $table->string('cell_reference')->default('L2');
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standby_funds');
    }
};
