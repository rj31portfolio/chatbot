<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Models\Business;
use App\Models\ChatWidget;
use App\Models\ChatWidgetDomain;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessService
{
    public function create(User $owner, array $data): Business
    {
        return DB::transaction(function () use ($owner, $data) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $owned=Business::where('owner_id',$owner->id)->get();
            $businessLimit=1;
            foreach($owned as $existing) {
                $allowed=app(TenantContext::class)->run($existing,function() {
                    try { return (int)(app(UsageService::class)->subscription()->plan->limits['businesses']??1); }
                    catch(\Illuminate\Validation\ValidationException) { return 1; }
                });
                $businessLimit=max($businessLimit,$allowed);
            }
            if($owned->count()>=$businessLimit) throw \Illuminate\Validation\ValidationException::withMessages(['business'=>'Your business limit has been reached. Upgrade an existing business to a plan that supports additional businesses.']);
            $business = new Business($data);
            $business->owner_id = $owner->id;
            $business->slug = Str::slug($data['name']).'-'.Str::lower(Str::random(8));
            $business->save();
            $business->users()->attach($owner->id, ['role' => 'owner', 'permissions' => json_encode([])]);
            app(TenantContext::class)->run($business, function () use ($business) {
                Subscription::create(['subscription_plan_id' => SubscriptionPlan::where('name', 'Free')->firstOrFail()->id, 'status' => 'active']);
                AiSetting::create(['features' => ['sales' => true, 'capture' => true, 'appointments' => true, 'pricing' => true, 'handoff' => true, 'notifications' => true, 'tracking' => false], 'lead_fields' => ['name', 'phone', 'email', 'requirement'], 'scoring' => config('saas.scoring')]);
                $widget = ChatWidget::create(['public_id' => (string) Str::uuid(), 'title' => $business->name.' assistant']);
                if ($host = parse_url($business->website_url ?? '', PHP_URL_HOST)) {
                    ChatWidgetDomain::create(['chat_widget_id' => $widget->id, 'domain' => strtolower($host)]);
                }
                if ($business->description) {
                    app(KnowledgeService::class)->save(['source_type' => 'profile', 'title' => 'Business profile', 'content' => $business->description]);
                }
            });

            return $business;
        });
    }
}
