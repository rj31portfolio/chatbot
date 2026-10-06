<?php
namespace App\Models;

class KnowledgeDocument extends TenantModel
{
    protected function casts(): array { return ['metadata'=>'array']; }
    public function chunks() { return $this->hasMany(KnowledgeChunk::class, 'document_id'); }
}
