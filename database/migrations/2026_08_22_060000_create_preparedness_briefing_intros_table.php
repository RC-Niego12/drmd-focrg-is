<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparedness_briefing_intros', function (Blueprint $table): void {
            $table->id();
            $table->json('content');
            $table->string('synopsis_image_path')->nullable();
            $table->timestamps();
        });

        DB::table('preparedness_briefing_intros')->insert([
            'content' => json_encode([
                'title_heading' => 'DSWD FIELD OFFICE CARAGA PREPAREDNESS FOR RESPONSE',
                'title_event' => 'ICOW Southwest Monsoon and Emerging Tropical Cyclone-Like Vortices',
                'title_as_of' => 'as of 21 August 2026, 09:00 AM',
                'synopsis_title' => 'SYNOPSIS:',
                'synopsis_image_label' => 'DOST PAGASA HIMAWARI-8 IR1',
                'synopsis_body' => 'As of 3:00 AM today, the monitored weather disturbance remains outside the Philippine Area of Responsibility (PAR). Areas likely to be affected in the region may experience partly cloudy to cloudy skies with isolated rainshowers or thunderstorms. Possible flash floods or landslides may occur during severe thunderstorms.',
                'synopsis_issued' => 'Issued at 4:00 AM, 21 August 2026',
                'synopsis_source' => 'Source: PAGASA official website',
                'synopsis_url' => 'https://www.pagasa.dost.gov.ph/',
                'forecast_title' => 'LOCAL FORECAST WEATHER CONDITIONS (Provincial)',
                'forecast_as_of' => 'as of 21 August 2026',
                'forecast_weather' => 'Generally, the region will experience partly cloudy to cloudy skies with isolated rainshowers or thunderstorms with possible flash floods or landslides during severe thunderstorms as impacts.',
                'forecast_signal' => 'No signal # issued',
                'forecast_remarks' => 'As of this reporting, there are no affected or displaced families and individuals in the region.',
                'divider_title' => 'RESOURCE CAPACITY',
                'divider_as_of' => 'AS OF 21 AUGUST 2026, 9:00 PM',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('preparedness_briefing_intros');
    }
};
