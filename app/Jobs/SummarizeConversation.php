<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\ChatSession;
use App\Services\AIService;
use App\Services\EventService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public int $businessId, public int $sessionId) {}

    public function handle(): void
    {
        $b = Business::findOrFail($this->businessId);
        app(TenantContext::class)->run($b, function () {
            $s = ChatSession::find($this->sessionId);
            if (! $s || $s->conversation_summary || ! $s->messages()->exists()) {
                return;
            }
            $summary = app(AIService::class)->summarizeConversation($s);
            $s->update(['conversation_summary' => $summary]);
            $s->lead?->update(['summary' => $summary]);
            app(EventService::class)->emit('conversation.completed', $s->lead, ['session_id' => $s->public_id, 'summary' => $summary]);
        });
    }
}
