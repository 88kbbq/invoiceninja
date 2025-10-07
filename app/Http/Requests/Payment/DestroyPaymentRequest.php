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

class DestroyPaymentRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return auth()->user()->can('edit', $this->payment) && $this->payment->is_deleted === false;
    }

    // public function withValidator($validator)
    // {
// $validator->after(function ($validator) {
    //     $attached_credits = $this->payment
    //                             ->credits()
    //                             ->whereHas('payments', function ($q){
    //                                 $q->withTrashed()->where('is_deleted',0);
    //                             })->count();

    //     if($attached_credits > 0){
    //         $validator->errors()->add('status_id', 'Payment has attached credits and can not be deleted');
    //     }
    // });
    // }
}
