<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\WebhookLog;
use App\Services\SafeHttpService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public int $businessId, public int $logId) {}

    public function handle(): void
    {
        app(TenantContext::class)->run(Business::findOrFail($this->businessId), function () {
            $log = WebhookLog::with('endpoint')->findOrFail($this->logId);
            if ($log->status === 'delivered' || ! $log->endpoint->active) {
                return;
            }
            $timestamp = (string) time();
            $body = json_encode($log->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $log->endpoint->secret);
            $log->increment('attempts');
            try {
                // Sign the same canonical JSON representation used by the receiver.
                $response = app(SafeHttpService::class)->fetch($log->endpoint->url, 'POST', $log->payload, ['X-AI-Lead-Signature' => $signature, 'X-AI-Lead-Timestamp' => $timestamp, 'X-AI-Lead-Event-Id' => $log->event_id]);
                $log->update(['http_status' => $response->status()]);
                $response->throw();
                if (! $response->successful()) {
                    throw new \RuntimeException('Webhook returned a redirect.');
                }
                $log->update(['status' => 'delivered', 'error' => null, 'next_attempt_at' => null]);
            } catch (\Throwable $e) {
                $log->update(['status' => $log->attempts >= 5 ? 'failed' : 'retrying', 'error' => 'Delivery failed. See application logs.', 'next_attempt_at' => now()->addMinutes(5)]);
                Log::warning('Webhook delivery failed', ['log_id' => $log->id, 'error' => $e->getMessage()]);
                throw $e;
            }
        });
    }
}
