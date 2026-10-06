<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\KnowledgeDocument;
use App\Services\BusinessService;
use App\Services\KnowledgeService;
use App\Support\ResourceRegistry;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function create()
    {
        return view('business.profile', ['business' => new Business, 'creating' => true]);
    }

    public function store(Request $r)
    {
        $business = app(BusinessService::class)->create($r->user(), $this->data($r));
        session(['business_id' => $business->id]);

        return redirect('/training')->with('status', 'Your business is ready. Let’s teach your AI.');
    }

    public function edit(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');

        return view('business.profile', ['business' => app(TenantContext::class)->business(), 'creating' => false]);
    }

    public function update(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');
        $b = app(TenantContext::class)->business();
        $b->update($this->data($r));
        $doc = KnowledgeDocument::where('source_type', 'profile')->first();
        app(KnowledgeService::class)->save(['source_type' => 'profile', 'title' => 'Business profile', 'content' => json_encode($b->only(['name', 'description', 'industry', 'city', 'phone', 'email', 'profile']), JSON_UNESCAPED_UNICODE)], $doc);

        return back()->with('status', 'Business information saved and AI knowledge updated.');
    }

    public function switch(Request $r)
    {
        $r->validate(['business_id' => 'required|integer']);
        $b = $r->user()->businesses()->where('businesses.id', $r->input('business_id'))->firstOrFail();
        session(['business_id' => $b->id]);

        return redirect('/dashboard');
    }

    private function data(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'industry' => 'nullable|string|max:255', 'description' => 'required|string|max:10000', 'website_url' => 'nullable|url:http,https|max:2048', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'whatsapp' => 'nullable|string|max:40', 'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:255', 'country' => 'nullable|string|max:255', 'timezone' => 'required|timezone:all_with_bc', 'profile' => 'nullable|array:hours,customers,usp,languages', 'profile.*' => 'nullable|string|max:2000']);
    }
}
