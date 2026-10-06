<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Support\ResourceRegistry;
use App\Services\UsageService;
use Illuminate\Http\Request;
class BrandingController extends Controller
{
    public function index(Request $r)
    {
        ResourceRegistry::authorize($r,'settings');
        return view('business.branding',['branding'=>BusinessSetting::where('key','branding')->first()?->value??[]]);
    }
    public function save(Request $r)
    {
        ResourceRegistry::authorize($r,'settings');
        if(!(app(UsageService::class)->subscription()->plan->limits['white_label']??0)) throw \Illuminate\Validation\ValidationException::withMessages(['plan'=>'Upgrade to a plan with white-label access to customize business branding.']);
        $data=$r->validate(['brand'=>'required|string|max:100','primary_color'=>'required|regex:/^#[0-9a-fA-F]{6}$/','logo_url'=>'nullable|url:https|max:2048','support_email'=>'nullable|email|max:255']);BusinessSetting::updateOrCreate(['key'=>'branding'],['value'=>$data]);
        return back()->with('status','Business branding saved.');
    }
}
