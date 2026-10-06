<?php
namespace App\Services;

use App\Models\{ChatSession,ChatMessage,AiSetting};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AIConversationService
{
    public function reply(ChatSession $session,string $message): array
    {
        $lock=Cache::lock('chat:'.$session->public_id,90);
        if(!$lock->get()) throw \Illuminate\Validation\ValidationException::withMessages(['message'=>'Please wait for the previous reply.']);
        try {
            $started=microtime(true); $ai=app(AIService::class); $settings=AiSetting::firstOrFail();
            $intent=$ai->analyzeIntent($message);
            $meta=$session->metadata??[];
            if(in_array($intent,['buying','appointment','pricing'],true)) $meta['signals'][$intent]=true;
            $session->update(['metadata'=>$meta,'last_activity'=>now()]);
            if($intent==='human'&&($settings->features['handoff']??false)) {
                $reply=['text'=>'I can connect you with the team. Please use the contact options below or share your contact details so the team can follow up.','citations'=>[],'tokens'=>0,'model'=>null];
                app(EventService::class)->emit('human.requested',$session->lead,['session_id'=>$session->public_id]);
            } else $reply=$ai->generateResponse($session,$message);
            ChatMessage::create(['session_id'=>$session->id,'sender_type'=>'visitor','message'=>$message,'intent'=>$intent]);
            ChatMessage::create(['session_id'=>$session->id,'sender_type'=>'ai','message'=>$reply['text'],'intent'=>$intent,'tokens_used'=>$reply['tokens'],'model'=>$reply['model'],'citations'=>$reply['citations'],'response_time'=>(int)((microtime(true)-$started)*1000)]);
            if(($settings->features['capture']??false)&&($session->lead_id||preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|\+?\d[\d ()-]{7,}\d/i',$message))) {
                try {
                    $transcript=$session->messages()->where('sender_type','visitor')->latest('id')->take(16)->get()->reverse()->pluck('message')->implode("\n");
                    $data=$ai->extractLeadData($transcript);
                    if($session->lead_id||!empty($data['email'])||!empty($data['phone'])) app(LeadService::class)->capture($session,$data);
                } catch(\Throwable $e) { Log::warning('Lead extraction failed',['session'=>$session->public_id,'error'=>$e->getMessage()]); }
            }
            $session->refresh();
            return ['message'=>$reply['text'],'intent'=>$intent,'citations'=>$reply['citations'],'score'=>$session->lead?->score??0];
        } finally { $lock->release(); }
    }
}
