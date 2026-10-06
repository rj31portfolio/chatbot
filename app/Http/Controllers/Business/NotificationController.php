<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Support\{ResourceRegistry,TenantContext};
use Illuminate\Http\Request;
class NotificationController extends Controller
{
    public function index(Request $r)
    {
        ResourceRegistry::authorize($r,'leads');
        return view('business.notifications',['alerts'=>$r->user()->notifications()->where('data->business_id',app(TenantContext::class)->id())->latest()->paginate(20)]);
    }
    public function read(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'leads');
        $r->user()->notifications()->where('data->business_id',app(TenantContext::class)->id())->whereKey($id)->firstOrFail()->markAsRead();
        return back()->with('status','Notification marked as read.');
    }
}
