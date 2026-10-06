<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Support\{ResourceRegistry,TenantContext};
use App\Services\{KnowledgeService,SafeHttpService};
use App\Models\{KnowledgeDocument,AuditLog,WebsiteSource};
use App\Jobs\CrawlWebsite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ResourceController extends Controller
{
    public function index(Request $r,string $section)
    {
        $spec=ResourceRegistry::get($section); ResourceRegistry::authorize($r,$spec['permission']);
        $items=$spec['model']::query()->when($r->filled('q'),fn($q)=>$q->where($section==='website'||$section==='integrations'?'url':($section==='automations'?'name':'title'),'like','%'.$r->input('q').'%'))->latest()->paginate(15)->withQueryString();
        $edit=$r->filled('edit')?$spec['model']::findOrFail($r->input('edit')):null;
        return view('business.resources',compact('section','spec','items','edit'));
    }
    public function save(Request $r,string $section,?int $id=null)
    {
        $spec=ResourceRegistry::get($section); ResourceRegistry::authorize($r,$spec['permission']);
        $item=$id?$spec['model']::findOrFail($id):null;
        $data=$r->validate($spec['rules']);
        DB::transaction(function() use($data,$section,$item,$spec,$r) {
            if($section==='knowledge') $saved=app(KnowledgeService::class)->save($data,$item);
            elseif(isset($spec['source'])) {
                $document=$item?->document_id?KnowledgeDocument::findOrFail($item->document_id):null;
                $doc=app(KnowledgeService::class)->save(['title'=>$data['title'],'source_type'=>$spec['source'],'content'=>$data['content'].(!empty($data['pricing'])?"\nPricing: ".$data['pricing']:'')],$document);
                $data['document_id']=$doc->id; $saved=$item??new $spec['model']; $saved->fill($data)->save();
            } else {
                if(in_array($section,['website','integrations'],true)) app(SafeHttpService::class)->resolve($data['url']);
                if($section==='integrations') {
                    $events=array_values(array_filter(array_map('trim',explode(',',$data['events']))));
                    $allowed=['lead.created','lead.updated','lead.hot','conversation.completed','appointment.created','human.requested'];
                    if(array_diff($events,$allowed)) throw \Illuminate\Validation\ValidationException::withMessages(['events'=>'Use supported event names, separated by commas.']);
                    $data['events']=$events;
                    if(empty($data['secret'])) { if($item) unset($data['secret']); else $data['secret']=Str::random(48); }
                }
                if($section==='automations') { $data['settings']=['value'=>$data['value']??'']; unset($data['value']); }
                $saved=$item??new $spec['model']; $saved->fill($data)->save();
                if($section==='website') CrawlWebsite::dispatch(app(TenantContext::class)->id(),$saved->id)->afterCommit();
            }
            AuditLog::create(['user_id'=>$r->user()->id,'action'=>$section.'.saved','metadata'=>['record_id'=>$saved->id]]);
        });
        return redirect('/manage/'.$section)->with('status',$section==='website'?'Website import queued. Run the queue worker to process it.':'Saved successfully.');
    }
    public function delete(Request $r,string $section,int $id)
    {
        $spec=ResourceRegistry::get($section); ResourceRegistry::authorize($r,$spec['permission']); $item=$spec['model']::findOrFail($id);
        DB::transaction(function() use($item,$spec) { if(isset($spec['source'])&&$item->document_id) KnowledgeDocument::find($item->document_id)?->delete(); $item->delete(); });
        return back()->with('status','Record deleted.');
    }
    public function upload(Request $r)
    {
        ResourceRegistry::authorize($r,'knowledge'); $r->validate(['document'=>'required|file|mimes:pdf,txt|max:10240']);
        $file=$r->file('document');
        $doc=app(\App\Services\UsageService::class)->createWithinLimit('knowledge_documents',KnowledgeDocument::class,fn()=>KnowledgeDocument::create(['source_type'=>'document','title'=>mb_substr($file->getClientOriginalName(),0,255),'content'=>'','status'=>'processing']));
        $path=$file->store('knowledge-uploads','local');
        \App\Jobs\ProcessKnowledgeUpload::dispatch(app(TenantContext::class)->id(),$doc->id,$path,strtolower($file->getClientOriginalExtension()));
        return back()->with('status','Document queued for processing. Run the queue worker to import its text.');
    }
}
