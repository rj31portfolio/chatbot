<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\{TenantContext,ResourceRegistry};
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class TeamController extends Controller
{
    public function index(Request $r) { ResourceRegistry::authorize($r,'team'); return view('business.team',['members'=>app(TenantContext::class)->business()->users()->get()]); }
    public function add(Request $r)
    {
        ResourceRegistry::authorize($r,'team');
        $data=$r->validate(['email'=>'required|email','permissions'=>'required|array','permissions.*'=>'in:leads,manage_leads,conversations,knowledge,chatbot,reports']);
        $user=User::where('email',$data['email'])->first(); if(!$user) return back()->withErrors(['email'=>'This person must create an account before you can add them.']);
        $b=app(TenantContext::class)->business();
        DB::transaction(function()use($b,$user,$data){$b->newQuery()->whereKey($b->id)->lockForUpdate()->first(); if($b->users()->where('users.id',$user->id)->exists()) return; if($b->users()->count()>=app(UsageService::class)->limit('team_members')) throw \Illuminate\Validation\ValidationException::withMessages(['team'=>'Your team member limit has been reached.']); $b->users()->attach($user->id,['role'=>'member','permissions'=>json_encode($data['permissions'])]);});
        return back()->with('status','Team member added.');
    }
    public function remove(Request $r,int $id) { ResourceRegistry::authorize($r,'team'); $b=app(TenantContext::class)->business(); abort_if($id===$b->owner_id,422,'The business owner cannot be removed.'); $b->users()->detach($id); return back()->with('status','Team member removed.'); }
}
