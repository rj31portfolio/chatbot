<?php

namespace App\Models;

class KnowledgeChunk extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function document()
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
