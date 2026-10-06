<?php
namespace App\AI\Providers;

use App\AI\{AIProviderInterface,AIResponse};
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekProvider implements AIProviderInterface
{
    public function complete(array $messages,bool $json=false): AIResponse
    {
        $config=config('ai.providers.deepseek');
        if(empty($config['api_key'])) throw new RuntimeException('DeepSeek is not configured.');
        $payload=['model'=>$config['model'],'messages'=>$messages,'max_tokens'=>config('ai.max_output_tokens'),'temperature'=>0.3];
        if($json) $payload['response_format']=['type'=>'json_object'];
        $body=Http::withToken($config['api_key'])->acceptJson()->timeout($config['timeout'])->post(rtrim($config['base_url'],'/').'/chat/completions',$payload)->throw()->json();
        $text=$body['choices'][0]['message']['content']??null;
        if(!is_string($text)||trim($text)===''||!isset($body['usage']['prompt_tokens'],$body['usage']['completion_tokens'])) throw new RuntimeException('Invalid AI provider response.');
        return new AIResponse($text,$body['model']??$config['model'],max(0,(int)$body['usage']['prompt_tokens']),max(0,(int)$body['usage']['completion_tokens']));
    }
}
