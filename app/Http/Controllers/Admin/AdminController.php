<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Business;
use App\Models\ChatSession;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index()
    {
        $revenue = Payment::withoutGlobalScopes()->where('status', 'paid')->sum('amount');
        $cost = AiUsageLog::withoutGlobalScopes()->sum('estimated_cost');

        return view('admin.dashboard', ['businesses' => Business::with('owner')->latest()->paginate(15), 'metrics' => ['businesses' => Business::count(), 'active' => Business::where('status', 'active')->count(), 'users' => User::count(), 'tokens' => AiUsageLog::withoutGlobalScopes()->sum('total_tokens'), 'requests' => AiUsageLog::withoutGlobalScopes()->count(), 'cost' => $cost, 'leads' => Lead::withoutGlobalScopes()->count(), 'chats' => ChatSession::withoutGlobalScopes()->count(), 'revenue' => $revenue], 'usage' => AiUsageLog::withoutGlobalScopes()->selectRaw('business_id, SUM(total_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')->groupBy('business_id')->get()->keyBy('business_id')]);
    }

    public function plans()
    {
        return view('admin.plans', ['plans' => SubscriptionPlan::get()]);
    }

    public function savePlan(Request $r, ?int $id = null)
    {
        $data = $r->validate(['name' => 'required|string|max:100|unique:subscription_plans,name,'.($id ?? 'NULL'), 'monthly_price' => 'required|numeric|min:0', 'yearly_price' => 'required|numeric|min:0', 'currency' => 'required|in:INR,USD,EUR', 'limits' => 'required|array', 'limits.*' => 'required|integer|min:0|max:1000000000']);
        $required = array_keys(config('saas.plans.Free'));
        $required = array_diff($required, ['price']);
        foreach ($required as $key) {
            if (! array_key_exists($key, $data['limits'])) {
                throw ValidationException::withMessages(['limits' => 'All plan limits are required.']);
            }
        }
        $plan = $id ? SubscriptionPlan::findOrFail($id) : new SubscriptionPlan;
        $data['active'] = $r->boolean('active');
        $plan->fill($data)->save();

        return back()->with('status', 'Plan saved.');
    }

    public function businessStatus(Request $r, int $id)
    {
        $data = $r->validate(['status' => 'required|in:active,suspended']);
        Business::findOrFail($id)->forceFill($data)->save();

        return back()->with('status', 'Business status updated.');
    }

    public function users()
    {
        return view('admin.users', ['users' => User::latest()->paginate(25)]);
    }

    public function userStatus(Request $r, int $id)
    {
        abort_if($id === $r->user()->id, 422, 'You cannot suspend yourself.');
        User::findOrFail($id)->forceFill($r->validate(['status' => 'required|in:active,suspended']))->save();

        return back()->with('status', 'User updated.');
    }

    public function settings()
    {
        return view('admin.settings', ['settings' => PlatformSetting::get()->keyBy('key')]);
    }

    public function saveSettings(Request $r)
    {
        $data = $r->validate(['usd_to_inr' => 'nullable|numeric|min:0|max:10000', 'logo_url' => 'nullable|url:https|max:2048', 'favicon_url' => 'nullable|url:https|max:2048', 'secondary_color' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'company_name' => 'nullable|string|max:255', 'website_url' => 'nullable|url:https|max:2048', 'brand' => 'required|string|max:100', 'primary_color' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'support_email' => 'nullable|email|max:255', 'deepseek_key' => 'nullable|string|max:255', 'deepseek_model' => 'required|string|max:100', 'input_cost' => 'required|numeric|min:0|max:1000', 'output_cost' => 'required|numeric|min:0|max:1000']);
        foreach ($data as $key => $value) {
            if ($key === 'deepseek_key') {
                if (! $value) {
                    continue;
                } $value = Crypt::encryptString($value);
            }
            PlatformSetting::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
        }

        return back()->with('status', 'Platform settings saved. API keys are encrypted.');
    }

    public function subscriptions()
    {
        return view('admin.subscriptions', ['subscriptions' => Subscription::withoutGlobalScopes()->with(['business', 'plan'])->latest()->paginate(25), 'plans' => SubscriptionPlan::get()]);
    }

    public function updateSubscription(Request $r, int $id)
    {
        $data = $r->validate(['subscription_plan_id' => 'required|exists:subscription_plans,id', 'status' => 'required|in:trial,active,past_due,cancelled,expired', 'ends_at' => 'nullable|date']);
        $subscription = Subscription::withoutGlobalScopes()->findOrFail($id);
        if ($data['status'] === 'trial') {
            $data['trial_ends_at'] = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : now()->addDays(config('saas.trial_days'));
        }
        app(TenantContext::class)->run($subscription->business, fn () => $subscription->update($data));

        return back()->with('status', 'Subscription updated.');
    }
}
