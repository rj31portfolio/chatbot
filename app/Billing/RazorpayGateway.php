<?php

namespace App\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RazorpayGateway implements PaymentGatewayInterface
{
    private function client()
    {
        if (! config('services.razorpay.key') || ! config('services.razorpay.secret')) {
            throw ValidationException::withMessages(['billing' => 'Online payments are not configured. Please contact support.']);
        }

        return Http::withBasicAuth(config('services.razorpay.key'), config('services.razorpay.secret'))->timeout(20)->baseUrl('https://api.razorpay.com/v1');
    }

    public function createOrder(int $minorAmount, string $currency, string $receipt): array
    {
        return $this->client()->post('/orders', ['amount' => $minorAmount, 'currency' => $currency, 'receipt' => $receipt])->throw()->json();
    }

    public function verify(array $payload): array
    {
        $expected = hash_hmac('sha256', $payload['razorpay_order_id'].'|'.$payload['razorpay_payment_id'], config('services.razorpay.secret'));
        if (! hash_equals($expected, $payload['razorpay_signature'])) {
            throw ValidationException::withMessages(['payment' => 'Payment verification failed.']);
        }
        $payment = $this->client()->get('/payments/'.rawurlencode($payload['razorpay_payment_id']))->throw()->json();
        if (($payment['status'] ?? '') !== 'captured' || ($payment['order_id'] ?? '') !== $payload['razorpay_order_id']) {
            throw ValidationException::withMessages(['payment' => 'Payment has not been captured. Please contact support.']);
        }

        return $payment;
    }
}
