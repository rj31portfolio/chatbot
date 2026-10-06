<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function(Blueprint $t) { $t->boolean('is_super_admin')->default(false); $t->string('status')->default('active'); });
        Schema::create('businesses', function(Blueprint $t) {
            $t->id(); $t->foreignId('owner_id')->constrained('users'); $t->string('name'); $t->string('slug')->unique();
            $t->string('industry')->nullable(); $t->text('description')->nullable(); $t->string('website_url',2048)->nullable();
            foreach(['phone','email','whatsapp','address','city','state','country','logo'] as $field) $t->string($field)->nullable();
            $t->string('timezone')->default('Asia/Kolkata'); $t->string('status')->default('active')->index(); $t->json('profile')->nullable(); $t->timestamps();
        });
        Schema::create('business_user', function(Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('member'); $t->json('permissions')->nullable(); $t->timestamps(); $t->unique(['business_id','user_id']);
        });
        Schema::create('subscription_plans', function(Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->decimal('monthly_price',12,2); $t->decimal('yearly_price',12,2)->default(0); $t->string('currency',3)->default('INR'); $t->json('limits'); $t->boolean('active')->default(true); $t->timestamps(); });
        $this->tenant('subscriptions', function(Blueprint $t) { $t->foreignId('subscription_plan_id')->constrained(); $t->string('status')->default('trial'); $t->string('interval')->default('monthly'); $t->timestamp('trial_ends_at')->nullable(); $t->timestamp('ends_at')->nullable(); $t->string('gateway')->nullable(); $t->string('gateway_reference')->nullable(); $t->unique('business_id'); });
        $this->tenant('subscription_usage', function(Blueprint $t) { $t->string('period',7); $t->string('metric'); $t->unsignedBigInteger('amount')->default(0); $t->unique(['business_id','period','metric']); });
        $this->tenant('knowledge_bases', fn(Blueprint $t) => $t->string('name'));
        $this->tenant('knowledge_documents', function(Blueprint $t) { $t->string('source_type')->index(); $t->string('title'); $t->longText('content'); $t->string('status')->default('ready'); $t->string('source_url',2048)->nullable(); $t->json('metadata')->nullable(); $t->unique(['business_id','id']); });
        $this->tenant('knowledge_chunks', function(Blueprint $t) { $t->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete(); $t->text('content'); $t->unsignedInteger('chunk_index'); $t->json('metadata')->nullable(); $t->unique(['document_id','chunk_index']); if (Schema::getConnection()->getDriverName()==='mysql') $t->fullText('content'); });
        $this->tenant('website_sources', function(Blueprint $t) { $t->string('url',2048); $t->string('status')->default('queued'); $t->unsignedInteger('pages_crawled')->default(0); $t->text('error')->nullable(); $t->timestamp('last_crawled_at')->nullable(); });
        $this->tenant('website_pages', function(Blueprint $t) { $t->foreignId('website_source_id')->constrained()->cascadeOnDelete(); $t->string('url',2048); $t->string('url_hash',64); $t->string('title'); $t->longText('content'); $t->unique(['website_source_id','url_hash']); });
        foreach(['business_services','business_products','business_faqs','business_policies'] as $table) $this->tenant($table, function(Blueprint $t) { $t->string('title'); $t->text('content'); $t->string('pricing')->nullable(); $t->foreignId('document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete(); });
        $this->tenant('ai_settings', function(Blueprint $t) { $t->string('tone')->default('professional'); $t->string('language')->default('English'); $t->text('instructions')->nullable(); $t->json('features'); $t->json('lead_fields'); $t->json('scoring'); $t->unsignedInteger('retention_days')->default(90); $t->unique('business_id'); });
        $this->tenant('business_settings', function(Blueprint $t) { $t->string('key'); $t->json('value'); $t->unique(['business_id','key']); });
        $this->tenant('chat_widgets', function(Blueprint $t) { $t->uuid('public_id')->unique(); $t->string('title')->default('How can we help?'); $t->string('color',7)->default('#f97316'); $t->text('welcome_message')->default('Hi! How can I help you today?'); $t->string('position')->default('right'); $t->json('settings')->nullable(); $t->boolean('active')->default(true); $t->boolean('is_demo')->default(false); $t->timestamp('installed_at')->nullable(); });
        $this->tenant('chat_widget_domains', function(Blueprint $t) { $t->foreignId('chat_widget_id')->constrained()->cascadeOnDelete(); $t->string('domain'); $t->unique(['chat_widget_id','domain']); });
        $this->tenant('visitors', function(Blueprint $t) { $t->uuid('public_id'); $t->string('landing_page',2048)->nullable(); $t->string('referrer',2048)->nullable(); $t->json('metadata')->nullable(); $t->unique(['business_id','public_id']); });
        $this->tenant('leads', function(Blueprint $t) {
            $t->uuid('public_id')->unique(); foreach(['name','email','phone','whatsapp','company','budget','timeline','location','service','product'] as $f) $t->string($f)->nullable();
            $t->text('requirement')->nullable(); $t->string('status')->default('new')->index(); $t->unsignedTinyInteger('score')->default(0)->index(); $t->string('temperature')->default('cold')->index();
            $t->string('source')->default('website_widget'); $t->json('utm')->nullable(); $t->json('signals')->nullable(); $t->text('summary')->nullable(); $t->text('recommended_action')->nullable();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->json('tags')->nullable();
        });
        $this->tenant('chat_sessions', function(Blueprint $t) { $t->uuid('public_id')->unique(); $t->foreignId('chat_widget_id')->constrained()->cascadeOnDelete(); $t->foreignId('visitor_id')->constrained()->cascadeOnDelete(); $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete(); $t->string('token_hash',64); $t->string('origin',2048); $t->string('status')->default('active')->index(); $t->string('page_url',2048)->nullable(); $t->json('metadata')->nullable(); $t->text('conversation_summary')->nullable(); $t->timestamp('last_activity')->nullable()->index(); $t->timestamp('expires_at'); });
        $this->tenant('chat_messages', function(Blueprint $t) { $t->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete(); $t->string('sender_type'); $t->text('message'); $t->string('intent')->nullable(); $t->unsignedInteger('tokens_used')->default(0); $t->string('model')->nullable(); $t->unsignedInteger('response_time')->nullable(); $t->json('citations')->nullable(); });
        $this->tenant('ai_usage_logs', function(Blueprint $t) { $t->string('model'); $t->unsignedInteger('input_tokens'); $t->unsignedInteger('output_tokens'); $t->unsignedInteger('total_tokens'); $t->decimal('estimated_cost',16,8)->default(0); $t->string('request_type'); $t->string('currency',3)->default('USD'); });
        $this->tenant('lead_scores', function(Blueprint $t) { $t->foreignId('lead_id')->constrained()->cascadeOnDelete(); $t->unsignedTinyInteger('score'); $t->json('breakdown'); });
        $this->tenant('lead_activities', function(Blueprint $t) { $t->foreignId('lead_id')->constrained()->cascadeOnDelete(); $t->string('type'); $t->text('description'); });
        $this->tenant('lead_notes', function(Blueprint $t) { $t->foreignId('lead_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained(); $t->text('content'); });
        $this->tenant('appointments', function(Blueprint $t) { $t->foreignId('lead_id')->constrained()->cascadeOnDelete(); $t->string('service'); $t->timestamp('starts_at'); $t->string('timezone'); $t->string('status')->default('requested'); $t->text('notes')->nullable(); });
        $this->tenant('webhook_endpoints', function(Blueprint $t) { $t->string('url',2048); $t->text('secret'); $t->json('events'); $t->boolean('active')->default(true); });
        $this->tenant('webhook_logs', function(Blueprint $t) { $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete(); $t->uuid('event_id'); $t->string('event'); $t->json('payload'); $t->unsignedInteger('attempts')->default(0); $t->string('status')->default('pending'); $t->unsignedSmallInteger('http_status')->nullable(); $t->text('error')->nullable(); $t->timestamp('next_attempt_at')->nullable(); $t->unique(['webhook_endpoint_id','event_id']); });
        $this->tenant('automation_rules', function(Blueprint $t) { $t->string('name'); $t->string('trigger'); $t->unsignedTinyInteger('min_score')->default(0); $t->string('action'); $t->json('settings')->nullable(); $t->boolean('active')->default(true); });
        $this->tenant('audit_logs', function(Blueprint $t) { $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->string('action'); $t->json('metadata')->nullable(); });
        $this->tenant('analytics_events', function(Blueprint $t) { $t->string('event'); $t->json('metadata')->nullable(); });
        $this->tenant('visitor_events', function(Blueprint $t) { $t->foreignId('visitor_id')->constrained()->cascadeOnDelete(); $t->string('event'); $t->json('metadata')->nullable(); });
        $this->tenant('analytics_daily', function(Blueprint $t) { $t->date('date'); $t->json('metrics'); $t->unique(['business_id','date']); });
        $this->tenant('payments', function(Blueprint $t) { $t->string('gateway'); $t->string('reference')->unique(); $t->decimal('amount',12,2); $t->string('currency',3); $t->string('status'); $t->decimal('gateway_fee',12,2)->default(0); });
        Schema::create('platform_settings', function(Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->json('value'); $t->timestamps(); });
        Schema::create('coupons', function(Blueprint $t) { $t->id(); $t->string('code')->unique(); $t->unsignedTinyInteger('percent'); $t->timestamp('expires_at')->nullable(); $t->unsignedInteger('max_redemptions')->default(1); $t->unsignedInteger('redemptions')->default(0); $t->timestamps(); });
        Schema::create('notifications', function(Blueprint $t) { $t->uuid('id')->primary(); $t->string('type'); $t->morphs('notifiable'); $t->text('data'); $t->timestamp('read_at')->nullable(); $t->timestamps(); });
    }
    private function tenant(string $name, callable $fields): void
    {
        Schema::create($name, function(Blueprint $t) use ($fields) { $t->id(); $t->foreignId('business_id')->constrained()->cascadeOnDelete(); $fields($t); $t->timestamps(); $t->index(['business_id','created_at']); });
    }
    public function down(): void
    {
        foreach(['notifications','coupons','platform_settings','payments','analytics_daily','visitor_events','analytics_events','audit_logs','automation_rules','webhook_logs','webhook_endpoints','appointments','lead_notes','lead_activities','lead_scores','ai_usage_logs','chat_messages','chat_sessions','leads','visitors','chat_widget_domains','chat_widgets','business_settings','ai_settings','business_policies','business_faqs','business_products','business_services','website_pages','website_sources','knowledge_chunks','knowledge_documents','knowledge_bases','subscription_usage','subscriptions','subscription_plans','business_user','businesses'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['is_super_admin','status']));
    }
};
