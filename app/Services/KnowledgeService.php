<?php
namespace App\Services;

use App\Models\{KnowledgeDocument,KnowledgeChunk};
use Illuminate\Support\Facades\DB;

class KnowledgeService
{
    public function save(array $data, ?KnowledgeDocument $document=null): KnowledgeDocument
    {
        $store=function() use($data,$document) {
            $doc=$document ?? new KnowledgeDocument;
            $doc->fill($data); $doc->status='ready'; $doc->save();
            $doc->chunks()->delete();
            $content=preg_replace('/\s+/u',' ',strip_tags($doc->content));
            $length=mb_strlen($content);
            for($offset=0,$i=0; $offset<$length; $offset+=1000,$i++) {
                KnowledgeChunk::create(['document_id'=>$doc->id,'content'=>mb_substr($content,$offset,1200),'chunk_index'=>$i,'metadata'=>['title'=>$doc->title,'source_type'=>$doc->source_type,'url'=>$doc->source_url]]);
            }
            return $doc;
        };
        return $document ? DB::transaction($store) : app(UsageService::class)->createWithinLimit('knowledge_documents',KnowledgeDocument::class,$store);
    }
    public function search(string $query,int $limit=6): array
    {
        $stop=['the','and','what','your','you','are','does','for','can','have','with','that','this','how','our','about'];
        $words=array_slice(array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($query)),fn($w)=>mb_strlen($w)>2&&!in_array($w,$stop,true)))),0,12);
        if(!$words) return [];
        $q=KnowledgeChunk::query()->whereHas('document',fn($q)=>$q->where('status','ready'));
        if(DB::getDriverName()==='mysql') {
            $q->whereFullText('content',implode(' ',$words));
        } else {
            $q->where(function($q) use($words) { foreach($words as $w) $q->orWhere('content','like','%'.str_replace(['%','_'],['\\%','\\_'],$w).'%'); });
        }
        return $q->limit(200)->get()->map(function($chunk) use($words) {
            $text=mb_strtolower($chunk->content); $hits=0;
            foreach($words as $word) if(mb_strpos($text,$word)!==false) $hits++;
            return ['id'=>$chunk->id,'document_id'=>$chunk->document_id,'title'=>$chunk->metadata['title']??'Knowledge','content'=>$chunk->content,'relevance'=>$hits/count($words)];
        })->filter(fn($c)=>$c['relevance']>0)->sortByDesc('relevance')->take($limit)->values()->all();
    }
    public function readiness(): array
    {
        $b=app(\App\Support\TenantContext::class)->business();
        $types=KnowledgeDocument::where('status','ready')->pluck('source_type')->unique();
        $checks=['Business profile'=>(bool)$b->description,'Contact information'=>(bool)($b->email||$b->phone),'Website knowledge'=>$types->contains('website'),'Services or products'=>$types->contains('service')||$types->contains('product'),'FAQs'=>$types->contains('faq'),'Pricing'=>$types->contains('pricing')||\App\Models\BusinessService::whereNotNull('pricing')->exists()||\App\Models\BusinessProduct::whereNotNull('pricing')->exists(),'Policies'=>$types->contains('policy'),'Working hours'=>(bool)($b->profile['hours']??false)];
        return ['score'=>(int)round(count(array_filter($checks))/count($checks)*100),'checks'=>$checks];
    }
}
