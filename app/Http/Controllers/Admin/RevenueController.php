<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\{Subscription,Payment,AiUsageLog,Lead,PlatformSetting};
class RevenueController extends Controller
{
    public function index()
    {
        $subscriptions=Subscription::withoutGlobalScopes()->where('status','active')->where(function($q){$q->whereNull('ends_at')->orWhere('ends_at','>',now());})->with('plan')->get();
        $mrr=[];
        foreach($subscriptions as $subscription) {
            $currency=$subscription->plan->currency;
            $mrr[$currency]=($mrr[$currency]??0)+($subscription->interval==='yearly'?$subscription->plan->yearly_price/12:$subscription->plan->monthly_price);
        }
        $revenue=Payment::withoutGlobalScopes()->where('status','paid')->where('updated_at','>=',now()->startOfMonth())->selectRaw('currency, SUM(amount) as revenue, SUM(gateway_fee) as fees')->groupBy('currency')->get();
        $aiCost=AiUsageLog::withoutGlobalScopes()->where('created_at','>=',now()->startOfMonth())->sum('estimated_cost');
        $leadCount=Lead::withoutGlobalScopes()->where('created_at','>=',now()->startOfMonth())->count();
        $fx=(float)(PlatformSetting::where('key','usd_to_inr')->first()?->value['value']??0);
        $daily=[];
        for($i=29;$i>=0;$i--) { $date=now()->subDays($i)->toDateString();$rows=Payment::withoutGlobalScopes()->where('status','paid')->whereDate('updated_at',$date)->selectRaw('currency, SUM(amount) as revenue')->groupBy('currency')->pluck('revenue','currency')->all();$daily[]=['date'=>$date,'amounts'=>$rows]; }
        return view('admin.revenue',compact('mrr','revenue','aiCost','leadCount','fx','daily'));
    }
}
