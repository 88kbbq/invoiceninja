<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Requests\Payment;

use App\Http\Requests\Request;

class BulkActionPaymentRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules()
    {

        return [
            'action' => 'required|string',
            'ids' => 'required|array',
            'template' => 'sometimes|string',
            'template_id' => 'sometimes|string',
            'send_email' => 'sometimes|bool'
        ];

    }

    // public function withValidator($validator)
    // {

    // $validator->after(function ($validator) {
    //     if($this->action == 'delete'){
    //         $attached_credits = \App\Models\Payment::withTrashed()
    //                             ->with('credits.payments')
    //                             ->whereIn('id', $this->transformKeys($this->ids))
    //                             ->company()
    //                             ->map(function ($payment) {
    //                                 return $payment->credits()->whereHas('payments', function ($q){
    //                                     $q->withTrashed()->where('is_deleted',0);
    //                                 })->count();
    //                             })->sum();

    //         if($attached_credits > 0){
    //             $validator->errors()->add('status_id', 'Payment has attached credits and can not be deleted');
    //         }
    //     }
    // });
    // }
}
