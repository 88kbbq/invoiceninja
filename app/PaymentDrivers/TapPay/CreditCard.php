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

namespace App\PaymentDrivers\TapPay;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\Common\LivewireMethodInterface;
use App\PaymentDrivers\Common\MethodInterface;
use App\PaymentDrivers\TapPayPaymentDriver;
use App\Utils\Traits\MakesHash;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

class CreditCard implements MethodInterface, LivewireMethodInterface
{
    use Utilities;
    use MakesHash;

    /**
     * @var TapPayPaymentDriver
     */
    public TapPayPaymentDriver $tappay;

    public function __construct(TapPayPaymentDriver $tappay)
    {
        $this->tappay = $tappay;
        $this->tappay->init();
    }

    /**
     * Authorization view for saving cards
     *
     * @param mixed $data
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function authorizeView($data)
    {
        $data['gateway'] = $this->tappay;
        $data['cardholder_name'] = auth()->guard('contact')->user()->present()->name() ?? '';
        $data['app_id'] = $this->tappay->getAppId();
        $data['app_key'] = $this->tappay->getPublishableKey();
        $data['server_type'] = $this->tappay->getServerType();

        return render('gateways.tappay.credit_card.authorize', $data);
    }

    /**
     * Handle authorization for credit card (save card for future use)
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws PaymentFailed
     */
    public function authorizeResponse(Request $request)
    {
        $prime = $request->input('prime');
        $cardholder_name = $request->input('cardholder_name');

        if (!$prime) {
            throw new PaymentFailed('No prime token received', 400);
        }

        try {
            // Make $1 authorization charge to validate and save card
            $response = $this->tappay->gateway->post('tpc/payment/pay-by-prime', [
                'json' => [
                    'prime' => $prime,
                    'partner_key' => $this->tappay->getPartnerKey(),
                    'merchant_id' => $this->tappay->getMerchantId(),
                    'amount' => 1, // $0.01 or $1 depending on currency
                    'currency' => $this->tappay->client->getCurrencyCode(),
                    'details' => 'Card authorization for future payments',
                    'cardholder' => [
                        'phone_number' => $this->tappay->client->phone ?? '',
                        'name' => $cardholder_name,
                        'email' => $this->tappay->client->present()->email(),
                    ],
                    'remember' => true, // Request card token for future use
                ],
            ]);

            $data = json_decode($response->getBody()->getContents());

            if ($data->status === 0 && isset($data->card_secret)) {
                // Success - save card token
                $payment_meta = new \stdClass();
                $payment_meta->exp_month = (string) $data->card_info->expiry_date->month;
                $payment_meta->exp_year = (string) $data->card_info->expiry_date->year;
                $payment_meta->brand = (string) ($data->card_info->type ?? 'Card');
                $payment_meta->last4 = (string) $data->card_info->last_four;
                $payment_meta->type = (int) GatewayType::CREDIT_CARD;
                $payment_meta->card_token = (string) $data->card_secret->card_token;

                $token_data = [
                    'payment_meta' => $payment_meta,
                    'token' => $data->card_secret->card_key,
                    'payment_method_id' => GatewayType::CREDIT_CARD,
                ];

                $payment_method = $this->tappay->storeGatewayToken($token_data);

                SystemLogger::dispatch(
                    ['response' => $data, 'data' => $token_data],
                    SystemLog::CATEGORY_GATEWAY_RESPONSE,
                    SystemLog::EVENT_GATEWAY_SUCCESS,
                    SystemLog::TYPE_TAPPAY,
                    $this->tappay->client,
                    $this->tappay->client->company,
                );

                return redirect()->route('client.payment_methods.show', $payment_method->hashed_id);
            }

            // Failure
            SystemLogger::dispatch(
                ['response' => $data],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_TAPPAY,
                $this->tappay->client,
                $this->tappay->client->company,
            );

            throw new PaymentFailed($data->msg ?? 'Card authorization failed', $data->status);

        } catch (GuzzleException $e) {
            SystemLogger::dispatch(
                ['error' => $e->getMessage()],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_ERROR,
                SystemLog::TYPE_TAPPAY,
                $this->tappay->client,
                $this->tappay->client->company,
            );

            throw new PaymentFailed('Authorization request failed: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Prepare payment data
     *
     * @param array $data
     * @return array
     */
    public function paymentData(array $data): array
    {
        $data['gateway'] = $this->tappay;
        $data['company_gateway'] = $this->tappay->company_gateway;
        $data['client'] = $this->tappay->client;
        $data['currency'] = $this->tappay->client->getCurrencyCode();
        $data['value'] = $this->tappay->convertToTapPayAmount($data['total']['amount_with_fee']);
        $data['raw_value'] = $data['total']['amount_with_fee'];
        $data['customer_email'] = $this->tappay->client->present()->email();
        $data['cardholder_name'] = auth()->guard('contact')->user()->present()->name() ?? '';
        $data['app_id'] = $this->tappay->getAppId();
        $data['app_key'] = $this->tappay->getPublishableKey();
        $data['server_type'] = $this->tappay->getServerType();

        \Log::debug('TapPay paymentData', [
            'app_id' => $data['app_id'],
            'app_key' => $data['app_key'],
            'server_type' => $data['server_type'],
            'test_mode' => $this->tappay->company_gateway->getConfigField('testMode'),
        ]);

        return $data;
    }

    /**
     * Payment view
     *
     * @param mixed $data
     * @param bool $livewire
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|string
     */
    public function paymentView($data, $livewire = false)
    {
        $data = $this->paymentData($data);

        if ($livewire) {
            return render('gateways.tappay.credit_card.pay_livewire', $data);
        }

        return render('gateways.tappay.credit_card.pay', $data);
    }

    /**
     * Livewire payment view
     *
     * @param array $data
     * @return string
     */
    public function livewirePaymentView(array $data): string
    {
        return 'gateways.tappay.credit_card.pay_livewire';
    }

    /**
     * Process payment response
     *
     * @param PaymentResponseRequest $request
     * @return \Illuminate\Http\RedirectResponse|mixed
     * @throws PaymentFailed
     */
    public function paymentResponse(PaymentResponseRequest $request)
    {
        // Check if paying with saved token
        if ($request->has('token') && !is_null($request->token) && !empty($request->token)) {
            return $this->attemptPaymentUsingToken($request);
        }

        // Pay with new card using Prime
        return $this->attemptPaymentUsingPrime($request);
    }

    /**
     * Attempt payment using saved token
     *
     * @param PaymentResponseRequest $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws PaymentFailed
     */
    private function attemptPaymentUsingToken(PaymentResponseRequest $request)
    {
        $cgt = ClientGatewayToken::query()
            ->where('id', $this->decodePrimaryKey($request->input('token')))
            ->where('company_id', auth()->guard('contact')->user()->client->company_id)
            ->first();

        if (!$cgt) {
            throw new PaymentFailed(ctrans('texts.payment_token_not_found'), 401);
        }

        // Get amount from request (already converted to TapPay format)
        $amount = $request->input('value') ?? $this->tappay->payment_hash->data->value ?? 0;

        try {
            // Build request body
            $requestBody = [
                'partner_key' => $this->tappay->getPartnerKey(),
                'merchant_id' => $this->tappay->getMerchantId(),
                'card_key' => $cgt->token,
                'card_token' => $cgt->meta->card_token ?? '',
                'amount' => (int) $amount,
                'currency' => $this->tappay->client->getCurrencyCode(),
                'details' => $this->tappay->getTapPayDescription(),
                'cardholder' => [
                    'phone_number' => $this->tappay->client->phone ?? '',
                    'name' => $this->tappay->client->present()->name(),
                    'email' => $this->tappay->client->present()->email(),
                ],
            ];

            // LOG REQUEST - For TapPay Support
            \Log::info('TapPay API Request - Pay by Token', [
                'endpoint' => 'tpc/payment/pay-by-token',
                'method' => 'POST',
                'request_body' => $requestBody,
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
                'gateway_token_id' => $cgt->id,
            ]);

            $response = $this->tappay->gateway->post('tpc/payment/pay-by-token', [
                'json' => $requestBody,
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody);

            // LOG RESPONSE - For TapPay Support
            \Log::info('TapPay API Response - Pay by Token', [
                'endpoint' => 'tpc/payment/pay-by-token',
                'http_status_code' => $response->getStatusCode(),
                'response_body' => $data,
                'response_raw' => $responseBody,
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
                'gateway_token_id' => $cgt->id,
            ]);

            if ($data->status === 0) {
                return $this->processSuccessfulPayment($data, $amount);
            }

            return $this->processFailedPayment($data);

        } catch (GuzzleException $e) {
            $this->tappay->unWindGatewayFees($this->tappay->payment_hash);

            // LOG ERROR - For TapPay Support
            \Log::error('TapPay API Error - Pay by Token', [
                'endpoint' => 'tpc/payment/pay-by-token',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'exception_class' => get_class($e),
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
                'gateway_token_id' => $cgt->id ?? null,
                'request_body' => $requestBody ?? null,
            ]);

            SystemLogger::dispatch(
                ['error' => $e->getMessage()],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_ERROR,
                SystemLog::TYPE_TAPPAY,
                $this->tappay->client,
                $this->tappay->client->company,
            );

            throw new PaymentFailed('Payment request failed: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Attempt payment using Prime token from TapPay SDK
     *
     * @param PaymentResponseRequest $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws PaymentFailed
     */
    private function attemptPaymentUsingPrime(PaymentResponseRequest $request)
    {
        $prime = $request->input('prime');
        $cardholder_name = $request->input('cardholder_name');
        $store_card = boolval($request->input('store_card'));

        if (!$prime) {
            throw new PaymentFailed('No prime token received', 400);
        }

        // Get amount from request (already converted to TapPay format)
        $amount = $request->input('value') ?? $this->tappay->payment_hash->data->value ?? 0;

        \Log::debug('TapPay Payment Amount Debug - Prime', [
            'request_value' => $request->input('value'),
            'payment_hash_value' => $this->tappay->payment_hash->data->value ?? null,
            'final_amount' => $amount,
            'raw_value' => $request->input('raw_value'),
        ]);

        try {
            // Build request body
            $requestBody = [
                'prime' => $prime,
                'partner_key' => $this->tappay->getPartnerKey(),
                'merchant_id' => $this->tappay->getMerchantId(),
                'amount' => (int) $amount,
                'currency' => $this->tappay->client->getCurrencyCode(),
                'details' => $this->tappay->getTapPayDescription(),
                'cardholder' => [
                    'phone_number' => $this->tappay->client->phone ?? '',
                    'name' => $cardholder_name,
                    'email' => $this->tappay->client->present()->email(),
                ],
                'remember' => $store_card,
            ];

            // LOG REQUEST - For TapPay Support
            \Log::info('TapPay API Request - Pay by Prime', [
                'endpoint' => 'tpc/payment/pay-by-prime',
                'method' => 'POST',
                'request_body' => $requestBody,
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
            ]);

            // Charge the card using Prime token
            $response = $this->tappay->gateway->post('tpc/payment/pay-by-prime', [
                'json' => $requestBody,
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody);

            // LOG RESPONSE - For TapPay Support
            \Log::info('TapPay API Response - Pay by Prime', [
                'endpoint' => 'tpc/payment/pay-by-prime',
                'http_status_code' => $response->getStatusCode(),
                'response_body' => $data,
                'response_raw' => $responseBody,
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
            ]);

            if ($data->status === 0) {
                // Save card if requested and token was returned
                if ($store_card && isset($data->card_secret)) {
                    $this->saveCardToken($data);
                }

                return $this->processSuccessfulPayment($data, $amount);
            }

            return $this->processFailedPayment($data);

        } catch (GuzzleException $e) {
            $this->tappay->unWindGatewayFees($this->tappay->payment_hash);

            // LOG ERROR - For TapPay Support
            \Log::error('TapPay API Error - Pay by Prime', [
                'endpoint' => 'tpc/payment/pay-by-prime',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'exception_class' => get_class($e),
                'timestamp' => now()->toIso8601String(),
                'client_id' => $this->tappay->client->id,
                'payment_hash' => $this->tappay->payment_hash->hash ?? null,
                'request_body' => $requestBody ?? null,
            ]);

            SystemLogger::dispatch(
                ['error' => $e->getMessage()],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_ERROR,
                SystemLog::TYPE_TAPPAY,
                $this->tappay->client,
                $this->tappay->client->company,
            );

            throw new PaymentFailed('Payment request failed: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Save card token for future use
     *
     * @param object $data
     * @return void
     */
    private function saveCardToken($data): void
    {
        $payment_meta = new \stdClass();
        $payment_meta->exp_month = (string) $data->card_info->expiry_date->month;
        $payment_meta->exp_year = (string) $data->card_info->expiry_date->year;
        $payment_meta->brand = (string) ($data->card_info->type ?? 'Card');
        $payment_meta->last4 = (string) $data->card_info->last_four;
        $payment_meta->type = (int) GatewayType::CREDIT_CARD;
        $payment_meta->card_token = (string) $data->card_secret->card_token;

        $token_data = [
            'payment_meta' => $payment_meta,
            'token' => $data->card_secret->card_key,
            'payment_method_id' => GatewayType::CREDIT_CARD,
        ];

        $this->tappay->storeGatewayToken($token_data);
    }

    /**
     * Process successful payment
     *
     * @param object $data
     * @param float $amount
     * @return \Illuminate\Http\RedirectResponse
     */
    private function processSuccessfulPayment($data, $amount)
    {
        // Create payment record
        $payment_record = [];
        $payment_record['amount'] = $amount;
        $payment_record['payment_type'] = PaymentType::CREDIT_CARD_OTHER;
        $payment_record['gateway_type_id'] = GatewayType::CREDIT_CARD;
        $payment_record['transaction_reference'] = $data->rec_trade_id;
        $payment_record['transaction_response'] = json_encode($data);

        $payment = $this->tappay->createPayment($payment_record, Payment::STATUS_COMPLETED);

        SystemLogger::dispatch(
            ['response' => $data, 'data' => $payment_record],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_TAPPAY,
            $this->tappay->client,
            $this->tappay->client->company,
        );

        return redirect()->route('client.payments.show', ['payment' => $payment->hashed_id]);
    }

    /**
     * Process failed payment
     *
     * @param object $data
     * @return mixed
     * @throws PaymentFailed
     */
    private function processFailedPayment($data)
    {
        $this->tappay->unWindGatewayFees($this->tappay->payment_hash);

        SystemLogger::dispatch(
            ['response' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_TAPPAY,
            $this->tappay->client,
            $this->tappay->client->company,
        );

        $error_message = $data->msg ?? 'Payment failed';

        return $this->tappay->processInternallyFailedPayment($this->tappay, new \Exception($error_message));
    }
}
