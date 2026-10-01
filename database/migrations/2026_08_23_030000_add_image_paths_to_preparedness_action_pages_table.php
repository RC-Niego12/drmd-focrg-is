<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_action_pages', function (Blueprint $table): void {
            $table->json('image_paths')->nullable()->after('image_path');
        });

        DB::table('preparedness_action_pages')->orderBy('id')->eachById(function ($page): void {
            DB::table('preparedness_action_pages')->where('id', $page->id)->update([
                'image_paths' => json_encode([$page->image_path, null, null]),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('preparedness_action_pages', function (Blueprint $table): void {
            $table->dropColumn('image_paths');
        });
    }
};
