<?php

use App\AI\Providers\DeepSeekProvider;

return [
    'provider' => env('AI_PROVIDER', 'deepseek'),
    'fallback' => env('AI_FALLBACK_PROVIDER'),
    'provider_classes' => ['deepseek' => DeepSeekProvider::class],
    'providers' => ['deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'timeout' => 40,
        'input_cost_per_million' => (float) env('AI_INPUT_COST', 0),
        'output_cost_per_million' => (float) env('AI_OUTPUT_COST', 0),
    ]],
    'max_context_chars' => 14000,
    'max_output_tokens' => 700,
    'history_messages' => 12,
    'response_cache_seconds'=>(int)env('AI_RESPONSE_CACHE_SECONDS',600),
];
