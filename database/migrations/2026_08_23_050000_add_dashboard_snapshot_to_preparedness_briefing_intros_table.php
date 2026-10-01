<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparedness_briefing_intros', function (Blueprint $table): void {
            $table->string('dashboard_snapshot_path')->nullable()->after('synopsis_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('preparedness_briefing_intros', function (Blueprint $table): void {
            $table->dropColumn('dashboard_snapshot_path');
        });
    }
};
