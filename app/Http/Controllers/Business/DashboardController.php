<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Subscription;
use App\Services\AnalyticsService;
use App\Services\KnowledgeService;
use App\Support\ResourceRegistry;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r)
    {
        ResourceRegistry::authorize($r, 'reports');

        return view('business.dashboard', ['subscription' => Subscription::with('plan')->first(), 'metrics' => app(AnalyticsService::class)->metrics(), 'daily' => app(AnalyticsService::class)->daily(), 'leads' => Lead::orderByDesc('score')->latest()->take(5)->get(), 'readiness' => app(KnowledgeService::class)->readiness()]);
    }

    public function training(Request $r)
    {
        ResourceRegistry::authorize($r, 'knowledge');

        return view('business.training', ['readiness' => app(KnowledgeService::class)->readiness(), 'counts' => KnowledgeDocument::selectRaw('source_type, count(*) as total')->groupBy('source_type')->pluck('total', 'source_type')]);
    }

    public function analytics(Request $r)
    {
        ResourceRegistry::authorize($r, 'reports');

        return view('business.analytics', ['metrics' => app(AnalyticsService::class)->metrics(), 'daily' => app(AnalyticsService::class)->daily(), 'sources' => Lead::selectRaw('source, count(*) as total')->groupBy('source')->get(), 'pages' => ChatSession::selectRaw('page_url, count(*) as total')->groupBy('page_url')->orderByDesc('total')->take(10)->get()]);
    }
}
