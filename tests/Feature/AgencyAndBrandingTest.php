<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{User,Business,Subscription,SubscriptionPlan,ChatWidget};
use App\Services\{BusinessService,WidgetService};
use App\Support\TenantContext;
class AgencyAndBrandingTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private Business $business;
    protected function setUp(): void
    {
        parent::setUp();$this->withoutVite();$this->seed(\Database\Seeders\PlanSeeder::class);
        $this->owner=User::factory()->create();$this->business=app(BusinessService::class)->create($this->owner,['name'=>'Agency Workspace','description'=>'Custom business','timezone'=>'UTC']);
    }
    public function test_free_plan_business_limit_and_agency_creation_are_enforced(): void
    {
        $this->actingAs($this->owner)->withSession(['business_id'=>$this->business->id]);
        $this->post('/business',['name'=>'Second','description'=>'New client business','timezone'=>'UTC'])->assertSessionHasErrors('business');
        app(TenantContext::class)->run($this->business,fn()=>Subscription::firstOrFail()->update(['subscription_plan_id'=>SubscriptionPlan::where('name','Agency')->firstOrFail()->id]));
        $this->post('/business',['name'=>'Second','description'=>'New client business','timezone'=>'UTC'])->assertRedirect('/training');
        $this->get('/agency')->assertOk()->assertSee('Agency Workspace')->assertSee('Second');
    }
    public function test_tenant_branding_is_plan_gated_and_does_not_change_another_workspace(): void
    {
        $this->actingAs($this->owner)->withSession(['business_id'=>$this->business->id]);
        $this->put('/branding',['brand'=>'Custom Agency','primary_color'=>'#0055aa'])->assertSessionHasErrors('plan');
        app(TenantContext::class)->run($this->business,fn()=>Subscription::firstOrFail()->update(['subscription_plan_id'=>SubscriptionPlan::where('name','Professional')->firstOrFail()->id]));
        $this->put('/branding',['brand'=>'Custom Agency','primary_color'=>'#0055aa'])->assertRedirect();$this->get('/dashboard')->assertOk()->assertSee('Custom Agency');
        app(TenantContext::class)->run($this->business,function() { $config=app(WidgetService::class)->config(ChatWidget::firstOrFail());$this->assertSame('Custom Agency',$config['brand']); });
        $other=User::factory()->create();$business=app(BusinessService::class)->create($other,['name'=>'Other Studio','description'=>'Separate business','timezone'=>'UTC']);
        $this->actingAs($other)->withSession(['business_id'=>$business->id])->get('/dashboard')->assertOk()->assertDontSee('Custom Agency');
    }
    public function test_suspended_accounts_can_log_out_and_cannot_create_businesses(): void
    {
        $this->owner->forceFill(['status'=>'suspended'])->save();$this->actingAs($this->owner)->get('/business/create')->assertForbidden();$this->post('/logout')->assertRedirect('/login');$this->assertGuest();
    }
}
