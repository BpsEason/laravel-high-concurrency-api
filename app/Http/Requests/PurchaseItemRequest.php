<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check(); // 確保使用者已登入
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:1|max:1000', // 限制單次購買數量上限
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'quantity.required' => '購買數量是必填的。',
            'quantity.integer' => '購買數量必須是整數。',
            'quantity.min' => '購買數量至少為 1。',
            'quantity.max' => '單次購買數量不能超過 :max。',
        ];
    }
}