<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{User,Business,SubscriptionPlan,Coupon,Payment};
use App\Services\BusinessService;
class ExportsAndCouponsTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private Business $business;
    protected function setUp(): void
    {
        parent::setUp();$this->withoutVite();$this->seed(\Database\Seeders\PlanSeeder::class);$this->user=User::factory()->create();$this->business=app(BusinessService::class)->create($this->user,['name'=>'Export Studio','description'=>'Consulting services','timezone'=>'UTC']);$this->actingAs($this->user)->withSession(['business_id'=>$this->business->id]);
    }
    public function test_csv_and_pdf_exports_are_real_downloads(): void
    {
        foreach(['leads','conversations','analytics'] as $type) {
            $this->get('/export/'.$type)->assertOk()->assertDownload();
            $pdf=$this->get('/export/'.$type.'?format=pdf')->assertOk()->assertDownload()->assertHeader('Content-Type','application/pdf');$this->assertStringStartsWith('%PDF-',$pdf->getContent());
        }
    }
    public function test_coupon_discount_is_reserved_atomically_and_stored_on_order(): void
    {
        $coupon=Coupon::create(['code'=>'SAVE20','percent'=>20,'max_redemptions'=>1]);$plan=SubscriptionPlan::where('name','Starter')->firstOrFail();
        $gateway=\Mockery::mock(\App\Billing\PaymentGatewayInterface::class);$gateway->shouldReceive('createOrder')->once()->with(79920,'INR',\Mockery::type('string'))->andReturn(['id'=>'discount_order','amount'=>79920,'currency'=>'INR']);$this->app->instance(\App\Billing\PaymentGatewayInterface::class,$gateway);
        $this->postJson('/subscription/order',['plan_id'=>$plan->id,'interval'=>'monthly','coupon'=>'save20'])->assertOk();$this->assertSame(1,$coupon->fresh()->redemptions);$this->assertDatabaseHas('payments',['reference'=>'discount_order','amount'=>799.2]);
        $this->postJson('/subscription/order',['plan_id'=>$plan->id,'interval'=>'monthly','coupon'=>'SAVE20'])->assertUnprocessable();
    }
    public function test_failed_gateway_does_not_consume_coupon_use(): void
    {
        $coupon=Coupon::create(['code'=>'FAIL10','percent'=>10,'max_redemptions'=>1]);$plan=SubscriptionPlan::where('name','Starter')->firstOrFail();$gateway=\Mockery::mock(\App\Billing\PaymentGatewayInterface::class);$gateway->shouldReceive('createOrder')->andThrow(new \RuntimeException('Gateway offline'));$this->app->instance(\App\Billing\PaymentGatewayInterface::class,$gateway);
        $this->postJson('/subscription/order',['plan_id'=>$plan->id,'interval'=>'monthly','coupon'=>'FAIL10'])->assertStatus(503);$this->assertSame(0,$coupon->fresh()->redemptions);
    }
    public function test_admin_creation_command_has_no_default_credentials(): void
    {
        $this->artisan('app:create-admin')->expectsQuestion('Admin email','admin@example.test')->expectsQuestion('Password (at least 12 characters, letters and numbers)','StrongAdmin12345')->expectsQuestion('Admin name','Test Administrator')->expectsOutput('Super admin created.')->assertSuccessful();$this->assertDatabaseHas('users',['email'=>'admin@example.test','is_super_admin'=>true]);
    }
}
