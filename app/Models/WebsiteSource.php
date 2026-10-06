<?php

namespace App\Models;

class WebsiteSource extends TenantModel
{
    protected function casts(): array
    {
        return ['last_crawled_at' => 'datetime'];
    }

    public function pages()
    {
        return $this->hasMany(WebsitePage::class);
    }
}
