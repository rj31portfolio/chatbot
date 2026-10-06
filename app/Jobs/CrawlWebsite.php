<?php
namespace App\Jobs;
use App\Models\{Business,WebsiteSource};
use App\Support\TenantContext;
use App\Services\WebsiteCrawlerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
class CrawlWebsite implements ShouldQueue
{
    use Queueable;
    public int $timeout=900;
    public int $tries=1;
    public function __construct(public int $businessId,public int $sourceId) {}
    public function handle(): void
    {
        $business=Business::findOrFail($this->businessId);
        app(TenantContext::class)->run($business,function() {
            $source=WebsiteSource::findOrFail($this->sourceId);
            try { app(WebsiteCrawlerService::class)->crawl($source); }
            catch(\Throwable $e) { Log::warning('Website import failed',['business_id'=>$this->businessId,'source_id'=>$this->sourceId,'error'=>$e->getMessage()]); $source->update(['status'=>'failed','error'=>'Import failed. Verify the URL, robots.txt permissions, and plan limits.']); }
        });
    }
}
