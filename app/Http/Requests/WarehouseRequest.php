<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA'])
            || $user->can('manage warehouses');
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'external_warehouse_id' => ['nullable', 'string', 'max:255'],
            'warehouse_number' => ['nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'barangay_name' => ['nullable', 'string', 'max:255'],
            'barangay_code' => ['nullable', 'string', 'max:255'],
            'rtef_capacity' => ['nullable', 'numeric', 'min:0'],
            'ffp_capacity' => ['nullable', 'numeric', 'min:0'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'distribution_network' => ['nullable', 'string', 'max:255'],
            'warehouse_type' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'ownership' => ['nullable', 'string', 'max:255'],
            'partnership' => ['nullable', 'string', 'max:255'],
            'longitude' => ['nullable', 'numeric'],
            'latitude' => ['nullable', 'numeric'],
            'rpa_start_date' => ['nullable', 'date'],
            'rpa_end_date' => ['nullable', 'date'],
            'validity' => ['nullable', 'string', 'max:255'],
            'designated_storekeepers' => ['nullable', 'string'],
            'storekeeper_contact_number' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,inactive,archived'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('warehouse_type')) {
            return;
        }

        $data = [
            'distribution_network' => $this->isPrepositioningArea($this->input('warehouse_type'))
                ? 'Last Mile'
                : 'Spokes',
        ];

        $category = $this->normalize($this->input('category'));

        if (in_array($category, ['owned', 'rented'], true)) {
            $data['ownership'] = ucfirst($category);
            $data['partnership'] = ucfirst($category);
        }

        $this->merge($data);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $prepositioning = $this->isPrepositioningArea($this->input('warehouse_type'));
            $ownedOrRented = ['owned', 'rented'];

            foreach (['category', 'ownership', 'partnership'] as $field) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, ucfirst(str_replace('_', ' ', $field)).' is required. Please select a value before saving.');

                    continue;
                }

                $value = $this->normalize($this->input($field));

                if ($prepositioning && in_array($value, $ownedOrRented, true)) {
                    $validator->errors()->add($field, ucfirst(str_replace('_', ' ', $field)).' cannot be Owned or Rented when Warehouse Type is Prepositioning Area. Please change the Warehouse Type or choose another value.');
                }

                if (! $prepositioning && ! in_array($value, $ownedOrRented, true)) {
                    $validator->errors()->add($field, ucfirst(str_replace('_', ' ', $field)).' must be Owned or Rented for Regional or Satellite warehouses.');
                }
            }

            $expectedNetwork = $prepositioning ? 'lastmile' : 'spokes';

            if ($this->normalize($this->input('distribution_network')) !== $expectedNetwork) {
                $validator->errors()->add('distribution_network', 'Distribution Network must be '.($prepositioning ? 'Last Mile' : 'Spokes').' for the selected Warehouse Type.');
            }
        });
    }

    private function isPrepositioningArea(?string $warehouseType): bool
    {
        return str_contains($this->normalize($warehouseType), 'preposition');
    }

    private function normalize(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '';
    }
}
