<?php
namespace App\AI;
final readonly class AIResponse
{
    public function __construct(public string $text,public string $model,public int $inputTokens,public int $outputTokens) {}
}
