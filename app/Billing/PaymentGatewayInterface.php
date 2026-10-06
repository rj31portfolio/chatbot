<?php

namespace App\Billing;

interface PaymentGatewayInterface
{
    public function createOrder(int $minorAmount, string $currency, string $receipt): array;

    public function verify(array $payload): array;
}
