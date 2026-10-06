<?php
namespace App\Http\Controllers\Business;
use App\Http\Controllers\Controller;
use App\Models\{Lead,LeadNote,LeadActivity,ChatSession,ChatMessage,Appointment};
use App\Support\{TenantContext,ResourceRegistry};
use App\Services\EventService;
use App\Jobs\SummarizeConversation;
use Illuminate\Http\Request;
class CrmController extends Controller
{
    private function query(Request $r)
    {
        return Lead::query()->when($r->filled('q'),fn($q)=>$q->where(function($q)use($r){foreach(['name','email','phone','company'] as $f) $q->orWhere($f,'like','%'.$r->input('q').'%');}))->when($r->filled('status'),fn($q)=>$q->where('status',$r->input('status')))->when($r->filled('temperature'),fn($q)=>$q->where('temperature',$r->input('temperature')))->when($r->filled('min_score'),fn($q)=>$q->where('score','>=',(int)$r->input('min_score')))->when($r->filled('from'),fn($q)=>$q->whereDate('created_at','>=',$r->input('from')))->when($r->filled('service'),fn($q)=>$q->where('service',$r->input('service')))->orderByDesc('score')->latest();
    }
    public function leads(Request $r) { ResourceRegistry::authorize($r,'leads'); return view('business.leads',['leads'=>$this->query($r)->paginate(20)->withQueryString()]); }
    public function detail(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'leads'); $lead=Lead::where('public_id',$id)->with(['activities','notes.author','sessions.messages'])->firstOrFail();
        return view('business.lead', ['lead'=>$lead,'members'=>app(TenantContext::class)->business()->users()->get()]);
    }
    public function update(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'manage_leads'); $lead=Lead::where('public_id',$id)->firstOrFail();
        $data=$r->validate(['status'=>'required|in:new,contacted,qualified,proposal,won,lost','assigned_user_id'=>'nullable|integer','tags'=>'nullable|string|max:1000']);
        if(!empty($data['assigned_user_id'])) abort_unless(app(TenantContext::class)->business()->users()->where('users.id',$data['assigned_user_id'])->exists(),422);
        $data['tags']=array_filter(array_map('trim',explode(',',$data['tags']??''))); $lead->update($data);
        LeadActivity::create(['lead_id'=>$lead->id,'type'=>'status','description'=>'Lead moved to '.$lead->status]); app(EventService::class)->emit('lead.updated',$lead);
        return back()->with('status','Lead updated.');
    }
    public function note(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'manage_leads'); $lead=Lead::where('public_id',$id)->firstOrFail(); $data=$r->validate(['content'=>'required|string|max:5000']);
        LeadNote::create(array_merge($data,['lead_id'=>$lead->id,'user_id'=>$r->user()->id])); return back()->with('status','Note added.');
    }
    public function delete(Request $r,string $id) { ResourceRegistry::authorize($r,'manage_leads'); Lead::where('public_id',$id)->firstOrFail()->delete(); return redirect('/leads')->with('status','Lead data deleted.'); }
    public function conversations(Request $r)
    {
        ResourceRegistry::authorize($r,'conversations');
        $sessions=ChatSession::with('lead')->when($r->filled('q'),fn($q)=>$q->whereHas('messages',fn($q)=>$q->where('message','like','%'.$r->input('q').'%')))->when($r->filled('intent'),fn($q)=>$q->whereHas('messages',fn($q)=>$q->where('intent',$r->input('intent'))))->latest()->paginate(20)->withQueryString();
        $selected=$r->filled('session')?ChatSession::where('public_id',$r->input('session'))->with('messages')->firstOrFail():null;
        return view('business.conversations',compact('sessions','selected'));
    }
    public function close(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'conversations'); $s=ChatSession::where('public_id',$id)->firstOrFail(); $s->update(['status'=>'completed']);
        SummarizeConversation::dispatch($s->business_id,$s->id); return back()->with('status','Conversation closed. Summary queued.');
    }
    public function humanReply(Request $r,string $id)
    {
        ResourceRegistry::authorize($r,'manage_leads'); $s=ChatSession::where('public_id',$id)->firstOrFail(); $data=$r->validate(['message'=>'required|string|max:3000']);
        ChatMessage::create(['session_id'=>$s->id,'sender_type'=>'human','message'=>$data['message']]);
        $s->update(['status'=>'transferred','last_activity'=>now()]);
        return back()->with('status','Reply added to the conversation.');
    }
    public function deleteConversation(Request $r,string $id) { ResourceRegistry::authorize($r,'conversations'); ChatSession::where('public_id',$id)->firstOrFail()->delete(); return back()->with('status','Conversation and messages deleted.'); }
    public function appointments(Request $r) { ResourceRegistry::authorize($r,'leads'); return view('business.appointments',['appointments'=>Appointment::with('lead')->latest('starts_at')->paginate(20)]); }
    public function appointmentStatus(Request $r,int $id)
    {
        ResourceRegistry::authorize($r,'manage_leads'); Appointment::findOrFail($id)->update($r->validate(['status'=>'required|in:requested,confirmed,completed,cancelled,no_show'])); return back()->with('status','Appointment updated.');
    }
    public function export(Request $r,string $type)
    {
        ResourceRegistry::authorize($r,$type==='leads'?'leads':($type==='conversations'?'conversations':'reports'));
        abort_unless(in_array($type,['leads','conversations','analytics'],true),404);
        $columns=$type==='leads'?['public_id','name','email','phone','requirement','score','temperature','status','created_at']:($type==='conversations'?['public_id','status','page_url','conversation_summary','created_at']:['date','chats','leads']);
        $rows=$type==='leads'?$this->query($r)->cursor():($type==='conversations'?ChatSession::latest()->cursor():collect(app(\App\Services\AnalyticsService::class)->daily()));
        return response()->streamDownload(function()use($columns,$rows){$f=fopen('php://output','w'); fputcsv($f,$columns,',','"',''); foreach($rows as $row){ $cells=[];foreach($columns as $col){$v=(string)(is_array($row)?($row[$col]??''):($row->$col??''));$cells[]=preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v;}fputcsv($f,$cells,',','"','');} fclose($f);},$type.'-'.now()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv']);
    }
}
