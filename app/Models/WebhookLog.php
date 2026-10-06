<?php
namespace App\Models;

class WebhookLog extends TenantModel
{
    protected function casts(): array { return ['payload'=>'array','next_attempt_at'=>'datetime']; }
    public function endpoint() { return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id'); }
}
