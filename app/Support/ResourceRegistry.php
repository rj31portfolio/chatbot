<?php

namespace App\Support;

use App\Models;
use Illuminate\Http\Request;

class ResourceRegistry
{
    public static function all(): array
    {
        return [
            'knowledge' => ['title' => 'Knowledge base', 'description' => 'Give your AI reliable information to answer from.', 'model' => Models\KnowledgeDocument::class, 'permission' => 'knowledge', 'fields' => ['title' => 'text', 'source_type' => 'select:manual,pricing,profile,document', 'content' => 'textarea'], 'rules' => ['title' => 'required|string|max:255', 'source_type' => 'required|in:manual,pricing,profile,document', 'content' => 'required|string|max:100000']],
            'services' => ['title' => 'Services', 'description' => 'Teach your assistant what you offer and how it is priced.', 'model' => Models\BusinessService::class, 'permission' => 'knowledge', 'source' => 'service', 'fields' => ['title' => 'text', 'content' => 'textarea', 'pricing' => 'text'], 'rules' => ['title' => 'required|string|max:255', 'content' => 'required|string|max:10000', 'pricing' => 'nullable|string|max:255']],
            'products' => ['title' => 'Products', 'description' => 'Add product details, availability, and verified prices.', 'model' => Models\BusinessProduct::class, 'permission' => 'knowledge', 'source' => 'product', 'fields' => ['title' => 'text', 'content' => 'textarea', 'pricing' => 'text'], 'rules' => ['title' => 'required|string|max:255', 'content' => 'required|string|max:10000', 'pricing' => 'nullable|string|max:255']],
            'faqs' => ['title' => 'FAQs', 'description' => 'Turn common questions into accurate answers.', 'model' => Models\BusinessFaq::class, 'permission' => 'knowledge', 'source' => 'faq', 'fields' => ['title' => 'text', 'content' => 'textarea'], 'rules' => ['title' => 'required|string|max:255', 'content' => 'required|string|max:10000']],
            'policies' => ['title' => 'Policies', 'description' => 'Document payment, returns, cancellations, and other business rules.', 'model' => Models\BusinessPolicy::class, 'permission' => 'knowledge', 'source' => 'policy', 'fields' => ['title' => 'text', 'content' => 'textarea'], 'rules' => ['title' => 'required|string|max:255', 'content' => 'required|string|max:10000']],
            'website' => ['title' => 'Website learning', 'description' => 'Import public pages into your business knowledge. Imports run in the background.', 'model' => Models\WebsiteSource::class, 'permission' => 'knowledge', 'fields' => ['url' => 'url'], 'rules' => ['url' => 'required|url:http,https|max:2048']],
            'integrations' => ['title' => 'Integrations', 'description' => 'Send signed lead and conversation events to your CRM or automation platform.', 'model' => Models\WebhookEndpoint::class, 'permission' => 'settings', 'fields' => ['url' => 'url', 'events' => 'text', 'secret' => 'password'], 'rules' => ['url' => 'required|url:https|max:2048', 'events' => 'required|string|max:1000', 'secret' => 'nullable|string|min:32|max:255']],
            'automations' => ['title' => 'Automations', 'description' => 'Apply business rules when leads are created, qualified, or updated.', 'model' => Models\AutomationRule::class, 'permission' => 'settings', 'fields' => ['name' => 'text', 'trigger' => 'select:lead.created,lead.updated,lead.hot,conversation.completed,appointment.created,human.requested', 'min_score' => 'number', 'action' => 'select:create_task,tag_lead,send_email', 'value' => 'text'], 'rules' => ['name' => 'required|string|max:255', 'trigger' => 'required|in:lead.created,lead.updated,lead.hot,conversation.completed,appointment.created,human.requested', 'min_score' => 'required|integer|min:0|max:100', 'action' => 'required|in:create_task,tag_lead,send_email', 'value' => 'nullable|string|max:500']],
        ];
    }

    public static function get(string $section): array
    {
        return self::all()[$section] ?? abort(404);
    }

    public static function authorize(Request $r, string $permission): void
    {
        $member = $r->attributes->get('membership');
        $permissions = json_decode($member?->permissions ?? '[]', true) ?: [];
        abort_unless($member?->role === 'owner' || in_array($permission, $permissions, true), 403, 'You do not have permission for this area.');
    }
}
