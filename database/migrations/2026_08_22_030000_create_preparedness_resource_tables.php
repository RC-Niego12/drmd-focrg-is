<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparedness_response_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->unique();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('status')->default('On Standby');
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('preparedness_qrt_coverage_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('area')->unique();
            $table->string('coverage_type')->default('Provincial / City / Municipal');
            $table->unsignedInteger('members')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('preparedness_qrt_specializations', function (Blueprint $table): void {
            $table->id();
            $table->string('specialization')->unique();
            $table->unsignedInteger('members')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::table('preparedness_response_assets')->insert([
            ['label' => 'Mobile Command Center (MCC) and ICT Equipment', 'quantity' => 1, 'status' => 'On Standby', 'image_path' => 'mobile-command-center.png', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Mobile Kitchen', 'quantity' => 1, 'status' => 'On Standby', 'image_path' => 'mobile-kitchen.png', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Wing Van', 'quantity' => 1, 'status' => 'On Standby', 'image_path' => 'wing-van.png', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'RP Vehicles', 'quantity' => 3, 'status' => 'On Standby', 'image_path' => null, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Forklift', 'quantity' => 1, 'status' => 'On Standby', 'image_path' => 'forklift.png', 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Starlinks', 'quantity' => 2, 'status' => 'On Standby', 'image_path' => 'starlink.png', 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('preparedness_qrt_coverage_areas')->insert([
            ['area' => 'Regional QRT / Field Office', 'coverage_type' => 'Regional', 'members' => 198, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['area' => 'Agusan del Norte', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 290, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['area' => 'Agusan del Sur', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 250, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['area' => 'Province of Dinagat Islands', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 40, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['area' => 'Surigao del Norte', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 218, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['area' => 'Surigao del Sur', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 306, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('preparedness_qrt_specializations')->insert([
            ['specialization' => 'CCCM and IDPP', 'members' => 133, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'PEA / Mental Health and Psychosocial Support (MHPSS)', 'members' => 25, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'DROMIC Reporting / Mobile Command Center (MCC)', 'members' => 45, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'Child and Women Friendly Space (C/WFS)', 'members' => 67, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'Humanitarian Supply Chain Management (HSCM)', 'members' => 32, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'Basic Incident Command System (ICS)', 'members' => 125, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
            ['specialization' => 'Mobile Kitchen', 'members' => 30, 'sort_order' => 7, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('preparedness_qrt_specializations');
        Schema::dropIfExists('preparedness_qrt_coverage_areas');
        Schema::dropIfExists('preparedness_response_assets');
    }
};
