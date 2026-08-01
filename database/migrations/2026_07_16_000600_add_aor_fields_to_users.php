<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('aor_provinces')->nullable()->after('lgu_name');
            $table->json('aor_districts')->nullable()->after('aor_provinces');
            $table->json('aor_cities_municipalities')->nullable()->after('aor_districts');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['aor_provinces', 'aor_districts', 'aor_cities_municipalities']));
    }
};
