<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessService;
use App\Services\KnowledgeService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $this->user = User::factory()->create();
        $this->business = app(BusinessService::class)->create($this->user, ['name' => 'API Studio', 'description' => 'Consulting services', 'timezone' => 'UTC']);
    }

    private function token(array $abilities = ['knowledge']): string
    {
        $plaintext = Str::random(64);
        $token = $this->user->tokens()->create(['business_id' => $this->business->id, 'name' => 'Test', 'token' => hash('sha256', $plaintext), 'abilities' => array_merge(['workspace:'.$this->business->id], $abilities), 'expires_at' => now()->addDay()]);

        return $token->id.'|'.$plaintext;
    }

    public function test_api_requires_authentication_and_explicit_permissions(): void
    {
        $this->getJson('/api/business/knowledge')->assertUnauthorized();
        $token = $this->token();
        $this->withToken($token)->getJson('/api/business/knowledge')->assertOk();
        $this->getJson('/api/business/leads')->assertForbidden();
    }

    public function test_api_knowledge_crud_preserves_tenant_boundary(): void
    {
        $token = $this->token();
        $response = $this->withToken($token)->postJson('/api/business/knowledge', ['title' => 'API pricing', 'source_type' => 'pricing', 'content' => 'Consultations cost INR 2,000.'])->assertOk();
        $id = $response->json('data.id');
        $this->putJson('/api/business/knowledge/'.$id, ['title' => 'Updated', 'source_type' => 'pricing', 'content' => 'Consultations cost INR 3,000.'])->assertOk();
        $otherUser = User::factory()->create();
        $other = app(BusinessService::class)->create($otherUser, ['name' => 'Other API Studio', 'description' => 'Private knowledge', 'timezone' => 'UTC']);
        $document = app(TenantContext::class)->run($other, fn () => app(KnowledgeService::class)->save(['title' => 'Secret', 'source_type' => 'manual', 'content' => 'Private zebra details']));
        $this->putJson('/api/business/knowledge/'.$document->id, ['title' => 'Attack', 'source_type' => 'manual', 'content' => 'Should be rejected'])->assertNotFound();
        $this->getJson('/api/business/knowledge?business_id='.$other->id)->assertOk()->assertDontSee('Private zebra');
        $this->deleteJson('/api/business/knowledge/'.$id)->assertOk();
    }

    public function test_revoked_and_expired_tokens_do_not_authenticate(): void
    {
        $token = $this->token();
        $this->user->tokens()->first()->update(['expires_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/business/knowledge')->assertUnauthorized();
        $this->user->tokens()->delete();
        $this->getJson('/api/business/knowledge')->assertUnauthorized();
    }

    public function test_keys_can_be_created_and_revoked_in_workspace(): void
    {
        $this->actingAs($this->user)->withSession(['business_id' => $this->business->id])->get('/api-keys')->assertOk();
        $this->post('/api-keys', ['name' => 'Integration', 'abilities' => ['leads'], 'days' => 30])->assertRedirect()->assertSessionHas('created_token');
        $token = $this->user->tokens()->firstOrFail();
        $this->assertSame($this->business->id, $token->business_id);
        $this->delete('/api-keys/'.$token->id)->assertRedirect();
        $this->assertSame(0, $this->user->tokens()->count());
    }
}
