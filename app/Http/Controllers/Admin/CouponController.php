<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
class CouponController extends Controller
{
    public function index() { return view('admin.coupons',['coupons'=>Coupon::latest()->get()]); }
    public function save(Request $r,?int $id=null)
    {
        $data=$r->validate(['code'=>'required|string|max:40|regex:/^[A-Z0-9_-]+$/|unique:coupons,code,'.($id??'NULL'),'percent'=>'required|integer|min:1|max:99','max_redemptions'=>'required|integer|min:1|max:1000000','expires_at'=>'nullable|date']);$coupon=$id?Coupon::findOrFail($id):new Coupon;$coupon->fill($data)->save();return back()->with('status','Coupon saved.');
    }
    public function delete(int $id) { Coupon::findOrFail($id)->delete();return back()->with('status','Coupon deleted. Existing orders retain their quoted discount.'); }
}
