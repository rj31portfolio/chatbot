<?php

namespace App\Models;

class LeadNote extends TenantModel
{
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
