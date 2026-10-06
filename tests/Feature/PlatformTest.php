<?php

namespace Tests\Feature;

use App\AI\AIProviderInterface;
use App\AI\AIResponse;
use App\Billing\PaymentGatewayInterface;
use App\Jobs\DeliverWebhook;
use App\Jobs\SummarizeConversation;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ChatWidget;
use App\Models\ChatWidgetDomain;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Models\WebsiteSource;
use App\Notifications\BusinessAlert;
use App\Services\AIService;
use App\Services\BusinessService;
use App\Services\KnowledgeService;
use App\Services\LeadScoringService;
use App\Services\SafeHttpService;
use App\Services\WebsiteCrawlerService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private string $widgetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        Queue::fake();
        Notification::fake();
        $this->owner = User::factory()->create(['name' => 'Test Owner']);
        $this->business = app(BusinessService::class)->create($this->owner, ['name' => 'Example Business', 'industry' => 'Custom industry', 'description' => 'We provide website development for small businesses.', 'website_url' => 'https://example.com', 'timezone' => 'Asia/Kolkata']);
        $this->tenant(function () {
            $widget = ChatWidget::firstOrFail();
            $this->widgetId = $widget->public_id;
            ChatWidgetDomain::firstOrCreate(['chat_widget_id' => $widget->id, 'domain' => 'example.com']);
        });
    }

    private function tenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->business, $fn);
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner)->withSession(['business_id' => $this->business->id]);
    }

    private function widgetHeaders(?string $token = null): array
    {
        return array_filter(['Origin' => 'https://example.com', 'X-Widget-Id' => $this->widgetId, 'Authorization' => $token ? 'Bearer '.$token : null]);
    }

    private function start(): array
    {
        return $this->postJson('/api/widget/session', ['page_url' => 'https://example.com/services'], $this->widgetHeaders())->assertOk()->json('data');
    }

    private function fakeAI(string $text = 'Our website development starts at INR 25,000.'): void
    {
        $this->app->instance(AIProviderInterface::class, new class($text) implements AIProviderInterface
        {
            public function __construct(private string $text) {}

            public function complete(array $messages, bool $json = false): AIResponse
            {
                return new AIResponse($json ? '{}' : $this->text, 'test-model', 100, 25);
            }
        });
    }

    public function test_public_pages_and_all_owner_screens_render(): void
    {
        foreach (['/', '/login', '/register', '/privacy', '/terms', '/demo'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->asOwner();
        foreach (['/dashboard', '/training', '/manage/knowledge', '/manage/website', '/manage/services', '/manage/products', '/manage/faqs', '/manage/policies', '/manage/integrations', '/manage/automations', '/chatbot', '/widget', '/installation', '/tester', '/leads', '/conversations', '/appointments', '/analytics', '/team', '/subscription', '/settings', '/business/create'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_authentication_and_registration(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/register', ['name' => 'New Owner', 'email' => 'new@example.test', 'password' => 'StrongPass12345', 'password_confirmation' => 'StrongPass12345', 'terms' => '1'])->assertRedirect('/business/create');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'is_super_admin' => 0]);
    }

    public function test_business_creation_connects_subscription_settings_and_widget(): void
    {
        $this->asOwner()->post('/business', ['name' => 'Custom Wellness', 'industry' => 'My own category', 'description' => 'We offer wellbeing consultations.', 'timezone' => 'Asia/Kolkata'])->assertRedirect('/training');
        $new = Business::where('name', 'Custom Wellness')->firstOrFail();
        $this->assertDatabaseHas('subscriptions', ['business_id' => $new->id]);
        $this->assertDatabaseHas('ai_settings', ['business_id' => $new->id]);
        $this->assertDatabaseHas('chat_widgets', ['business_id' => $new->id]);
    }

    public function test_admin_is_protected_and_admin_screens_render(): void
    {
        $this->asOwner()->get('/admin')->assertForbidden();
        $this->owner->forceFill(['is_super_admin' => true])->save();
        foreach (['/admin', '/admin/users', '/admin/plans', '/admin/settings', '/admin/subscriptions'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_missing_tenant_context_fails_closed(): void
    {
        $this->expectException(\LogicException::class);
        Lead::count();
    }

    public function test_other_business_cannot_access_leads_or_knowledge(): void
    {
        $otherOwner = User::factory()->create();
        $other = app(BusinessService::class)->create($otherOwner, ['name' => 'Other', 'description' => 'Other business facts.', 'timezone' => 'UTC']);
        [$lead,$document] = app(TenantContext::class)->run($other, function () {
            return [Lead::create(['public_id' => (string) Str::uuid(), 'name' => 'Private Lead']), app(KnowledgeService::class)->save(['title' => 'Secret', 'source_type' => 'manual', 'content' => 'private confidential zebra'])];
        });
        $this->asOwner()->get('/leads/'.$lead->public_id)->assertNotFound();
        $this->put('/manage/knowledge/'.$document->id, ['title' => 'Changed', 'source_type' => 'manual', 'content' => 'Attempt'])->assertNotFound();
        $this->tenant(fn () => $this->assertEmpty(app(KnowledgeService::class)->search('confidential zebra')));
    }

    public function test_tampering_with_selected_business_does_not_grant_access(): void
    {
        $otherOwner = User::factory()->create();
        $other = app(BusinessService::class)->create($otherOwner, ['name' => 'Other', 'description' => 'Private company', 'timezone' => 'UTC']);
        $this->actingAs($this->owner)->withSession(['business_id' => $other->id])->get('/dashboard')->assertOk()->assertSee('Example Business')->assertDontSee('Private company');
        $this->post('/business/switch', ['business_id' => $other->id])->assertNotFound();
    }

    public function test_team_permissions_are_enforced(): void
    {
        $member = User::factory()->create();
        $this->business->users()->attach($member->id, ['role' => 'member', 'permissions' => json_encode(['leads'])]);
        $this->actingAs($member)->withSession(['business_id' => $this->business->id])->get('/leads')->assertOk();
        $this->get('/manage/knowledge')->assertForbidden();
        $this->post('/manage/services', ['title' => 'Unauthorized', 'content' => 'No'])->assertForbidden();
        $this->get('/settings')->assertForbidden();
    }

    public function test_services_and_faqs_generate_searchable_knowledge_and_updates_rechunk(): void
    {
        $this->asOwner()->post('/manage/services', ['title' => 'Website development', 'content' => 'Responsive websites with custom lead forms.', 'pricing' => 'INR 25,000'])->assertRedirect('/manage/services');
        $this->post('/manage/faqs', ['title' => 'Working hours', 'content' => 'We are open Monday to Friday.'])->assertRedirect('/manage/faqs');
        $this->tenant(function () {
            $chunks = app(KnowledgeService::class)->search('website pricing');
            $this->assertNotEmpty($chunks);
            $this->assertStringContainsString('25,000', implode(' ', array_column($chunks, 'content')));
            $doc = KnowledgeDocument::where('source_type', 'service')->firstOrFail();
            app(KnowledgeService::class)->save(['content' => 'Replacement content'], $doc);
            $this->assertSame(1, $doc->chunks()->count());
        });
    }

    public function test_widget_domain_and_status_validation(): void
    {
        $this->getJson('/api/widget/config', ['Origin' => 'https://evil.example', 'X-Widget-Id' => $this->widgetId])->assertForbidden();
        $this->getJson('/api/widget/config', ['X-Widget-Id' => $this->widgetId])->assertForbidden();
        $this->getJson('/api/widget/config', $this->widgetHeaders())->assertOk()->assertJsonPath('data.title', 'Example Business assistant')->assertJsonMissingPath('data.api_key')->assertJsonMissingPath('data.instructions');
        $this->business->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/widget/config', $this->widgetHeaders())->assertNotFound();
    }

    public function test_session_tokens_are_required_and_bound_to_widget(): void
    {
        $session = $this->start();
        $this->postJson('/api/widget/message', ['session_id' => $session['session_id'], 'message' => 'Hello'], $this->widgetHeaders('bad-token'))->assertUnauthorized();
        $this->tenant(function () use ($session) {
            $stored = ChatSession::where('public_id', $session['session_id'])->firstOrFail();
            $this->assertNotSame($session['token'], $stored->token_hash);
        });
        $this->fakeAI('Hello, how can I help?');
        $this->postJson('/api/widget/message', ['session_id' => $session['session_id'], 'message' => 'Hello'], $this->widgetHeaders($session['token']))->assertOk();
    }

    public function test_subscription_expiry_blocks_widget(): void
    {
        $this->tenant(fn () => Subscription::firstOrFail()->update(['status' => 'expired']));
        $this->getJson('/api/widget/config', $this->widgetHeaders())->assertUnprocessable()->assertJsonPath('success', false);
    }

    public function test_usage_limits_prevent_provider_calls(): void
    {
        $session = $this->start();
        $this->tenant(fn () => SubscriptionUsage::create(['period' => now()->format('Y-m'), 'metric' => 'ai_messages', 'amount' => 300]));
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldNotReceive('complete');
        $this->app->instance(AIProviderInterface::class, $provider);
        $this->postJson('/api/widget/message', ['session_id' => $session['session_id'], 'message' => 'Hello'], $this->widgetHeaders($session['token']))->assertUnprocessable();
    }

    public function test_provider_failure_refunds_reserved_usage_and_returns_friendly_error(): void
    {
        $session = $this->start();
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andThrow(new \RuntimeException('private key must not leak'));
        $this->app->instance(AIProviderInterface::class, $provider);
        $response = $this->postJson('/api/widget/message', ['session_id' => $session['session_id'], 'message' => 'Hello'], $this->widgetHeaders($session['token']))->assertStatus(503)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('private key', $response->getContent());
        $this->tenant(fn () => $this->assertSame(0, (int) SubscriptionUsage::where('metric', 'ai_tokens')->value('amount')));
    }

    public function test_ai_retrieves_business_pricing_tracks_usage_and_hides_citations_from_visitors(): void
    {
        $this->tenant(fn () => app(KnowledgeService::class)->save(['title' => 'Website pricing', 'source_type' => 'pricing', 'content' => 'Website development pricing starts at INR 25,000.']));
        $this->fakeAI();
        $session = $this->start();
        $this->postJson('/api/widget/message', ['session_id' => $session['session_id'], 'message' => 'What is website development pricing?'], $this->widgetHeaders($session['token']))->assertOk()->assertJsonPath('data.message', 'Our website development starts at INR 25,000.')->assertJsonMissingPath('data.citations');
        $this->tenant(function () {
            $this->assertSame(125, (int) AiUsageLog::sum('total_tokens'));
            $this->assertSame(125, (int) SubscriptionUsage::where('metric', 'ai_tokens')->value('amount'));
            $this->assertNotEmpty(ChatMessage::where('sender_type', 'ai')->firstOrFail()->citations);
        });
    }

    public function test_unknown_question_does_not_call_provider(): void
    {
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldNotReceive('complete');
        $this->app->instance(AIProviderInterface::class, $provider);
        $s = $this->start();
        $this->postJson('/api/widget/message', ['session_id' => $s['session_id'], 'message' => 'Do you sell quantum helicopters?'], $this->widgetHeaders($s['token']))->assertOk()->assertJsonPath('data.message', "I don't have enough information to answer that accurately. I can connect you with the team.");
    }

    public function test_lead_capture_scores_lead_notifies_and_enqueues_signed_webhook(): void
    {
        $this->tenant(fn () => WebhookEndpoint::create(['url' => 'https://receiver.example/webhook', 'secret' => str_repeat('s', 40), 'events' => ['lead.created']]));
        $s = $this->start();
        $this->postJson('/api/widget/lead', ['session_id' => $s['session_id'], 'name' => 'Alex', 'phone' => '+91 9876543210', 'requirement' => 'A business website', 'service' => 'Website development', 'budget' => 'INR 50,000', 'timeline' => 'This month', 'consent' => '1'], $this->widgetHeaders($s['token']))->assertOk();
        $this->tenant(function () {
            $lead = Lead::firstOrFail();
            $this->assertSame(65, $lead->score);
            $this->assertSame('hot', $lead->temperature);
            $this->assertSame(1, WebhookLog::count());
        });
        Notification::assertSentTo($this->owner, BusinessAlert::class);
        Queue::assertPushed(DeliverWebhook::class);
        $this->asOwner()->get('/leads')->assertOk()->assertSee('Alex');
    }

    public function test_lead_capture_requires_consent_and_contact_information(): void
    {
        $s = $this->start();
        $this->postJson('/api/widget/lead', ['session_id' => $s['session_id'], 'name' => 'Alex'], $this->widgetHeaders($s['token']))->assertUnprocessable()->assertJsonValidationErrors(['consent', 'email', 'phone']);
    }

    public function test_scoring_is_configurable_and_capped(): void
    {
        $this->tenant(function () {
            AiSetting::firstOrFail()->update(['scoring' => ['name' => 100, 'phone' => 100]]);
            $r = app(LeadScoringService::class)->calculate(new Lead(['name' => 'Alex', 'phone' => '9876543210']));
            $this->assertSame(100, $r['score']);
            $this->assertSame('very_hot', $r['temperature']);
        });
    }

    public function test_appointment_is_requested_not_automatically_confirmed(): void
    {
        $s = $this->start();
        $h = $this->widgetHeaders($s['token']);
        $this->postJson('/api/widget/lead', ['session_id' => $s['session_id'], 'email' => 'alex@example.test', 'consent' => '1'], $h)->assertOk();
        $this->postJson('/api/widget/appointment', ['session_id' => $s['session_id'], 'service' => 'Consultation', 'starts_at' => now()->addDay()->toIso8601String(), 'timezone' => 'Asia/Kolkata'], $h)->assertOk()->assertJsonPath('data.status', 'requested');
        $this->tenant(fn () => $this->assertSame('requested', Appointment::firstOrFail()->status));
    }

    public function test_human_reply_is_visible_to_visitor_and_bot_pauses(): void
    {
        $s = $this->start();
        $this->asOwner()->post('/conversations/'.$s['session_id'].'/reply', ['message' => 'Hello, this is the owner.'])->assertRedirect();
        $this->postJson('/api/widget/history', ['session_id' => $s['session_id']], $this->widgetHeaders($s['token']))->assertOk()->assertJsonPath('data.messages.0.sender_type', 'human');
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldNotReceive('complete');
        $this->app->instance(AIProviderInterface::class, $provider);
        $this->postJson('/api/widget/message', ['session_id' => $s['session_id'], 'message' => 'Thanks'], $this->widgetHeaders($s['token']))->assertOk();
    }

    public function test_summary_job_is_dispatched_when_conversation_ends(): void
    {
        $s = $this->start();
        $this->postJson('/api/widget/end', ['session_id' => $s['session_id']], $this->widgetHeaders($s['token']))->assertOk();
        Queue::assertPushed(SummarizeConversation::class);
    }

    public function test_private_network_crawling_is_rejected(): void
    {
        foreach (['http://127.0.0.1', 'http://10.0.0.1', 'http://100.100.100.200/latest/meta-data', 'http://169.254.169.254/latest/meta-data', 'http://[::1]', 'ftp://example.com', 'http://example.com:8080', 'https://user:password@example.com'] as $url) {
            try {
                app(SafeHttpService::class)->resolve($url);
                $this->fail('Unsafe URL accepted: '.$url);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_robots_longest_rule_and_specific_agent_are_respected(): void
    {
        $crawler = app(WebsiteCrawlerService::class);
        $robots = "User-agent: *\nDisallow: /private\nAllow: /private/public\n";
        $this->assertFalse($crawler->allowed($robots, '/private/data'));
        $this->assertTrue($crawler->allowed($robots, '/private/public/page'));
        $this->assertTrue($crawler->allowed($robots, '/services'));
        $this->assertFalse($crawler->allowed("User-agent: *\nAllow: /\nUser-agent: AILeadAgentBot\nDisallow: /\n", '/services'));
    }

    public function test_crawler_extracts_public_pages_into_knowledge_and_skips_disallowed_pages(): void
    {
        $safe = \Mockery::mock(SafeHttpService::class);
        $safe->shouldReceive('resolve')->andReturn(['example.com', 443, '93.184.216.34']);
        Http::fake(['https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /private", 200), 'https://example.com' => Http::response('<title>Example</title><h1>Website development</h1><p>Pricing INR 25,000</p><a href="/private">private</a>', 200, ['Content-Type' => 'text/html'])]);
        $safe->shouldReceive('fetch')->with('https://example.com/robots.txt')->andReturn(Http::get('https://example.com/robots.txt'));
        $safe->shouldReceive('fetch')->with('https://example.com')->andReturn(Http::get('https://example.com'));
        $this->app->instance(SafeHttpService::class, $safe);
        $this->tenant(function () {
            $source = WebsiteSource::create(['url' => 'https://example.com']);
            app(WebsiteCrawlerService::class)->crawl($source);
            $this->assertSame('completed', $source->fresh()->status);
            $this->assertSame(1, $source->fresh()->pages_crawled);
            $this->assertNotEmpty(app(KnowledgeService::class)->search('pricing'));
        });
    }

    public function test_webhook_signature_matches_exact_sent_body(): void
    {
        $this->tenant(function () {
            $endpoint = WebhookEndpoint::create(['url' => 'https://receiver.example/events', 'secret' => str_repeat('k', 40), 'events' => ['lead.created']]);
            $log = WebhookLog::create(['webhook_endpoint_id' => $endpoint->id, 'event_id' => (string) Str::uuid(), 'event' => 'lead.created', 'payload' => ['name' => 'Élodie', 'url' => 'https://example.com/path']]);
            $safe = \Mockery::mock(SafeHttpService::class);
            $safe->shouldReceive('fetch')->once()->andReturnUsing(function ($url, $method, $payload, $headers) use ($endpoint) {
                $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->assertSame(hash_hmac('sha256', $headers['X-AI-Lead-Timestamp'].'.'.$canonical, $endpoint->secret), $headers['X-AI-Lead-Signature']);
                Http::fake(['https://receiver.example/*' => Http::response('ok', 200)]);

                return Http::get($url);
            });
            $this->app->instance(SafeHttpService::class, $safe);
            (new DeliverWebhook($this->business->id, $log->id))->handle();
            $this->assertSame('delivered', $log->fresh()->status);
        });
    }

    public function test_invalid_ai_json_is_rejected_without_creating_a_lead(): void
    {
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldReceive('complete')->andReturn(new AIResponse('not json', 'test', 10, 5));
        $this->app->instance(AIProviderInterface::class, $provider);
        $this->tenant(function () {
            try {
                app(AIService::class)->extractLeadData('My email is alex@example.test');
                $this->fail('Invalid JSON accepted');
            } catch (\JsonException) {
                $this->assertSame(0, Lead::count());
            }
        });
    }

    public function test_plan_limits_are_editable_by_admin(): void
    {
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $this->asOwner();
        $limits = config('saas.plans.Free');
        unset($limits['price']);
        $limits['conversations'] = 2;
        $this->put('/admin/plans/'.SubscriptionPlan::where('name', 'Free')->firstOrFail()->id, ['name' => 'Free', 'monthly_price' => 0, 'yearly_price' => 0, 'currency' => 'INR', 'limits' => $limits, 'active' => '1'])->assertRedirect();
        $this->start();
        $this->start();
        $this->postJson('/api/widget/session', [], $this->widgetHeaders())->assertUnprocessable();
    }

    public function test_payment_is_verified_before_subscription_activation(): void
    {
        $plan = SubscriptionPlan::where('name', 'Starter')->firstOrFail();
        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('createOrder')->once()->with(99900, 'INR', \Mockery::type('string'))->andReturn(['id' => 'order_test', 'amount' => 99900, 'currency' => 'INR']);
        $gateway->shouldReceive('verify')->once()->andReturn(['status' => 'captured', 'order_id' => 'order_test', 'amount' => 99900, 'currency' => 'INR', 'fee' => 200]);
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $this->asOwner()->postJson('/subscription/order',['plan_id' => $plan->id, 'interval' => 'monthly'])->assertOk();
        $this->tenant(fn () => $this->assertSame('Free',Subscription::firstOrFail()->plan->name));
        $this->postJson('/subscription/verify',['razorpay_order_id' => 'order_test', 'razorpay_payment_id' => 'pay_test', 'razorpay_signature' => str_repeat('x',64)])->assertOk();
        $this->tenant(fn () => $this->assertSame('Starter',Subscription::firstOrFail()->plan->name));
    }
}
