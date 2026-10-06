<?php
namespace App\Services;

use App\Models\{Business,Subscription,SubscriptionPlan,AiSetting,ChatWidget,ChatWidgetDomain};
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessService
{
    public function create(\App\Models\User $owner,array $data): Business
    {
        return DB::transaction(function() use($owner,$data) {
            $business=new Business($data);
            $business->owner_id=$owner->id;
            $business->slug=Str::slug($data['name']).'-'.Str::lower(Str::random(8));
            $business->save();
            $business->users()->attach($owner->id,['role'=>'owner','permissions'=>json_encode([])]);
            app(TenantContext::class)->run($business,function() use($business) {
                Subscription::create(['subscription_plan_id'=>SubscriptionPlan::where('name','Free')->firstOrFail()->id,'status'=>'active']);
                AiSetting::create(['features'=>['sales'=>true,'capture'=>true,'appointments'=>true,'pricing'=>true,'handoff'=>true,'notifications'=>true,'tracking'=>false],'lead_fields'=>['name','phone','email','requirement'],'scoring'=>config('saas.scoring')]);
                $widget=ChatWidget::create(['public_id'=>(string) Str::uuid(),'title'=>$business->name.' assistant']);
                if($host=parse_url($business->website_url??'',PHP_URL_HOST)) ChatWidgetDomain::create(['chat_widget_id'=>$widget->id,'domain'=>strtolower($host)]);
                if($business->description) app(KnowledgeService::class)->save(['source_type'=>'profile','title'=>'Business profile','content'=>$business->description]);
            });
            return $business;
        });
    }
}
