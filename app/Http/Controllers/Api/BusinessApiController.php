<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\AnalyticsService;
use App\Services\EventService;
use App\Services\KnowledgeService;
use App\Support\ResourceRegistry;
use Illuminate\Http\Request;

class BusinessApiController extends Controller
{
    private function authorize(Request $r, string $permission): void
    {
        ResourceRegistry::authorize($r, $permission);
        abort_unless($r->user()->tokenCan($permission), 403);
    }

    private function ok(mixed $data, string $message = 'Success')
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public function leads(Request $r)
    {
        $this->authorize($r, 'leads');

        return $this->ok(Lead::latest()->paginate(30, ['public_id', 'name', 'email', 'phone', 'company', 'requirement', 'score', 'temperature', 'status', 'service', 'budget', 'timeline', 'summary', 'recommended_action', 'created_at']));
    }

    public function lead(Request $r, string $id)
    {
        $this->authorize($r, 'leads');

        return $this->ok(Lead::where('public_id', $id)->firstOrFail()->only(['public_id', 'name', 'email', 'phone', 'requirement', 'score', 'temperature', 'status', 'summary', 'recommended_action']));
    }

    public function updateLead(Request $r, string $id)
    {
        $this->authorize($r, 'manage_leads');
        $lead = Lead::where('public_id', $id)->firstOrFail();
        $lead->update($r->validate(['status' => 'required|in:new,contacted,qualified,proposal,won,lost']));
        LeadActivity::create(['lead_id' => $lead->id, 'type' => 'status', 'description' => 'Status updated through API: '.$lead->status]);
        app(EventService::class)->emit('lead.updated', $lead);

        return $this->ok(['public_id' => $lead->public_id, 'status' => $lead->status]);
    }

    public function knowledge(Request $r)
    {
        $this->authorize($r, 'knowledge');

        return $this->ok(KnowledgeDocument::latest()->paginate(30, ['id', 'source_type', 'title', 'content', 'status', 'created_at']));
    }

    public function saveKnowledge(Request $r, ?int $id = null)
    {
        $this->authorize($r, 'knowledge');
        $data = $r->validate(['title' => 'required|string|max:255', 'source_type' => 'required|in:manual,profile,pricing,document', 'content' => 'required|string|max:100000']);
        $doc = app(KnowledgeService::class)->save($data, $id ? KnowledgeDocument::findOrFail($id) : null);

        return $this->ok($doc->only(['id', 'title', 'source_type', 'status']), 'Knowledge saved');
    }

    public function deleteKnowledge(Request $r, int $id)
    {
        $this->authorize($r, 'knowledge');
        KnowledgeDocument::findOrFail($id)->delete();

        return $this->ok([], 'Knowledge deleted');
    }

    public function conversations(Request $r)
    {
        $this->authorize($r, 'conversations');

        return $this->ok(ChatSession::latest()->paginate(30, ['public_id', 'status', 'page_url', 'conversation_summary', 'created_at']));
    }

    public function conversation(Request $r, string $id)
    {
        $this->authorize($r, 'conversations');
        $session = ChatSession::where('public_id', $id)->firstOrFail();

        return $this->ok(['session' => $session->only(['public_id', 'status', 'conversation_summary']), 'messages' => $session->messages()->orderBy('id')->get(['sender_type', 'message', 'intent', 'created_at'])]);
    }

    public function analytics(Request $r)
    {
        $this->authorize($r, 'reports');

        return $this->ok(['metrics' => app(AnalyticsService::class)->metrics(), 'daily' => app(AnalyticsService::class)->daily()]);
    }
}
