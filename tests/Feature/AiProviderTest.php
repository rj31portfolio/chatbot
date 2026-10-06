<?php

namespace Tests\Feature;

use App\AI\AIProviderInterface;
use App\AI\AIProviderManager;
use App\AI\AIResponse;
use App\Jobs\ProcessKnowledgeUpload;
use App\Jobs\SummarizeConversation;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ChatWidget;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\User;
use App\Services\AIConversationService;
use App\Services\AIService;
use App\Services\BusinessService;
use App\Services\KnowledgeService;
use App\Services\LeadService;
use App\Services\WidgetService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
        Notification::fake();
        $user = User::factory()->create();
        $this->business = app(BusinessService::class)->create($user, ['name' => 'Provider Test Studio', 'description' => 'Website development agency', 'timezone' => 'Asia/Kolkata']);
        app(TenantContext::class)->set($this->business);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function chatSession(): ChatSession
    {
        $data = app(WidgetService::class)->start(ChatWidget::firstOrFail(), [], 'https://example.com');

        return ChatSession::where('public_id', $data['session_id'])->firstOrFail();
    }

    public function test_deepseek_uses_server_key_and_selected_context(): void
    {
        config(['ai.providers.deepseek.api_key' => 'test-server-secret']);
        app(KnowledgeService::class)->save(['source_type' => 'pricing', 'title' => 'Website pricing', 'content' => 'Website development pricing starts at INR 25,000.']);
        app(KnowledgeService::class)->save(['source_type' => 'manual', 'title' => 'Unrelated private fact', 'content' => 'An unrelated zebra fact about purple animals.']);
        Http::fake(['api.deepseek.com/*' => Http::response(['model' => 'deepseek-chat', 'choices' => [['message' => ['content' => 'Website development starts at INR 25,000.']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]])]);
        $response = app(AIService::class)->generateResponse($this->chatSession(), 'Website pricing?');
        $this->assertStringContainsString('25,000', $response['text']);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return $request->hasHeader('Authorization', 'Bearer test-server-secret') && str_contains($body, '25,000') && ! str_contains($body, 'unrelated zebra') && ! str_contains($body, 'test-server-secret');
        });
        $this->assertSame(120, (int) AiUsageLog::sum('total_tokens'));
    }

    public function test_contact_details_are_extracted_and_scored_from_natural_conversation(): void
    {
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldReceive('complete')->with(\Mockery::type('array'), false)->once()->andReturn(new AIResponse('Thanks Alex. Our team can discuss your website.', 'test', 60, 20));
        $provider->shouldReceive('complete')->with(\Mockery::type('array'), true)->once()->andReturn(new AIResponse('{"name":"Alex","phone":"+91 9876543210","requirement":"Website development","service":"Website development","timeline":"This month"}', 'test', 80, 40));
        $this->app->instance(AIProviderInterface::class, $provider);
        $s = $this->chatSession();
        app(AIConversationService::class)->reply($s, 'My name is Alex. I need website development this month. My phone is +91 9876543210.');
        $lead = Lead::firstOrFail();
        $this->assertSame('Alex', $lead->name);
        $this->assertSame(55, $lead->score);
        $this->assertSame($lead->id, $s->fresh()->lead_id);
    }

    public function test_summary_job_stores_ai_summary_on_conversation_and_lead(): void
    {
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn(new AIResponse('The visitor needs a website this month. Suggested next step: call.', 'test', 40, 20));
        $this->app->instance(AIProviderInterface::class, $provider);
        $s = $this->chatSession();
        $lead = app(LeadService::class)->capture($s, ['name' => 'Alex', 'phone' => '9876543210', 'requirement' => 'A website this month']);
        ChatMessage::create(['session_id' => $s->id, 'sender_type' => 'visitor', 'message' => 'I need a website this month.']);
        (new SummarizeConversation($this->business->id, $s->id))->handle();
        $this->assertSame($s->fresh()->conversation_summary, $lead->fresh()->summary);
        $this->assertStringContainsString('website this month', $lead->fresh()->summary);
    }

    public function test_pricing_off_is_enforced_without_an_ai_call(): void
    {
        AiSetting::firstOrFail()->update(['features' => ['pricing' => false, 'capture' => false, 'handoff' => true]]);
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldNotReceive('complete');
        $this->app->instance(AIProviderInterface::class, $provider);
        $response = app(AIConversationService::class)->reply($this->chatSession(), 'What is your pricing?');
        $this->assertSame('Please contact our team for current pricing.', $response['message']);
    }

    public function test_safe_static_answer_cache_is_tenant_and_context_specific(): void
    {
        app(KnowledgeService::class)->save(['title' => 'Working hours', 'source_type' => 'faq', 'content' => 'Our working hours are Monday to Friday, 9 AM to 6 PM.']);
        $provider = \Mockery::mock(AIProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn(new AIResponse('Monday to Friday, 9 AM to 6 PM.', 'test', 60, 15));
        $this->app->instance(AIProviderInterface::class, $provider);
        $a = app(AIService::class)->generateResponse($this->chatSession(), 'What are your working hours?');
        $b = app(AIService::class)->generateResponse($this->chatSession(), 'What are your working hours?');
        $this->assertSame($a['text'], $b['text']);
        $this->assertSame(0, $b['tokens']);
        $this->assertSame(1, AiUsageLog::count());
    }

    public function test_configured_fallback_is_opt_in(): void
    {
        config(['ai.provider' => 'broken', 'ai.fallback' => 'working', 'ai.provider_classes' => ['broken' => BrokenTestProvider::class, 'working' => WorkingTestProvider::class]]);
        $r = app(AIProviderManager::class)->complete([['role' => 'user', 'content' => 'Hello']]);
        $this->assertSame('fallback reply', $r->text);
        config(['ai.fallback' => null]);
        $this->expectException(\RuntimeException::class);
        app(AIProviderManager::class)->complete([]);
    }

    public function test_document_upload_job_chunks_text_and_deletes_temporary_file(): void
    {
        Storage::fake('local');
        $path = 'knowledge-uploads/test.txt';
        Storage::disk('local')->put($path, 'Website development pricing starts at INR 25,000.');
        $doc = KnowledgeDocument::create(['title' => 'Upload', 'source_type' => 'document', 'content' => '', 'status' => 'processing']);
        (new ProcessKnowledgeUpload($this->business->id, $doc->id, $path, 'txt'))->handle();
        $this->assertSame('ready', $doc->fresh()->status);
        $this->assertSame(1, $doc->chunks()->count());
        Storage::disk('local')->assertMissing($path);
    }
}
class BrokenTestProvider implements AIProviderInterface
{
    public function complete(array $messages, bool $json = false): AIResponse
    {
        throw new \RuntimeException('Provider offline.');
    }
}
class WorkingTestProvider implements AIProviderInterface
{
    public function complete(array $messages,bool $json = false): AIResponse
    {
        return new AIResponse('fallback reply','fallback',5,5);
    }
}
