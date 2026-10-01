<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_action_pages', function (Blueprint $table): void {
            $table->json('captions')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('preparedness_action_pages', function (Blueprint $table): void {
            $table->dropColumn('captions');
        });
    }
};
