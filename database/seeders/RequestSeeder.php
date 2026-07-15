<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\AssessmentType;
use App\Models\AssistanceRequest;
use App\Models\Incident;
use App\Models\RequestParty;
use App\Models\User;
use Illuminate\Database\Seeder;

class RequestSeeder extends Seeder
{
    public function run(): void
    {
        $drmdAa = User::where('email', 'drmd-aa@example.test')->firstOrFail();
        $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
        $rros = User::where('email', 'rros@example.test')->firstOrFail();

        $reliefType = AssessmentType::where('name', 'Relief Augmentation')->first();
        $preparednessType = AssessmentType::where('name', 'Preparedness for Response')->first();

        $butuan = RequestParty::firstOrCreate(
            ['directory_key' => 'seed-butuan-cswdo'],
            [
                'requesting_party' => 'City Government of Butuan',
                'office_agency_details' => 'City Social Welfare and Development Office',
                'lgu_level' => 'CLGU',
                'office_head' => 'Hon. Maria Santos',
                'source' => 'seeder',
                'is_active' => true,
            ]
        );

        $surigao = RequestParty::firstOrCreate(
            ['directory_key' => 'seed-surigao-pswdo'],
            [
                'requesting_party' => 'Provincial Government of Surigao del Norte',
                'office_agency_details' => 'Provincial Social Welfare and Development Office',
                'lgu_level' => 'PLGU',
                'office_head' => 'Hon. Juan Dela Cruz',
                'source' => 'seeder',
                'is_active' => true,
            ]
        );

        $cabadbaran = RequestParty::firstOrCreate(
            ['directory_key' => 'seed-cabadbaran-mswdo'],
            [
                'requesting_party' => 'City Government of Cabadbaran',
                'office_agency_details' => 'City Social Welfare and Development Office',
                'lgu_level' => 'CLGU',
                'office_head' => 'Hon. Ana Reyes',
                'source' => 'seeder',
                'is_active' => true,
            ]
        );

        $typhoon = Incident::firstOrCreate(
            ['name' => 'Effects of Tropical Cyclone', 'incident_date' => '2026-07-08'],
            [
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'BUTUAN CITY',
                'summary' => 'Seeded tropical cyclone incident for demo requests.',
            ]
        );

        $flood = Incident::firstOrCreate(
            ['name' => 'Flooding Incident', 'incident_date' => '2026-07-05'],
            [
                'province' => 'SURIGAO DEL NORTE',
                'municipality' => 'SURIGAO CITY',
                'summary' => 'Seeded flooding incident for demo requests.',
            ]
        );

        $fire = Incident::firstOrCreate(
            ['name' => 'Fire Incident', 'incident_date' => '2026-07-12'],
            [
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'CABADBARAN CITY',
                'summary' => 'Seeded fire incident for demo requests.',
            ]
        );

        $scenarios = [
            [
                'reference_number' => 'REQ-20260710-SEED01',
                'submission_type' => 'fni_request',
                'party' => $butuan,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'BUTUAN CITY',
                'date_received_by_drmd' => '2026-07-10',
                'date_requested' => '2026-07-09',
                'request_drn' => 'DRN-SEED-001',
                'purpose' => 'FNI request document intake',
                'remarks' => 'Awaiting DRRS assessment.',
                'status' => 'endorsed',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-10',
                'encoded_by' => $drmdAa->id,
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 500, 'unit' => 'box'],
                ],
            ],
            [
                'reference_number' => 'PROP-20260711-SEED02',
                'submission_type' => 'proposal',
                'proposal_type' => 'FFT/W',
                'party' => $surigao,
                'province' => 'SURIGAO DEL NORTE',
                'municipality' => 'SURIGAO CITY',
                'date_received_by_drmd' => '2026-07-11',
                'date_requested' => '2026-07-11',
                'request_drn' => 'DRN-SEED-002',
                'purpose' => 'FFT/W proposal intake',
                'remarks' => 'Proposal endorsed for assessment.',
                'status' => 'endorsed',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-11',
                'encoded_by' => $drmdAa->id,
                'items' => [
                    ['item_name' => 'Hygiene Kit', 'requested_quantity' => 200, 'unit' => 'kit'],
                    ['item_name' => 'Sleeping Kit', 'requested_quantity' => 150, 'unit' => 'kit'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260708-SEED03',
                'submission_type' => 'fni_request',
                'party' => $butuan,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'BUTUAN CITY',
                'date_received_by_drmd' => '2026-07-08',
                'date_requested' => '2026-07-08',
                'request_drn' => 'DRN-SEED-003',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $typhoon->id,
                'incident_details' => 'TD Crising',
                'incident_count' => 1,
                'remarks' => 'Assessment draft in progress.',
                'status' => 'under_review',
                'assessment_status' => 'draft',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-08',
                'encoded_by' => $drrs->id,
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 1000, 'unit' => 'box'],
                    ['item_name' => 'Bottled Water', 'requested_quantity' => 500, 'unit' => 'case'],
                    ['item_name' => 'Malong', 'requested_quantity' => 300, 'unit' => 'pc'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260705-SEED04',
                'submission_type' => 'fni_request',
                'party' => $surigao,
                'province' => 'SURIGAO DEL NORTE',
                'municipality' => 'SURIGAO CITY',
                'date_received_by_drmd' => '2026-07-05',
                'date_requested' => '2026-07-05',
                'request_drn' => 'DRN-SEED-004',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $flood->id,
                'incident_details' => 'River overflow',
                'incident_count' => 1,
                'remarks' => 'Assessment signed and acted.',
                'status' => 'acted',
                'assessment_status' => 'final',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-05',
                'encoded_by' => $drrs->id,
                'completed_at' => now()->subDays(2),
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 800, 'unit' => 'box', 'approved_quantity' => 800],
                ],
            ],
            [
                'reference_number' => 'REQ-20260706-SEED05',
                'submission_type' => 'fni_request',
                'party' => $cabadbaran,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'CABADBARAN CITY',
                'date_received_by_drmd' => '2026-07-06',
                'date_requested' => '2026-07-06',
                'request_drn' => 'DRN-SEED-005',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $fire->id,
                'incident_details' => 'Residential fire',
                'incident_count' => 1,
                'remarks' => 'Submitted to RROS for decision.',
                'status' => 'submitted',
                'assessment_status' => 'submitted',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-06',
                'encoded_by' => $drrs->id,
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 120, 'unit' => 'box'],
                    ['item_name' => 'Kitchen Kit', 'requested_quantity' => 40, 'unit' => 'kit'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260703-SEED06',
                'submission_type' => 'fni_request',
                'party' => $butuan,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'BUTUAN CITY',
                'date_received_by_drmd' => '2026-07-03',
                'date_requested' => '2026-07-03',
                'request_drn' => 'DRN-SEED-006',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $typhoon->id,
                'incident_details' => 'TD Ada',
                'remarks' => 'Fully approved by RROS.',
                'status' => 'approved',
                'assessment_status' => 'submitted',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-03',
                'encoded_by' => $drrs->id,
                'completed_at' => now()->subDays(5),
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'approval' => ['decision' => 'approved', 'remarks' => 'Approved in full.', 'approved_by' => $rros->id],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 600, 'unit' => 'box', 'approved_quantity' => 600, 'status' => 'approved'],
                    ['item_name' => 'Hygiene Kit', 'requested_quantity' => 100, 'unit' => 'kit', 'approved_quantity' => 100, 'status' => 'approved'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260702-SEED07',
                'submission_type' => 'fni_request',
                'party' => $surigao,
                'province' => 'SURIGAO DEL NORTE',
                'municipality' => 'SURIGAO CITY',
                'date_received_by_drmd' => '2026-07-02',
                'date_requested' => '2026-07-02',
                'request_drn' => 'DRN-SEED-007',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $flood->id,
                'incident_details' => 'Flash flood pockets',
                'remarks' => 'Partial approval — adjust quantities for release.',
                'status' => 'partially_approved',
                'assessment_status' => 'submitted',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-02',
                'encoded_by' => $drrs->id,
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'approval' => ['decision' => 'partially_approved', 'remarks' => 'Approved with reduced quantities.', 'approved_by' => $rros->id],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 1000, 'unit' => 'box', 'approved_quantity' => 700, 'status' => 'approved'],
                    ['item_name' => 'Tarpaulin', 'requested_quantity' => 200, 'unit' => 'pc', 'approved_quantity' => 0, 'status' => 'rejected'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260701-SEED08',
                'submission_type' => 'proposal',
                'proposal_type' => 'NFFT/W',
                'party' => $cabadbaran,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'CABADBARAN CITY',
                'date_received_by_drmd' => '2026-07-01',
                'date_requested' => '2026-07-01',
                'request_drn' => 'DRN-SEED-008',
                'purpose' => 'Preparedness for Response',
                'assessment_type_id' => $preparednessType?->id,
                'remarks' => 'Rejected — insufficient justification.',
                'status' => 'rejected',
                'assessment_status' => 'submitted',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-07-01',
                'encoded_by' => $drrs->id,
                'assessment_form_data' => [
                    'response_purpose' => 'Preparedness for Response',
                    'request_type' => 'Preparedness',
                ],
                'approval' => ['decision' => 'rejected', 'remarks' => 'Insufficient supporting documents.', 'approved_by' => $rros->id],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 50, 'unit' => 'box', 'approved_quantity' => 0, 'status' => 'rejected'],
                ],
            ],
            [
                'reference_number' => 'REQ-20260628-SEED09',
                'submission_type' => 'fni_request',
                'party' => $butuan,
                'province' => 'AGUSAN DEL NORTE',
                'municipality' => 'BUTUAN CITY',
                'date_received_by_drmd' => '2026-06-28',
                'date_requested' => '2026-06-28',
                'request_drn' => 'DRN-SEED-009',
                'purpose' => 'Preparedness for Response',
                'assessment_type_id' => $preparednessType?->id,
                'remarks' => 'Prepositioning support for rainy season.',
                'status' => 'acted',
                'assessment_status' => 'final',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-06-28',
                'encoded_by' => $drrs->id,
                'assessment_form_data' => [
                    'response_purpose' => 'Preparedness for Response',
                    'request_type' => 'Preparedness',
                ],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 300, 'unit' => 'box', 'approved_quantity' => 300],
                    ['item_name' => 'Collapsible Water Container', 'requested_quantity' => 100, 'unit' => 'pc', 'approved_quantity' => 100],
                ],
            ],
            [
                'reference_number' => 'REQ-20260625-SEED10',
                'submission_type' => 'fni_request',
                'party' => $surigao,
                'province' => 'SURIGAO DEL NORTE',
                'municipality' => 'SURIGAO CITY',
                'date_received_by_drmd' => '2026-06-25',
                'date_requested' => '2026-06-25',
                'request_drn' => 'DRN-SEED-010',
                'purpose' => 'Relief Augmentation',
                'assessment_type_id' => $reliefType?->id,
                'incident_id' => $flood->id,
                'incident_details' => 'Coastal flooding',
                'remarks' => 'Goods released to LGU.',
                'status' => 'released',
                'assessment_status' => 'final',
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => '2026-06-25',
                'encoded_by' => $drrs->id,
                'completed_at' => now()->subDays(10),
                'assessment_form_data' => [
                    'response_purpose' => 'Relief Augmentation',
                    'request_type' => 'Disaster',
                ],
                'items' => [
                    ['item_name' => 'Family Food Pack', 'requested_quantity' => 450, 'unit' => 'box', 'approved_quantity' => 450, 'status' => 'approved'],
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $party = $scenario['party'];
            $items = $scenario['items'] ?? [];
            $approval = $scenario['approval'] ?? null;
            unset($scenario['party'], $scenario['items'], $scenario['approval']);

            $request = AssistanceRequest::updateOrCreate(
                ['reference_number' => $scenario['reference_number']],
                array_merge([
                    'request_party_id' => $party->id,
                    'requesting_agency' => $party->requesting_party,
                    'office_agency_details' => $party->office_agency_details,
                    'lgu_level' => $party->lgu_level,
                    'lgu' => $party->requesting_party,
                    'requester' => $party->office_head ?? 'Seeded Requester',
                    'requester_position' => 'Local Chief Executive',
                    'submitted_at' => now()->subDays(1),
                ], $scenario)
            );

            $request->items()->delete();
            foreach ($items as $item) {
                $request->items()->create(array_merge([
                    'priority' => 'normal',
                    'status' => 'pending',
                ], $item));
            }

            if ($approval) {
                Approval::updateOrCreate(
                    [
                        'request_id' => $request->id,
                        'decision' => $approval['decision'],
                    ],
                    [
                        'approved_by' => $approval['approved_by'],
                        'remarks' => $approval['remarks'],
                        'decided_at' => now()->subDays(3),
                    ]
                );
            }
        }
    }
}
