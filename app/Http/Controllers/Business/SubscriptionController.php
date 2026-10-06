<?php

namespace App\Http\Controllers\Business;

use App\Billing\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsage;
use App\Support\ResourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function index(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');

        return view('business.subscription', ['subscription' => Subscription::with('plan')->firstOrFail(), 'plans' => SubscriptionPlan::where('active', true)->get(), 'usage' => SubscriptionUsage::where('period', now()->format('Y-m'))->pluck('amount', 'metric')]);
    }

    public function order(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');
        $r->validate(['plan_id' => 'required|integer', 'interval' => 'required|in:monthly,yearly', 'coupon' => 'nullable|string|max:40']);
        $plan = SubscriptionPlan::whereKey($r->input('plan_id'))->where('active', true)->firstOrFail();
        $amount = $r->input('interval') === 'yearly' ? $plan->yearly_price : $plan->monthly_price;
        if ($amount <= 0) {
            throw ValidationException::withMessages(['plan' => 'Choose a paid plan to use online checkout.']);
        }
        $order = DB::transaction(function () use ($r, $plan, $amount) {
            $coupon = null;
            $minor = (int) round($amount * 100);
            if ($r->filled('coupon')) {
                $coupon = Coupon::where('code', strtoupper($r->input('coupon')))->lockForUpdate()->first();
                if (! $coupon || $coupon->expires_at?->isPast() || $coupon->redemptions >= $coupon->max_redemptions) {
                    throw ValidationException::withMessages(['coupon' => 'This coupon is invalid, expired, or fully used.']);
                }
                $minor = (int) round($minor * (100 - $coupon->percent) / 100);
                $coupon->increment('redemptions');
            }
            $order = app(PaymentGatewayInterface::class)->createOrder($minor, $plan->currency, 'upgrade_'.Str::random(16));
            Payment::create(['gateway' => 'razorpay', 'reference' => $order['id'], 'amount' => $minor / 100, 'currency' => $plan->currency, 'status' => 'pending', 'metadata' => ['plan_id' => $plan->id, 'interval' => $r->input('interval'), 'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code]]);

            return $order;
        });

        return response()->json(['success' => true, 'message' => 'Order created', 'data' => ['order' => $order, 'key' => config('services.razorpay.key')]]);
    }

    public function verify(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');
        $data = $r->validate(['razorpay_order_id' => 'required|string|max:100', 'razorpay_payment_id' => 'required|string|max:100', 'razorpay_signature' => 'required|string|size:64']);
        $record = Payment::where('reference', $data['razorpay_order_id'])->firstOrFail();
        $payment = app(PaymentGatewayInterface::class)->verify($data);
        abort_unless((int) $payment['amount'] === (int) round($record->amount * 100) && $payment['currency'] === $record->currency, 422);
        DB::transaction(function () use ($record, $payment) {
            $record = Payment::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->status === 'paid') {
                return;
            }$record->update(['status' => 'paid', 'gateway_fee' => ($payment['fee'] ?? 0) / 100]);
            $interval = $record->metadata['interval'];
            Subscription::firstOrFail()->update(['subscription_plan_id' => $record->metadata['plan_id'], 'status' => 'active', 'interval' => $interval, 'ends_at' => $interval === 'yearly' ? now()->addYear() : now()->addMonth(), 'gateway' => 'razorpay', 'gateway_reference' => $record->reference]);
        });

        return response()->json(['success' => true, 'message' => 'Subscription upgraded', 'data' => []]);
    }

    public function cancel(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');
        $s = Subscription::firstOrFail();
        $s->update(['status' => 'cancelled']);

        return back()->with('status', 'Subscription cancelled. Widget access is paused.');
    }
}
