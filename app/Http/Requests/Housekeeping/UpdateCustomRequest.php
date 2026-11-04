<?php

namespace App\Http\Requests\Housekeeping;

use App\Models\CustomRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('customRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(CustomRequest::STATUSES))],
            'admin_notes' => ['nullable', 'string', 'max:10000'],
            'quoted_price' => ['nullable', Rule::prohibitedIf($this->route('customRequest')->design_assistance || $this->has('base_quoted_price')), 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'base_quoted_price' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }
}
