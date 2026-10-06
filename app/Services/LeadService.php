<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadService
{
    public function capture(ChatSession $session, array $data): Lead
    {
        return DB::transaction(function () use ($session, $data) {
            $session = ChatSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $lead = $session->lead;
            $isNew = ! $lead;
            if ($isNew) {
                app(UsageService::class)->consume('leads');
                $lead = new Lead(['public_id' => (string) Str::uuid(), 'utm' => $session->metadata['utm'] ?? []]);
            }
            $lead->fill(array_filter($data, fn ($v) => $v !== null && $v !== ''));
            $lead->signals = array_merge($lead->signals ?? [], $session->metadata['signals'] ?? []);
            $oldScore = $lead->score ?? 0;
            $result = app(LeadScoringService::class)->calculate($lead);
            $lead->score = $result['score'];
            $lead->temperature = $result['temperature'];
            $lead->recommended_action = $result['score'] >= 80 ? 'Call within 10 minutes' : ($result['score'] >= 60 ? 'Schedule a consultation' : 'Ask about their requirements');
            if (! $isNew && ! $lead->isDirty()) {
                return $lead;
            }
            $lead->save();
            $session->update(['lead_id' => $lead->id]);
            LeadScore::create(['lead_id' => $lead->id, 'score' => $lead->score, 'breakdown' => $result['breakdown']]);
            LeadActivity::create(['lead_id' => $lead->id, 'type' => $isNew ? 'created' : 'updated', 'description' => $isNew ? 'Lead captured from a website conversation.' : 'Lead details and qualification score updated.']);
            app(EventService::class)->emit($isNew ? 'lead.created' : 'lead.updated', $lead);
            if ($oldScore < 80 && $lead->score >= 80) {
                app(EventService::class)->emit('lead.hot', $lead);
            }

            return $lead;
        });
    }
}
