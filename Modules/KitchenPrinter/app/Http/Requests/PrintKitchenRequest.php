<?php

namespace Modules\KitchenPrinter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrintKitchenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can view the invoice/quote
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ids' => 'sometimes|array',
            'ids.*' => 'string',
        ];
    }
}
