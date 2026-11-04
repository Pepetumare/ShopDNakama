<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptCustomQuote extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accept', $this->route('customRequest')) ?? false;
    }

    public function rules(): array
    {
        $required = $this->route('customRequest')->order()->exists() ? 'sometimes' : 'required';

        return [
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_address_line1' => [$required, 'string', 'max:255'],
            'shipping_city' => [$required, 'string', 'max:255'],
            'shipping_region' => [$required, 'string', 'max:255'],
        ];
    }
}
