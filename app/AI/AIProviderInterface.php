<?php

namespace App\AI;

interface AIProviderInterface
{
    public function complete(array $messages, bool $json = false): AIResponse;
}
