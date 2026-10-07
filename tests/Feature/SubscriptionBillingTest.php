<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BusinessService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $this->owner = User::factory()->create();
        $this->business = app(BusinessService::class)->create($this->owner, ['name' => 'Billing Studio', 'description' => 'Business consulting', 'timezone' => 'UTC']);
        $this->actingAs($this->owner)->withSession(['business_id' => $this->business->id]);
        config(['services.razorpay.key' => 'rzp_test_key', 'services.razorpay.secret' => 'test_secret']);
        Http::preventStrayRequests();
    }

    /** @return array{name: string, email: string, address: string, city: string, state: string, postal_code: string, country: string, tax_id: string} */
    private function billingDetails(): array
    {
        return ['name' => 'Original Billing Company', 'email' => 'billing@example.test', 'address' => '12 Market Road', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400001', 'country' => 'India', 'tax_id' => 'TEST-TAX-ID'];
    }

    /** @return array{razorpay_order_id: string, razorpay_payment_id: string, razorpay_signature: string} */
    private function paymentPayload(): array
    {
        return ['razorpay_order_id' => 'order_billing', 'razorpay_payment_id' => 'pay_billing', 'razorpay_signature' => hash_hmac('sha256', 'order_billing|pay_billing', 'test_secret')];
    }

    private function createOrder(int $capturedAmount = 99900): int
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_billing', 'amount' => 99900, 'currency' => 'INR']), 'api.razorpay.com/v1/payments/pay_billing' => Http::response(['id' => 'pay_billing', 'status' => 'captured', 'order_id' => 'order_billing', 'amount' => $capturedAmount, 'currency' => 'INR', 'fee' => 200])]);
        $plan = SubscriptionPlan::where('name', 'Starter')->firstOrFail();
        $this->postJson('/subscription/order', ['plan_id' => $plan->id, 'interval' => 'monthly'])->assertOk()->assertJsonPath('data.prefill.email', $this->billingDetails()['email']);

        return app(TenantContext::class)->run($this->business, fn () => Payment::firstOrFail()->id);
    }

    public function test_billing_details_are_validated_and_saved_for_current_business(): void
    {
        $this->put('/subscription/billing', ['name' => 'Incomplete'])->assertSessionHasErrors(['email', 'address']);
        $this->put('/subscription/billing', $this->billingDetails())->assertRedirect()->assertSessionHas('status');
        app(TenantContext::class)->run($this->business, function () {
            $this->assertSame($this->billingDetails()['name'], BusinessSetting::where('key', 'billing')->firstOrFail()->value['name']);
        });
        $this->get('/subscription')->assertOk()->assertSee('Original Billing Company')->assertSee('Payment history');
    }

    public function test_paid_invoice_has_snapshot_and_downloads_real_pdf(): void
    {
        $this->put('/subscription/billing', $this->billingDetails());
        $id = $this->createOrder();
        $this->get('/subscription/invoices/'.$id)->assertNotFound();
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertOk();
        $this->put('/subscription/billing', array_replace($this->billingDetails(), ['name' => 'Changed Company']));
        $this->get('/subscription/invoices/'.$id)->assertOk()->assertSee('Original Billing Company')->assertDontSee('Changed Company')->assertSee('Starter')->assertSee('TEST-TAX-ID')->assertSee('pay_billing');
        $pdf = $this->get('/subscription/invoices/'.$id.'?download=1')->assertOk()->assertDownload()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get('/subscription')->assertSee('Download PDF');
    }

    public function test_invoice_cannot_be_accessed_from_another_business(): void
    {
        $this->put('/subscription/billing', $this->billingDetails());
        $id = $this->createOrder();
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertOk();
        $otherOwner = User::factory()->create();
        $other = app(BusinessService::class)->create($otherOwner, ['name' => 'Another business', 'description' => 'Consulting', 'timezone' => 'UTC']);
        $this->actingAs($otherOwner)->withSession(['business_id' => $other->id]);
        $this->get('/subscription/invoices/'.$id)->assertNotFound();
        $this->get('/subscription/invoices/'.$id.'?download=1')->assertNotFound();
        $this->get('/subscription')->assertDontSee('Original Billing Company')->assertDontSee('order_billing');
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertNotFound();
    }

    public function test_member_without_settings_permission_cannot_read_or_change_billing(): void
    {
        $member = User::factory()->create();
        $this->business->users()->attach($member->id, ['role' => 'member', 'permissions' => json_encode(['reports'])]);
        $this->actingAs($member)->withSession(['business_id' => $this->business->id]);
        $this->get('/subscription')->assertForbidden();
        $this->put('/subscription/billing', $this->billingDetails())->assertForbidden();
        $this->get('/subscription/invoices/1')->assertForbidden();
    }

    public function test_renewal_preserves_remaining_access_and_repeated_verification_does_not_extend_twice(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 15)->startOfDay());
        $plan = SubscriptionPlan::where('name', 'Starter')->firstOrFail();
        app(TenantContext::class)->run($this->business, fn () => Subscription::firstOrFail()->update(['subscription_plan_id' => $plan->id, 'ends_at' => '2027-01-31 00:00:00']));
        $this->put('/subscription/billing', $this->billingDetails());
        $this->createOrder();
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertOk();
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertOk();
        app(TenantContext::class)->run($this->business, function () {
            $this->assertSame('2027-02-28', Subscription::firstOrFail()->ends_at->format('Y-m-d'));
            $this->assertSame('2027-01-31', substr(Payment::firstOrFail()->metadata['invoice']['starts_at'], 0, 10));
        });
    }

    public function test_invalid_signature_and_wrong_amount_never_activate_or_create_invoice(): void
    {
        $this->put('/subscription/billing', $this->billingDetails());
        $id = $this->createOrder(100);
        $this->postJson('/subscription/verify', array_replace($this->paymentPayload(), ['razorpay_signature' => str_repeat('0', 64)]))->assertUnprocessable();
        $this->postJson('/subscription/verify', $this->paymentPayload())->assertUnprocessable();
        $this->get('/subscription/invoices/'.$id)->assertNotFound();
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseHas('subscriptions', ['business_id' => $this->business->id, 'subscription_plan_id' => SubscriptionPlan::where('name', 'Free')->firstOrFail()->id]);
    }

    public function test_dashboard_displays_active_expired_cancelled_and_free_subscription_states(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('No expiry date');
        app(TenantContext::class)->run($this->business, fn () => Subscription::firstOrFail()->update(['ends_at' => now()->addDays(3)]));
        $this->get('/dashboard')->assertOk()->assertSee('data-expiry=', false)->assertSee('days remaining');
        app(TenantContext::class)->run($this->business, fn () => Subscription::firstOrFail()->update(['ends_at' => now()->subDay()]));
        $this->get('/dashboard')->assertOk()->assertSee('Expired');
        app(TenantContext::class)->run($this->business, fn () => Subscription::firstOrFail()->update(['status' => 'cancelled']));
        $this->get('/dashboard')->assertOk()->assertSee('Access paused');
    }

    public function test_landing_prices_follow_active_database_plans(): void
    {
        SubscriptionPlan::where('name', 'Starter')->update(['monthly_price' => 1234, 'yearly_price' => 12340]);
        SubscriptionPlan::where('name', 'Agency')->update(['active' => false]);
        $this->get('/')->assertOk()->assertSee('1,234')->assertSee('12,340')->assertDontSee('Get Agency')->assertSee('Open the live demo');
    }
}
