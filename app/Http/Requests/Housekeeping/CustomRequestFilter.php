<?php

namespace App\Http\Requests\Housekeeping;

use App\Models\CustomRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomRequestFilter extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_keys(CustomRequest::STATUSES))],
            'product_type' => ['nullable', Rule::in(array_keys(CustomRequest::PRODUCTS))],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('date_from') ? 'after_or_equal:date_from' : null]),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('product_type'), CustomRequest::LEGACY_PATCH_TYPES, true)) {
            $this->merge(['product_type' => 'patch']);
        }
    }
}
