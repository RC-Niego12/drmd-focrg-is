<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA'])
            || $user->can('manage inventory');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:food,non_food'],
            'unit' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,inactive,archived'],
            'description' => ['nullable', 'string'],
        ];
    }
}
