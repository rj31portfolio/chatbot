<?php
namespace App\Services;
use App\Models\{Lead,ChatSession,ChatMessage,Visitor,Appointment,AiUsageLog};
class AnalyticsService
{
    public function metrics(): array
    {
        $chats=ChatSession::count(); $leads=Lead::count();
        return ['visitors'=>Visitor::count(),'conversations'=>$chats,'leads'=>$leads,'qualified'=>Lead::where('score','>=',60)->count(),'hot'=>Lead::where('score','>=',80)->count(),'conversion'=>$chats?round($leads/$chats*100,1):0,'average_score'=>round(Lead::avg('score')??0),'appointments'=>Appointment::count(),'ai_messages'=>ChatMessage::where('sender_type','ai')->count(),'tokens'=>AiUsageLog::sum('total_tokens'),'cost'=>AiUsageLog::sum('estimated_cost')];
    }
    public function daily(): array
    {
        $series=[];
        for($i=13;$i>=0;$i--) {
            $date=now()->subDays($i)->toDateString();
            $series[]=['date'=>$date,'chats'=>ChatSession::whereDate('created_at',$date)->count(),'leads'=>Lead::whereDate('created_at',$date)->count()];
        }
        return $series;
    }
}
