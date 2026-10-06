<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\AiSetting;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Notifications\BusinessAlert;
use App\Support\TenantContext;
use Illuminate\Support\Str;

class EventService
{
    public function emit(string $event, ?Lead $lead = null, array $extra = []): void
    {
        $b = app(TenantContext::class)->business();
        $payload = ['id' => (string) Str::uuid(), 'event' => $event, 'business' => $b->slug, 'occurred_at' => now()->toIso8601String(), 'data' => array_merge($lead ? $lead->only(['public_id', 'name', 'email', 'phone', 'requirement', 'score', 'temperature', 'status']) : [], $extra)];
        foreach (WebhookEndpoint::where('active', true)->get() as $endpoint) {
            if (! in_array($event, $endpoint->events, true)) {
                continue;
            }
            $log = WebhookLog::create(['webhook_endpoint_id' => $endpoint->id, 'event_id' => $payload['id'], 'event' => $event, 'payload' => $payload]);
            DeliverWebhook::dispatch($b->id, $log->id)->afterCommit();
        }
        $settings = AiSetting::firstOrFail();
        if (in_array($event, ['lead.created', 'lead.hot', 'human.requested', 'appointment.created'], true)) {
            $b->owner->notify(new BusinessAlert(['business_id' => $b->id, 'title' => ucwords(str_replace('.', ' ', $event)), 'description' => $lead ? "{$lead->name}: {$lead->requirement} (score {$lead->score})" : 'A website visitor needs your attention.', 'lead_id' => $lead?->public_id], $settings->features['notifications'] ?? false));
        }
        if (! $lead) {
            return;
        }
        foreach (AutomationRule::where('active', true)->where('trigger', $event)->where('min_score', '<=', $lead->score)->get() as $rule) {
            if ($rule->action === 'create_task') {
                LeadActivity::create(['lead_id' => $lead->id, 'type' => 'task', 'description' => $rule->settings['value'] ?? 'Contact this lead.']);
            }
            if ($rule->action === 'tag_lead') {
                $lead->update(['tags' => array_values(array_unique(array_merge($lead->tags ?? [], [$rule->settings['value'] ?? $rule->name])))]);
            }
            if ($rule->action === 'assign_team_member') {
                $user = $b->users()->where('users.id', $rule->settings['user_id'] ?? 0)->first();
                if ($user) {
                    $lead->update(['assigned_user_id' => $user->id]);
                }
            }
            if ($rule->action === 'send_email' && ($settings->features['notifications'] ?? false)) {
                $b->owner->notify(new BusinessAlert(['business_id' => $b->id, 'title' => $rule->name, 'description' => $lead->requirement ?? 'New qualified lead.'], true));
            }
        }
    }
}
