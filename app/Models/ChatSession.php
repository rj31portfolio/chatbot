<?php

namespace App\Models;

class ChatSession extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_activity' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function widget()
    {
        return $this->belongsTo(ChatWidget::class, 'chat_widget_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class);
    }
}
