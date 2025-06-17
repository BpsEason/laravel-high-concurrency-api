<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:1|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => __('item.quantity_required'),
            'quantity.integer' => __('item.quantity_integer'),
            'quantity.min' => __('item.quantity_min'),
            'quantity.max' => __('item.quantity_max', ['max' => 1000]),
        ];
    }
}