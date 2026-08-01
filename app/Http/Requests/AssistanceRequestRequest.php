<?php

namespace App\Http\Requests;

use App\Support\DocumentReferenceNumber;
use App\Support\AssessmentNarrative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistanceRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $meta = (array) $this->input('assessment_form_data', []);
        $parts = [
            'prefix' => (string) ($meta['assessment_drn_prefix'] ?? ''),
            'year' => (string) ($meta['assessment_drn_year'] ?? ''),
            'month' => (string) ($meta['assessment_drn_month'] ?? ''),
            'specified' => (string) ($meta['assessment_drn_specified'] ?? ''),
        ];
        $purpose = (string) ($meta['response_purpose'] ?? $this->input('purpose'));
        if ($purpose === 'Preparedness for Response') {
            $meta['request_type'] = null;
            $this->merge([
                'incident_name' => null,
                'incident_date' => null,
                'incident_details' => null,
            ]);
        } elseif ($purpose === 'Relief Augmentation') {
            $meta['request_type'] = 'Disaster';
        }
        $this->merge([
            'assessment_form_data' => $meta,
            'assessment_drn' => DocumentReferenceNumber::compose(...$parts),
            'recommendations' => AssessmentNarrative::sanitize($this->input('recommendations')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('encode requests') ?? false;
    }

    public function rules(): array
    {
        return [
            'request_party_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => blank($this->route('assistanceRequest')?->source_lgu_dromic_request_id)),
                'exists:request_parties,id',
            ],
            'requesting_agency' => ['required', 'string', 'max:255'],
            'lgu' => ['nullable', 'string', 'max:255'],
            'lgu_level' => ['nullable', 'string', 'max:50'],
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'requester' => ['nullable', 'string', 'max:255'],
            'date_requested' => ['required', 'date'],
            'incident_name' => ['nullable', 'required_if:assessment_form_data.request_type,Disaster', 'string', 'max:255'],
            'incident_date' => ['nullable', 'required_if:assessment_form_data.request_type,Disaster', 'date'],
            'assessment_type_id' => ['nullable', 'exists:assessment_types,id'],
            'purpose' => ['required', 'in:Relief Augmentation,Preparedness for Response'],
            'assessment_summary' => ['nullable', 'string'],
            'assessment_form_data' => ['required', 'array'],
            'assessment_form_data.request_type' => ['nullable', 'in:Disaster'],
            'assessment_form_data.response_purpose' => ['required', 'in:Relief Augmentation,Preparedness for Response'],
            'assessment_form_data.affected_areas' => ['nullable', 'array', 'max:100'],
            'assessment_form_data.affected_areas.*' => ['string', 'max:255'],
            'assessment_form_data.information_source' => ['nullable', 'required_if:assessment_form_data.request_type,Disaster', 'string', 'max:255'],
            'assessment_form_data.information_date' => ['nullable', 'required_if:assessment_form_data.request_type,Disaster', 'date'],
            'assessment_form_data.families_served' => ['nullable', 'integer', 'min:0'],
            'assessment_form_data.has_previous_augmentation' => ['required', 'boolean'],
            'assessment_form_data.previous_augmentations' => ['nullable', 'array'],
            'assessment_form_data.previous_augmentations.*.unit' => ['nullable', 'string', 'max:50'],
            'assessment_form_data.previous_augmentations.*.description' => ['nullable', 'string', 'max:255'],
            'assessment_form_data.previous_augmentations.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'assessment_form_data.previous_augmentations.*.remarks' => ['nullable', 'string', 'max:1000'],
            'assessment_form_data.delivery_batches' => ['nullable', 'array', 'max:5'],
            'assessment_form_data.delivery_batches.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'assessment_form_data.delivery_batches.*.date' => ['nullable', 'date'],
            'assessment_form_data.delivery_batches.*.available' => ['nullable', 'in:YES,NO'],
            'assessment_form_data.delivery_batches.*.details' => ['nullable', 'string', 'max:1000'],
            'assessment_form_data.provide_augmentation' => ['required', 'boolean'],
            'assessment_form_data.prepared_by' => ['required', 'string', 'max:255'],
            'assessment_form_data.prepared_by_position' => ['nullable', 'string', 'max:255'],
            'assessment_form_data.prepared_by_designation' => ['nullable', 'string', 'max:255'],
            'assessment_form_data.prepared_at' => ['required', 'date'],
            'assessment_form_data.reviewed_by' => ['required', 'string', 'max:500'],
            'assessment_form_data.approved_by' => ['required', 'string', 'max:500'],
            'assessment_form_data.assessment_drn_prefix' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
            'assessment_form_data.assessment_drn_year' => ['required', 'digits:2'],
            'assessment_form_data.assessment_drn_month' => ['required', 'date_format:m'],
            'assessment_form_data.assessment_drn_specified' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
            'recommendations' => ['required', 'string', 'min:20'],
            'remarks' => ['nullable', 'string'],
            'date_received_by_drmd' => ['required', 'date'], 'request_drn' => ['nullable', 'string', 'max:255'],
            'office_agency_details' => ['nullable', 'string', 'max:255'], 'endorsed_to_drrs' => ['boolean'], 'date_endorsed_to_drrs' => ['nullable', 'date'],
            'incident_details' => ['nullable', 'string', 'max:255'], 'incident_count' => ['nullable', 'integer', 'min:1'],
            'response_drn' => ['nullable', 'string', 'max:255'], 'assessment_drn' => ['required', 'string', 'max:255'],
            'source_document_url' => ['nullable', 'string', 'max:2048'], 'response_letter_url' => ['nullable', 'string', 'max:2048'],
            'coordinated_with_rros' => ['boolean'], 'date_coordinated_with_rros' => ['nullable', 'date'],
            'requester_position' => ['nullable', 'string', 'max:255'], 'requester_address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:100'], 'affected_families' => ['nullable', 'required_if:assessment_form_data.request_type,Disaster', 'integer', 'min:1'],
            'assigned_social_worker' => ['required', 'string', 'max:255'],
            'act_on_behalf' => ['nullable', 'boolean'],
            'on_behalf_reason' => ['nullable', 'required_if:act_on_behalf,true', 'string', 'min:8', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'items.*.fni_library_item_id' => ['required', 'exists:fni_library_items,id'],
            'items.*.source_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.requested_quantity' => ['required', 'integer', 'min:1'],
            'items.*.available_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.priority' => ['required', 'in:low,normal,high,urgent'],
            'items.*.remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $meta = (array) $this->input('assessment_form_data', []);
            if (! filled($meta['prepared_by_position'] ?? null) && ! filled($meta['prepared_by_designation'] ?? null)) {
                $validator->errors()->add('assessment_form_data.prepared_by_position', 'Your Position or Designation must be configured in User Access before submission.');
            }
            if (($meta['has_previous_augmentation'] ?? null) === true || ($meta['has_previous_augmentation'] ?? null) === '1') {
                $rows = collect($meta['previous_augmentations'] ?? [])->filter(fn ($row) => collect($row)->contains(fn ($value) => filled($value)));
                if ($rows->isEmpty()) {
                    $validator->errors()->add('assessment_form_data.previous_augmentations', 'Enter at least one previous augmentation when YES is selected.');
                }
                foreach (($meta['previous_augmentations'] ?? []) as $index => $row) {
                    if (! collect($row)->contains(fn ($value) => filled($value))) continue;
                    foreach (['unit', 'description', 'quantity'] as $field) {
                        if (! filled($row[$field] ?? null)) $validator->errors()->add("assessment_form_data.previous_augmentations.{$index}.{$field}", 'Complete the Unit, Description, and Quantity for each previous augmentation row that you start.');
                    }
                }
            }
            foreach (($meta['delivery_batches'] ?? []) as $index => $batch) {
                if (! collect($batch)->contains(fn ($value) => filled($value))) continue;
                foreach (['quantity', 'date', 'available', 'details'] as $field) {
                    if (! filled($batch[$field] ?? null)) $validator->errors()->add("assessment_form_data.delivery_batches.{$index}.{$field}", 'Complete all fields for each delivery batch that you start.');
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'request_party_id' => 'Requesting Party',
            'date_received_by_drmd' => 'Date Received by DRMD',
            'date_requested' => 'Date of Request',
            'incident_name' => 'Type of Disaster',
            'incident_date' => 'Date of Disaster Occurrence',
            'affected_families' => 'Actual Affected Families',
            'purpose' => 'Purpose of Transaction',
            'assessment_form_data.response_purpose' => 'Purpose of Transaction',
            'assessment_form_data.affected_areas' => 'Affected Areas',
            'assessment_form_data.information_source' => 'Source of Information',
            'assessment_form_data.information_date' => 'Date of Information',
            'assessment_form_data.reviewed_by' => 'Reviewed By',
            'assessment_form_data.approved_by' => 'Approved By',
            'assessment_form_data.assessment_drn_prefix' => 'Assessment DRN Prefix',
            'assessment_form_data.assessment_drn_year' => 'Assessment DRN Year',
            'assessment_form_data.assessment_drn_month' => 'Assessment DRN Month',
            'assessment_form_data.assessment_drn_specified' => 'Specified Assessment DRN',
            'recommendations' => 'Assessment Narrative',
            'act_on_behalf' => 'Act on behalf',
            'on_behalf_reason' => 'Reason for acting on behalf',
            'items.*.fni_library_item_id' => 'FNI Description',
            'items.*.requested_quantity' => 'Requested Quantity',
            'items.*.unit' => 'Unit of Measurement',
        ];
    }
}
