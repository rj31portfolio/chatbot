<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class ProcessKnowledgeUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $businessId, public int $documentId, public string $path, public string $type) {}

    public function handle(): void
    {
        app(TenantContext::class)->run(Business::findOrFail($this->businessId), function () {
            $doc = KnowledgeDocument::find($this->documentId);
            try {
                if (! $doc) {
                    return;
                }
                $file = Storage::disk('local')->path($this->path);
                $text = $this->type === 'pdf' ? (new Parser)->parseFile($file)->getText() : file_get_contents($file);
                if (! trim($text)) {
                    throw new \RuntimeException('No extractable text.');
                }
                app(KnowledgeService::class)->save(['content' => mb_substr($text, 0, 100000)], $doc);
            } catch (\Throwable $e) {
                $doc?->update(['status' => 'failed']);
                Log::warning('Knowledge upload failed', ['document_id' => $this->documentId, 'error' => $e->getMessage()]);
            } finally {
                Storage::disk('local')->delete($this->path);
            }
        });
    }
}
