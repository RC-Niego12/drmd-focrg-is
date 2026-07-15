<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage inventory') ?? false;
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
