<?php

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\User;
use App\Services\WorkflowNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function workflowRequest(array $attributes = []): AssistanceRequest
{
    $encoder = User::where('email', 'drmd-aa@example.test')->firstOrFail();

    return AssistanceRequest::create([
        'reference_number' => 'REQ-WORKFLOW-001',
        'submission_type' => 'fni_request',
        'encoded_by' => $encoder->id,
        'requesting_agency' => 'Test LGU',
        'requester' => 'Test Requester',
        'date_requested' => now()->toDateString(),
        'purpose' => 'Relief Augmentation',
        'endorsed_to_drrs' => true,
        'date_endorsed_to_drrs' => now()->toDateString(),
        'status' => 'endorsed',
        'submitted_at' => now(),
        ...$attributes,
    ]);
}

it('notifies DRRS when DRMD AA endorses a request and marks the notification acted after assessment starts', function (): void {
    $this->seed(DatabaseSeeder::class);
    $request = workflowRequest();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();

    app(WorkflowNotificationService::class)->notifyDrmdAaEndorsed($request);

    $this->actingAs($drrs)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('action_required_count', 1)
        ->assertJsonPath('notifications.0.action_key', 'drrs_assessment_required')
        ->assertJsonPath('notifications.0.acted', false);

    $request->update(['status' => 'under_review', 'assessment_status' => 'draft']);

    $this->actingAs($drrs)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('action_required_count', 0)
        ->assertJsonPath('notifications.0.acted', true);
});

it('notifies RROS, DRIMS, and originators across the request workflow', function (): void {
    $this->seed(DatabaseSeeder::class);
    $request = workflowRequest(['status' => 'submitted', 'assessment_status' => 'submitted']);
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();
    $encoder = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $service = app(WorkflowNotificationService::class);

    $service->notifyDrrsAssessmentSubmitted($request);
    expect($rros->fresh()->notifications()->first()->data['action_key'])->toBe('rros_decision_required');

    $request->update(['status' => 'approved']);
    $service->notifyRrosDecisionRecorded($request->fresh(['encoder']));

    expect($drims->fresh()->notifications()->first()->data['action_key'])->toBe('drims_dromic_required')
        ->and($encoder->fresh()->notifications()->first()->data['action_key'])->toBe('rros_decision_recorded');

    DromicReport::create([
        'report_number' => 'DROMIC-WORKFLOW-001',
        'request_id' => $request->id,
        'affected_lgu' => 'Test LGU',
        'date_released' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $drims->id,
    ]);
    $service->notifyDromicCreated($request->fresh(['encoder']));

    $this->actingAs($drims)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('notifications.0.acted', true);

    expect($encoder->fresh()->notifications->contains(fn ($notification): bool => $notification->data['action_key'] === 'drims_dromic_created'))->toBeTrue();
});
