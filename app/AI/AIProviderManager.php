<?php
namespace App\AI;
class AIProviderManager implements AIProviderInterface
{
    public function complete(array $messages,bool $json=false): AIResponse
    {
        $primary=config('ai.provider');
        try { return $this->resolve($primary)->complete($messages,$json); }
        catch(\Throwable $e) {
            $fallback=config('ai.fallback');
            if(!$fallback||$fallback===$primary) throw $e;
            \Illuminate\Support\Facades\Log::warning('Primary AI provider failed; configured fallback selected',['provider'=>$primary]);
            return $this->resolve($fallback)->complete($messages,$json);
        }
    }
    private function resolve(string $name): AIProviderInterface
    {
        $class=config('ai.provider_classes.'.$name);
        if(!$class||!is_subclass_of($class,AIProviderInterface::class)) throw new \RuntimeException('The configured AI provider is not installed.');
        return app($class);
    }
}
