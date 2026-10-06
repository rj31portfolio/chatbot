<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Support\{TenantContext,ResourceRegistry};
use App\Services\AnalyticsService;
use App\Models\Subscription;
use Illuminate\Http\Request;
class AgencyController extends Controller
{
    public function index(Request $request)
    {
        ResourceRegistry::authorize($request,'settings');
        $clients=[];
        foreach($request->user()->businesses()->where('owner_id',$request->user()->id)->get() as $business) {
            $clients[]=app(TenantContext::class)->run($business,fn()=>['business'=>$business,'metrics'=>app(AnalyticsService::class)->metrics(),'plan'=>Subscription::with('plan')->first()?->plan->name]);
        }
        return view('business.agency',compact('clients'));
    }
}
