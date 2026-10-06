<?php
namespace App\Services;

use App\Models\{ChatWidget,ChatSession,Visitor,AiSetting,VisitorEvent};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class WidgetService
{
    public function config(ChatWidget $widget): array
    {
        $b=app(\App\Support\TenantContext::class)->business();
        $s=AiSetting::firstOrFail();
        return ['widget_id'=>$widget->public_id,'title'=>$widget->title,'color'=>$widget->color,'welcome_message'=>$widget->welcome_message,'position'=>$widget->position,'brand'=>config('saas.brand'),'fields'=>$s->lead_fields,'capture'=>$s->features['capture']??false,'appointments'=>$s->features['appointments']??false,'phone'=>$b->phone,'email'=>$b->email,'whatsapp'=>$b->whatsapp,'settings'=>array_intersect_key($widget->settings??[],array_flip(['placeholder','radius','bottom','size','auto_open_seconds','show_branding','show_phone','show_email','show_whatsapp','tracking']))];
    }
    public function start(ChatWidget $widget,array $data,string $origin): array
    {
        return DB::transaction(function() use($widget,$data,$origin) {
            app(UsageService::class)->consume('conversations');
            $tracking=AiSetting::firstOrFail()->features['tracking']??false;
            $visitor=Visitor::firstOrCreate(['public_id'=>$data['visitor_id']??(string)Str::uuid()],['landing_page'=>$tracking?($data['page_url']??null):null,'referrer'=>$tracking?($data['referrer']??null):null,'metadata'=>$tracking?($data['utm']??[]):[]]);
            $token=Str::random(64);
            $session=ChatSession::create(['public_id'=>(string)Str::uuid(),'chat_widget_id'=>$widget->id,'visitor_id'=>$visitor->id,'token_hash'=>hash('sha256',$token),'origin'=>$origin,'page_url'=>$data['page_url']??null,'last_activity'=>now(),'expires_at'=>now()->addHours(24),'metadata'=>['utm'=>$tracking?($data['utm']??[]):[]]]);
            if($tracking) VisitorEvent::create(['visitor_id'=>$visitor->id,'event'=>'chat.started','metadata'=>['page_url'=>$data['page_url']??null]]);
            $widget->update(['installed_at'=>now()]);
            return ['session_id'=>$session->public_id,'token'=>$token,'visitor_id'=>$visitor->public_id];
        });
    }
    public function authenticate(string $publicId,string $token,?string $origin=null,?int $widgetId=null): ChatSession
    {
        $session=ChatSession::where('public_id',$publicId)->firstOrFail();
        abort_unless(strlen($token)>=32&&hash_equals($session->token_hash,hash('sha256',$token))&&$session->expires_at->isFuture()&&in_array($session->status,['active','transferred'],true),401,'Your chat session expired. Please start a new conversation.');
        if($origin!==null) abort_unless($session->origin===$origin,403);
        if($widgetId!==null) abort_unless($session->chat_widget_id===$widgetId,403);
        return $session;
    }
}
