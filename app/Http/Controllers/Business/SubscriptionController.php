<?php

namespace App\Http\Controllers\Business;

use App\Billing\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsage;
use App\Support\ResourceRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    public function index(Request $r): View
    {
        ResourceRegistry::authorize($r, 'settings');

        return view('business.subscription', ['subscription' => Subscription::with('plan')->firstOrFail(), 'plans' => SubscriptionPlan::where('active', true)->get(), 'usage' => SubscriptionUsage::where('period', now()->format('Y-m'))->pluck('amount', 'metric'), 'billing' => BusinessSetting::where('key', 'billing')->first()?->value ?? [], 'payments' => Payment::latest()->paginate(10)]);
    }

    public function billing(Request $request): RedirectResponse
    {
        ResourceRegistry::authorize($request, 'settings');
        $billing = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'phone' => 'nullable|string|max:30', 'address' => 'required|string|max:1000', 'city' => 'required|string|max:100', 'state' => 'required|string|max:100', 'postal_code' => 'required|string|max:20', 'country' => 'required|string|max:100', 'tax_id' => 'nullable|string|max:40']);
        BusinessSetting::updateOrCreate(['key' => 'billing'], ['value' => $billing]);

        return back()->with('status', 'Billing details saved. They will appear on your next invoice.');
    }

    public function invoice(Request $request, int $id): View|Response
    {
        ResourceRegistry::authorize($request, 'settings');
        $payment = Payment::whereKey($id)->where('status', 'paid')->firstOrFail();
        $invoice = $payment->metadata['invoice'] ?? ['number' => 'INV-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT), 'issued_at' => $payment->updated_at->toIso8601String(), 'seller' => config('saas.brand'), 'billing' => ['name' => $payment->business->name], 'plan' => SubscriptionPlan::find($payment->metadata['plan_id'] ?? null)?->name ?? 'Subscription', 'interval' => $payment->metadata['interval'] ?? 'monthly'];
        if ($request->boolean('download')) {
            return Pdf::loadView('business.invoice', compact('payment', 'invoice'))->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->download($invoice['number'].'.pdf');
        }

        return view('business.invoice', compact('payment', 'invoice'));
    }

    public function order(Request $r): JsonResponse
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
            Payment::create(['gateway' => 'razorpay', 'reference' => $order['id'], 'amount' => $minor / 100, 'currency' => $plan->currency, 'status' => 'pending', 'metadata' => ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing' => BusinessSetting::where('key', 'billing')->first()?->value ?? ['name' => $r->attributes->get('business')->name, 'email' => $r->user()->email], 'seller' => PlatformSetting::where('key', 'company_name')->first()?->value['value'] ?? config('saas.brand'), 'interval' => $r->input('interval'), 'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code]]);

            return $order;
        });

        $billing = BusinessSetting::where('key', 'billing')->first()?->value ?? [];

        return response()->json(['success' => true, 'message' => 'Order created', 'data' => ['order' => $order, 'key' => config('services.razorpay.key'), 'prefill' => ['name' => $billing['name'] ?? $r->user()->name, 'email' => $billing['email'] ?? $r->user()->email, 'contact' => $billing['phone'] ?? '']]]);
    }

    public function verify(Request $r): JsonResponse
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
            }
            $subscription = Subscription::lockForUpdate()->firstOrFail();
            $interval = $record->metadata['interval'];
            $startsAt = $subscription->status === 'active' && $subscription->subscription_plan_id === (int) $record->metadata['plan_id'] && $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
            $endsAt = $interval === 'yearly' ? $startsAt->copy()->addYearNoOverflow() : $startsAt->copy()->addMonthNoOverflow();
            $metadata = $record->metadata;
            $metadata['invoice'] = ['number' => 'INV-'.str_pad((string) $record->id, 8, '0', STR_PAD_LEFT), 'issued_at' => now()->toIso8601String(), 'seller' => $metadata['seller'] ?? config('saas.brand'), 'billing' => $metadata['billing'] ?? [], 'plan' => $metadata['plan_name'] ?? SubscriptionPlan::findOrFail($metadata['plan_id'])->name, 'interval' => $interval, 'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(), 'payment_id' => $payment['id'] ?? null];
            $record->update(['status' => 'paid', 'gateway_fee' => ($payment['fee'] ?? 0) / 100, 'metadata' => $metadata]);
            $subscription->update(['subscription_plan_id' => $record->metadata['plan_id'], 'status' => 'active', 'interval' => $interval, 'ends_at' => $endsAt, 'gateway' => 'razorpay', 'gateway_reference' => $record->reference]);
        });

        return response()->json(['success' => true, 'message' => 'Subscription upgraded', 'data' => []]);
    }

    public function cancel(Request $r): RedirectResponse
    {
        ResourceRegistry::authorize($r, 'settings');
        $s = Subscription::firstOrFail();
        $s->update(['status' => 'cancelled']);

        return back()->with('status', 'Subscription cancelled. Widget access is paused.');
    }
}
