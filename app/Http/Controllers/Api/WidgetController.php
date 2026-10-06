<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SummarizeConversation;
use App\Models\AiSetting;
use App\Models\Appointment;
use App\Models\ChatSession;
use App\Services\AIConversationService;
use App\Services\EventService;
use App\Services\LeadService;
use App\Services\WidgetService;
use Illuminate\Http\Request;

class WidgetController extends Controller
{
    private function ok(array $data, string $message = 'Success')
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function session(Request $r): ChatSession
    {
        $r->validate(['session_id' => 'required|uuid']);

        return app(WidgetService::class)->authenticate($r->input('session_id'), $r->bearerToken() ?? '', $r->attributes->get('widget_origin'), $r->attributes->get('widget')->id);
    }

    public function config(Request $r)
    {
        return $this->ok(app(WidgetService::class)->config($r->attributes->get('widget')));
    }

    public function start(Request $r)
    {
        $data = $r->validate(['visitor_id' => 'nullable|uuid', 'page_url' => 'nullable|url:http,https|max:2048', 'referrer' => 'nullable|url:http,https|max:2048', 'utm' => 'nullable|array', 'utm.*' => 'nullable|string|max:255']);

        return $this->ok(app(WidgetService::class)->start($r->attributes->get('widget'), $data, $r->attributes->get('widget_origin')));
    }

    public function message(Request $r)
    {
        $data = $r->validate(['message' => 'required|string|max:3000']);
        $reply = app(AIConversationService::class)->reply($this->session($r), $data['message']);

        return $this->ok(['message' => $reply['message']]);
    }

    public function lead(Request $r)
    {
        abort_unless(AiSetting::firstOrFail()->features['capture'] ?? false, 403);
        $data = $r->validate(['name' => 'nullable|string|max:255', 'phone' => 'required_without:email|nullable|string|max:40|regex:/^[+0-9().\s-]{6,40}$/', 'email' => 'required_without:phone|nullable|email|max:255', 'company' => 'nullable|string|max:255', 'requirement' => 'nullable|string|max:3000', 'budget' => 'nullable|string|max:255', 'timeline' => 'nullable|string|max:255', 'service' => 'nullable|string|max:255', 'consent' => 'accepted']);
        unset($data['consent']);
        $lead = app(LeadService::class)->capture($this->session($r), $data);

        return $this->ok(['lead_id' => $lead->public_id], 'Your details have been sent to the team.');
    }

    public function appointment(Request $r)
    {
        abort_unless(AiSetting::firstOrFail()->features['appointments'] ?? false, 403);
        $data = $r->validate(['service' => 'required|string|max:255', 'starts_at' => 'required|date|after:now', 'timezone' => 'required|timezone:all_with_bc', 'notes' => 'nullable|string|max:2000']);
        $session = $this->session($r);
        abort_unless($session->lead_id, 422, 'Share contact details before requesting an appointment.');
        $appointment = Appointment::create(array_merge($data, ['lead_id' => $session->lead_id]));
        $metadata = $session->metadata ?? [];
        $metadata['signals']['appointment'] = true;
        $session->update(['metadata' => $metadata]);
        app(LeadService::class)->capture($session, []);
        app(EventService::class)->emit('appointment.created', $session->lead, ['starts_at' => $appointment->starts_at->toIso8601String()]);

        return $this->ok(['status' => 'requested'], 'Appointment requested. The team will confirm availability.');
    }

    public function end(Request $r)
    {
        $s = $this->session($r);
        $s->update(['status' => 'completed']);
        SummarizeConversation::dispatch($s->business_id, $s->id);

        return $this->ok([], 'Conversation completed.');
    }

    public function history(Request $r)
    {
        $session = $this->session($r);

        return $this->ok(['messages' => $session->messages()->where('id', '>', max(0, (int) $r->input('after', 0)))->orderBy('id')->limit(100)->get(['id', 'sender_type', 'message'])->toArray()]);
    }
}
