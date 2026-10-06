<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Support\ResourceRegistry;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');

        return view('business.api-keys', ['tokens' => $r->user()->tokens()->where('business_id', app(TenantContext::class)->id())->latest()->get()]);
    }

    public function store(Request $r)
    {
        ResourceRegistry::authorize($r, 'settings');
        $data = $r->validate(['name' => 'required|string|max:100', 'abilities' => 'required|array|min:1', 'abilities.*' => 'in:leads,manage_leads,knowledge,conversations,reports', 'days' => 'required|integer|min:1|max:365']);
        $plaintext = Str::random(64);
        $token = $r->user()->tokens()->create(['business_id' => app(TenantContext::class)->id(), 'name' => $data['name'], 'token' => hash('sha256', $plaintext), 'abilities' => array_merge(['workspace:'.app(TenantContext::class)->id()], $data['abilities']), 'expires_at' => now()->addDays($data['days'])]);

        return back()->with('created_token', $token->id.'|'.$plaintext)->with('status', 'API key created. Copy it now; it will not be shown again.');
    }

    public function destroy(Request $r, int $id)
    {
        ResourceRegistry::authorize($r, 'settings');
        $r->user()->tokens()->where('business_id', app(TenantContext::class)->id())->whereKey($id)->firstOrFail()->delete();

        return back()->with('status','API key revoked.');
    }
}
