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

namespace App\PaymentDrivers;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Http\Requests\Payments\PaymentWebhookRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\TapPay\CreditCard;
use App\Utils\Traits\SystemLogTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

class TapPayPaymentDriver extends BaseDriver
{
    use SystemLogTrait;
    use TapPay\Utilities;

    /* The company gateway instance */
    public $company_gateway;

    /* The invitation */
    public $invitation;

    /* Gateway capabilities */
    public $refundable = true;

    /* Token billing */
    public $token_billing = true;

    /* Authorize payment methods */
    public $can_authorise_credit_card = true;

    /**
     * @var Client Guzzle HTTP client
     */
    public $gateway;

    /**
     * @var mixed Payment method instance
     */
    public $payment_method;

    /**
     * Supported payment methods
     *
     * @var array
     */
    public static $methods = [
        GatewayType::CREDIT_CARD => CreditCard::class,
    ];

    /**
     * System log type constant
     */
    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_TAPPAY;

    /**
     * Returns the default gateway type.
     *
     * @return array
     */
    public function gatewayTypes(): array
    {
        return [
            GatewayType::CREDIT_CARD,
        ];
    }

    /**
     * Set the payment method
     *
     * @param int|null $payment_method
     * @return TapPayPaymentDriver
     */
    public function setPaymentMethod($payment_method = null): self
    {
        $class = self::$methods[GatewayType::CREDIT_CARD];
        $this->payment_method = new $class($this);

        return $this;
    }

    /**
     * Initialize the TapPay payment driver
     *
     * @return $this
     */
    public function init()
    {
        $baseUri = $this->company_gateway->getConfigField('testMode')
            ? 'https://sandbox.tappaysdk.com/'
            : 'https://prod.tappaysdk.com/';

        $this->gateway = new Client([
            'base_uri' => $baseUri,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->company_gateway->getConfigField('partnerKey'),
            ],
            'timeout' => 30,
            'verify' => true, // SSL verification enabled
        ]);

        return $this;
    }

    /**
     * Return the gateway view name
     *
     * @param int $gateway_type_id The gateway type
     * @return string The view string
     */
    public function viewForType($gateway_type_id): string
    {
        return 'gateways.tappay.credit_card.pay';
    }

    /**
     * Authorization view for saving payment methods
     *
     * @param array $data
     * @return \Illuminate\View\View
     */
    public function authorizeView($data)
    {
        return $this->payment_method->authorizeView($data);
    }

    /**
     * Process authorization response
     *
     * @param Request $request
     * @return mixed
     */
    public function authorizeResponse($request)
    {
        return $this->payment_method->authorizeResponse($request);
    }

    /**
     * Payment view
     *
     * @param array $data Payment data array
     * @return \Illuminate\View\View
     */
    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    /**
     * Process the payment response
     *
     * @param PaymentResponseRequest $request The payment request
     * @return mixed
     */
    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    /**
     * Refund a payment
     *
     * @param Payment $payment
     * @param float $amount
     * @param bool $return_client_response
     * @return array|mixed
     * @throws PaymentFailed
     */
    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        $this->init();

        $refund_amount = $this->convertToTapPayAmount($amount);

        try {
            $response = $this->gateway->post('tpc/transaction/refund', [
                'json' => [
                    'partner_key' => $this->company_gateway->getConfigField('partnerKey'),
                    'rec_trade_id' => $payment->transaction_reference,
                    'amount' => $refund_amount,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents());

            if ($data->status === 0) {
                // Success
                SystemLogger::dispatch(
                    ['response' => $data, 'data' => $payment],
                    SystemLog::CATEGORY_GATEWAY_RESPONSE,
                    SystemLog::EVENT_GATEWAY_SUCCESS,
                    SystemLog::TYPE_TAPPAY,
                    $this->client,
                    $this->client->company,
                );

                return [
                    'transaction_reference' => $data->rec_trade_id ?? $payment->transaction_reference,
                    'transaction_response' => json_encode($data),
                    'success' => true,
                    'description' => $data->msg ?? 'Refund successful',
                    'code' => $data->status,
                ];
            }

            // Failure
            SystemLogger::dispatch(
                ['response' => $data, 'data' => $payment],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_TAPPAY,
                $this->client,
                $this->client->company,
            );

            throw new PaymentFailed($data->msg ?? 'Refund failed', $data->status);

        } catch (GuzzleException $e) {
            SystemLogger::dispatch(
                ['error' => $e->getMessage(), 'data' => $payment],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_ERROR,
                SystemLog::TYPE_TAPPAY,
                $this->client,
                $this->client->company,
            );

            throw new PaymentFailed('Refund request failed: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Process payment with saved token
     *
     * @param ClientGatewayToken $cgt
     * @param PaymentHash $payment_hash
     * @return Payment
     * @throws PaymentFailed
     */
    public function tokenBilling(ClientGatewayToken $cgt, PaymentHash $payment_hash)
    {
        $this->init();
        $this->payment_hash = $payment_hash;

        $amount = array_sum(array_column($this->payment_hash->invoices(), 'amount')) + $this->payment_hash->fee_total;
        $tappay_amount = $this->convertToTapPayAmount($amount);

        try {
            $response = $this->gateway->post('tpc/payment/pay-by-token', [
                'json' => [
                    'partner_key' => $this->company_gateway->getConfigField('partnerKey'),
                    'merchant_id' => $this->company_gateway->getConfigField('merchantId'),
                    'card_key' => $cgt->token,
                    'card_token' => $cgt->meta->card_token ?? '',
                    'amount' => $tappay_amount,
                    'currency' => $this->client->getCurrencyCode(),
                    'details' => $this->getTapPayDescription(),
                    'cardholder' => [
                        'phone_number' => $this->client->phone ?? '',
                        'name' => $this->client->present()->name(),
                        'email' => $this->client->present()->email(),
                    ],
                    'three_domain_secure' => true,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents());

            if ($data->status === 0) {
                // Success - create payment record
                $payment_record = [];
                $payment_record['amount'] = $amount;
                $payment_record['payment_type'] = PaymentType::CREDIT_CARD_OTHER;
                $payment_record['gateway_type_id'] = GatewayType::CREDIT_CARD;
                $payment_record['transaction_reference'] = $data->rec_trade_id;
                $payment_record['transaction_response'] = json_encode($data);

                $payment = $this->createPayment($payment_record, Payment::STATUS_COMPLETED);

                SystemLogger::dispatch(
                    ['response' => $data, 'data' => $payment_record],
                    SystemLog::CATEGORY_GATEWAY_RESPONSE,
                    SystemLog::EVENT_GATEWAY_SUCCESS,
                    SystemLog::TYPE_TAPPAY,
                    $this->client,
                    $this->client->company,
                );

                return $payment;
            }

            // Failure
            $this->unWindGatewayFees($payment_hash);

            SystemLogger::dispatch(
                ['response' => $data, 'data' => $payment_hash],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_TAPPAY,
                $this->client,
                $this->client->company,
            );

            throw new PaymentFailed($data->msg ?? 'Token payment failed', $data->status);

        } catch (GuzzleException $e) {
            $this->unWindGatewayFees($payment_hash);

            SystemLogger::dispatch(
                ['error' => $e->getMessage(), 'data' => $payment_hash],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_ERROR,
                SystemLog::TYPE_TAPPAY,
                $this->client,
                $this->client->company,
            );

            throw new PaymentFailed('Token billing request failed: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Process webhook requests (payment notifications from TapPay)
     *
     * @param PaymentWebhookRequest $request
     * @return \Illuminate\Http\Response
     */
    public function processWebhookRequest(PaymentWebhookRequest $request)
    {
        // TapPay webhook handling
        // This method can be expanded based on specific webhook requirements

        SystemLogger::dispatch(
            $request->all(),
            SystemLog::CATEGORY_WEBHOOK,
            SystemLog::EVENT_WEBHOOK_RESPONSE,
            SystemLog::TYPE_TAPPAY,
            null,
            $this->company_gateway->company,
        );

        return response()->json(['message' => 'Webhook received'], 200);
    }
}
