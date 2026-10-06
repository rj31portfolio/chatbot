<?php

namespace App\Models;

class Lead extends TenantModel
{
    protected function casts(): array
    {
        return ['signals' => 'array', 'utm' => 'array', 'tags' => 'array'];
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function notes()
    {
        return $this->hasMany(LeadNote::class);
    }

    public function sessions()
    {
        return $this->hasMany(ChatSession::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
