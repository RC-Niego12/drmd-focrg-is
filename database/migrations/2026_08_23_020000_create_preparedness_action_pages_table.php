<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparedness_action_pages', function (Blueprint $table): void {
            $table->id();
            $table->json('actions');
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach ([0, 1] as $sortOrder) {
            DB::table('preparedness_action_pages')->insert([
                'actions' => json_encode(['', '', '']),
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preparedness_action_pages');
    }
};
