<?php

namespace Database\Seeders;

use App\Models\AssistanceRequest;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BakekangDromicReportSeeder extends Seeder
{
    public function run(): void
    {
        $incident = Incident::updateOrCreate(
            ['name' => 'Effects of Tropical Cyclone Bakekang', 'incident_date' => '2026-09-07'],
            ['summary' => 'Dummy regional tropical cyclone incident for testing DSWD DROMIC consolidation.']
        );

        $reviewer = User::where('email', 'drims@example.test')->first()
            ?? User::role('Super Admin')->first();
        $submittedAt = Carbon::parse('2026-09-09 08:30:00');

        foreach ($this->reports() as $index => $report) {
            $payload = [
                'incident_name' => 'Effects of Tropical Cyclone Bakekang',
                'occurrence_started_at' => '2026-09-07 14:00:00',
                'narrative' => "Tropical Cyclone Bakekang brought strong winds and heavy rainfall to {$report['municipality']}, {$report['province']}. The LGU conducted evacuation, rapid assessment, and relief operations in the affected barangays.",
                'area_rows' => $report['areas'],
                'evacuation_center_rows' => $report['centers'],
                'assistance_rows' => $report['assistance'],
                'response_action_rows' => [
                    ['acted_by_office' => 'LDRRMO', 'action_intervention' => 'Activated the operations center and coordinated evacuation and rapid damage assessment.'],
                    ['acted_by_office' => 'LSWDO', 'action_intervention' => 'Managed evacuation centers and distributed available LGU relief supplies.'],
                ],
            ];

            AssistanceRequest::withTrashed()->updateOrCreate(
                ['reference_number' => sprintf('LGU-BAKEKANG-2026-%02d', $index + 1)],
                [
                    'incident_id' => $incident->id,
                    'submission_type' => 'lgu_dromic_relief_request',
                    'requesting_agency' => $report['municipality'].' LGU',
                    'lgu' => $report['municipality'].' LGU',
                    'lgu_level' => str_contains($report['municipality'], 'City') ? 'CLGU' : 'MLGU',
                    'province' => $report['province'],
                    'municipality' => $report['municipality'],
                    'requester' => 'Dummy LGU DROMIC Focal Person',
                    'date_requested' => '2026-09-09',
                    'purpose' => 'Tropical Cyclone Bakekang situation report',
                    'status' => 'submitted',
                    'submitted_at' => $submittedAt->copy()->addMinutes($index * 5),
                    'lgu_report_status' => 'submitted',
                    'lgu_finalized_at' => $submittedAt->copy()->addMinutes($index * 5),
                    'lgu_submitted_to_dswd_at' => $submittedAt->copy()->addMinutes($index * 5),
                    // A shared incident_id groups these LGU reports into one regional
                    // incident without violating the report-series sequence index.
                    'lgu_dromic_series_key' => null,
                    'lgu_dromic_report_number' => 1,
                    'lgu_dromic_revision_number' => 0,
                    'lgu_dromic_report_classification' => 'regular',
                    'lgu_dromic_validation_status' => 'validated_no_findings',
                    'lgu_dromic_reviewed_by' => $reviewer?->id,
                    'lgu_dromic_reviewed_at' => $submittedAt->copy()->addMinutes($index * 5 + 2),
                    'lgu_dromic_review_note' => 'Automatically validated dummy report for DSWD DROMIC feature testing.',
                    'lgu_dromic_payload' => $payload,
                    'deleted_at' => null,
                ]
            );
        }
    }

    private function reports(): array
    {
        $makeCenter = fn (string $barangay, string $name, int $families, int $persons): array => [
            'barangay_origin' => $barangay, 'barangay_address' => $barangay,
            'evacuation_center' => $name, 'families_cum' => $families, 'families_now' => $families,
            'persons_cum' => $persons, 'persons_now' => $persons,
            'disaggregation_completed' => true,
            'disaggregation' => [
                'age_sex' => [
                    'infant' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 1, 'female_now' => 1],
                    'toddler' => ['male_cum' => 2, 'male_now' => 2, 'female_cum' => 2, 'female_now' => 2],
                    'pre_school' => ['male_cum' => 2, 'male_now' => 2, 'female_cum' => 2, 'female_now' => 2],
                    'school_age' => ['male_cum' => 4, 'male_now' => 4, 'female_cum' => 4, 'female_now' => 4],
                    'teenage' => ['male_cum' => 3, 'male_now' => 3, 'female_cum' => 3, 'female_now' => 3],
                    'adult' => ['male_cum' => max(0, intdiv($persons - 26, 2)), 'male_now' => max(0, intdiv($persons - 26, 2)), 'female_cum' => max(0, $persons - 26 - intdiv($persons - 26, 2)), 'female_now' => max(0, $persons - 26 - intdiv($persons - 26, 2))],
                    'elderly' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 1, 'female_now' => 1],
                ],
                'sectoral' => [
                    'pwds' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 1, 'female_now' => 1],
                    'child_headed_family' => ['male_cum' => 0, 'male_now' => 0, 'female_cum' => 0, 'female_now' => 0],
                    'single_headed_family' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 2, 'female_now' => 2],
                    'solo_parent' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 2, 'female_now' => 2],
                    'pregnant_women' => ['female_cum' => 1, 'female_now' => 1],
                    'lactating_mothers' => ['female_cum' => 1, 'female_now' => 1],
                    'four_ps' => ['male_cum' => 3, 'male_now' => 3, 'female_cum' => 4, 'female_now' => 4],
                    'indigenous_people' => ['male_cum' => 2, 'male_now' => 2, 'female_cum' => 2, 'female_now' => 2],
                ],
            ],
        ];
        $area = fn (string $barangay, int $families, int $persons, int $outsideFamilies, int $outsidePersons, int $totally, int $partially): array => [
            'area' => $barangay, 'affected_families' => $families, 'affected_persons' => $persons,
            'outside_ec_included' => true, 'outside_ec_families_cum' => $outsideFamilies,
            'outside_ec_families_now' => $outsideFamilies, 'outside_ec_persons_cum' => $outsidePersons,
            'outside_ec_persons_now' => $outsidePersons, 'damaged_houses_included' => true,
            'damaged_houses_totally' => $totally, 'damaged_houses_partially' => $partially,
        ];
        $aid = fn (string $item, int $quantity, float $cost): array => [
            'source' => 'City/Municipal LGU', 'item' => $item, 'quantity' => $quantity, 'cost_per_unit' => $cost,
        ];

        return [
            ['province' => 'Agusan del Norte', 'municipality' => 'Butuan City', 'areas' => [$area('Baan Riverside', 58, 232, 18, 72, 3, 9), $area('Los Angeles', 42, 168, 12, 48, 1, 6)], 'centers' => [$makeCenter('Baan Riverside', 'Baan Elementary School', 20, 80), $makeCenter('Los Angeles', 'Los Angeles Barangay Hall', 15, 60)], 'assistance' => [$aid('Family Food Packs', 100, 850), $aid('Hygiene Kits', 35, 620)]],
            ['province' => 'Agusan del Norte', 'municipality' => 'Cabadbaran City', 'areas' => [$area('Calibunan', 36, 144, 8, 32, 2, 5), $area('Katugasan', 28, 112, 6, 24, 1, 4)], 'centers' => [$makeCenter('Calibunan', 'Calibunan Covered Court', 14, 56), $makeCenter('Katugasan', 'Katugasan Elementary School', 10, 40)], 'assistance' => [$aid('Family Food Packs', 64, 850), $aid('Sleeping Kits', 24, 780)]],
            ['province' => 'Agusan del Sur', 'municipality' => 'Bayugan City', 'areas' => [$area('Marcelina', 75, 300, 20, 80, 4, 12), $area('Taglatawan', 55, 220, 15, 60, 2, 8)], 'centers' => [$makeCenter('Marcelina', 'Marcelina Elementary School', 28, 112), $makeCenter('Taglatawan', 'Taglatawan Gymnasium', 18, 72)], 'assistance' => [$aid('Family Food Packs', 130, 850), $aid('Water Containers', 46, 410)]],
            ['province' => 'Surigao del Norte', 'municipality' => 'Surigao City', 'areas' => [$area('Washington', 92, 368, 25, 100, 5, 14), $area('Taft', 68, 272, 18, 72, 3, 10)], 'centers' => [$makeCenter('Washington', 'Surigao City National High School', 34, 136), $makeCenter('Taft', 'Taft Barangay Gym', 22, 88)], 'assistance' => [$aid('Family Food Packs', 160, 850), $aid('Kitchen Kits', 56, 1200)]],
            ['province' => 'Surigao del Sur', 'municipality' => 'Tandag City', 'areas' => [$area('Telaje', 48, 192, 12, 48, 2, 7), $area('San Agustin Norte', 34, 136, 9, 36, 1, 5)], 'centers' => [$makeCenter('Telaje', 'Telaje Elementary School', 18, 72), $makeCenter('San Agustin Norte', 'San Agustin Norte Gym', 11, 44)], 'assistance' => [$aid('Family Food Packs', 82, 850), $aid('Family Kits', 29, 950)]],
            ['province' => 'Dinagat Islands', 'municipality' => 'Dinagat', 'areas' => [$area('White Beach', 31, 124, 7, 28, 2, 4), $area('Cayetano', 25, 100, 6, 24, 1, 3)], 'centers' => [$makeCenter('White Beach', 'White Beach Evacuation Center', 12, 48), $makeCenter('Cayetano', 'Cayetano Multi-Purpose Hall', 8, 32)], 'assistance' => [$aid('Family Food Packs', 56, 850), $aid('Laminated Sacks', 20, 180)]],
        ];
    }
}
