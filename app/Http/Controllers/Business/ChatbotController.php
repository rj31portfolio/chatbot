<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Models\ChatWidget;
use App\Models\ChatWidgetDomain;
use App\Models\PlatformSetting;
use App\Services\AIConversationService;
use App\Services\SafeHttpService;
use App\Services\UsageService;
use App\Services\WidgetService;
use App\Support\ResourceRegistry;
use App\Support\TenantContext;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatbotController extends Controller
{
    public function settings(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');

        return view('business.ai-settings', ['settings' => AiSetting::firstOrFail()]);
    }

    public function saveSettings(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $data = $r->validate(['tone' => 'required|in:professional,friendly,consultative,concise', 'language' => 'required|string|max:100', 'instructions' => 'nullable|string|max:6000', 'retention_days' => 'required|integer|min:1|max:3650', 'scoring' => 'required|array', 'scoring.*' => 'integer|min:0|max:100', 'lead_fields' => 'required|array|min:1', 'lead_fields.*' => 'in:name,email,phone,company,requirement,budget,timeline,service']);
        $data['scoring'] = array_intersect_key($data['scoring'], config('saas.scoring'));
        $data['features'] = [];
        foreach (['sales', 'capture', 'appointments', 'pricing', 'handoff', 'notifications', 'tracking'] as $key) {
            $data['features'][$key] = $r->boolean('features.'.$key);
        }
        AiSetting::firstOrFail()->update($data);

        return back()->with('status', 'AI behavior saved.');
    }

    public function widget(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $widgets = ChatWidget::get();
        $widget = $r->filled('widget') ? ChatWidget::where('public_id', $r->input('widget'))->firstOrFail() : $widgets->firstOrFail();

        return view('business.widget', compact('widgets', 'widget'));
    }

    public function saveWidget(Request $r, string $publicId)
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $widget = ChatWidget::where('public_id', $publicId)->firstOrFail();
        $data = $r->validate(['title' => 'required|string|max:100', 'color' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'welcome_message' => 'required|string|max:2000', 'position' => 'required|in:left,right', 'domains' => 'required|string|max:2000', 'settings' => 'nullable|array:placeholder,radius,bottom,auto_open_seconds,show_branding,show_phone,show_email,show_whatsapp', 'settings.placeholder' => 'nullable|string|max:120', 'settings.radius' => 'nullable|integer|min:0|max:32', 'settings.bottom' => 'nullable|integer|min:0|max:200', 'settings.auto_open_seconds' => 'nullable|integer|min:0|max:600']);
        $domains = array_unique(array_filter(array_map(fn ($d) => strtolower(trim($d)), preg_split('/[\r\n,]+/', $data['domains']))));
        foreach ($domains as $domain) {
            if (! preg_match('/^(localhost|[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)$/', $domain) || str_contains($domain, '..')) {
                throw ValidationException::withMessages(['domains' => 'Enter exact hostnames such as example.com, one per line.']);
            }
        }
        if (count($domains) > app(UsageService::class)->limit('domains')) {
            throw ValidationException::withMessages(['domains' => 'Your plan domain limit has been reached.']);
        }
        unset($data['domains']);
        $data['settings'] = array_merge($widget->settings ?? [], $data['settings'] ?? []);
        foreach (['show_branding', 'show_phone', 'show_email', 'show_whatsapp'] as $key) {
            $data['settings'][$key] = $r->boolean('settings.'.$key);
        }
        DB::transaction(function () use ($widget, $data, $domains) {
            $widget->update($data);
            $widget->domains()->delete();
            foreach ($domains as $domain) {
                ChatWidgetDomain::create(['chat_widget_id' => $widget->id, 'domain' => $domain]);
            }
        });

        return back()->with('status', 'Widget design and approved domains saved.');
    }

    public function newWidget(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $widget = app(UsageService::class)->createWithinLimit('widgets', ChatWidget::class, fn () => ChatWidget::create(['public_id' => (string) Str::uuid()]));

        return redirect('/widget?widget='.$widget->public_id)->with('status', 'Widget created. Add its approved domains.');
    }

    public function installation(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');

        return view('business.installation', ['widgets' => ChatWidget::with('domains')->get()]);
    }

    public function check(Request $r, string $publicId): RedirectResponse
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $widget = ChatWidget::where('public_id', $publicId)->firstOrFail();
        $url = app(TenantContext::class)->business()->website_url;
        if (! $url) {
            return back()->withErrors(['website' => 'Add your website URL in business settings first.']);
        }
        try {
            $visited = [];
            for ($redirects = 0; $redirects <= 5; $redirects++) {
                if (isset($visited[$url])) {
                    return back()->withErrors(['installation' => 'Your website redirects in a loop. Check the website URL in business settings.']);
                }
                $visited[$url] = true;
                $response = app(SafeHttpService::class)->fetch($url);
                if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    break;
                }
                $location = $response->header('Location');
                if (! $location || $redirects === 5) {
                    return back()->withErrors(['installation' => 'Your website has an invalid redirect or too many redirects. Update the website URL to the final published address.']);
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            }
        } catch (ConnectionException $exception) {
            $message = str_contains(strtolower($exception->getMessage()), 'certificate')
                ? 'The server could not verify your website HTTPS certificate. Please contact platform support to check the certificate configuration.'
                : 'Could not connect to your website. Check that it is online and the URL in business settings is correct, then try again.';

            return back()->withErrors(['installation' => $message]);
        } catch (ValidationException $exception) {
            return back()->withErrors(['installation' => 'The website or its redirect must use a public HTTP/HTTPS address on port 80 or 443. Check the URL in business settings.']);
        } catch (GuzzleException|\RuntimeException|\InvalidArgumentException $exception) {
            return back()->withErrors(['installation' => 'The website response could not be checked. Try again, or open your published website and send a test message to verify the widget.']);
        }
        if (! $response->successful()) {
            return back()->withErrors(['installation' => 'Your website returned HTTP '.$response->status().'. Check that the page is public and does not block the installation checker.']);
        }
        $found = $response->successful() && str_contains($response->body(), 'widget.js') && str_contains($response->body(), $publicId);
        if ($found) {
            $widget->update(['installed_at' => now()]);
        }

        return back()->with('status', $found ? 'Widget installation detected on your homepage.' : 'Widget not detected on your homepage. Check the code or test a page where it is installed.');
    }

    public function tester(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');

        return view('business.tester', ['widget' => ChatWidget::firstOrFail(), 'configured' => filled(config('ai.providers.deepseek.api_key')) || PlatformSetting::where('key', 'deepseek_key')->exists()]);
    }

    public function testStart(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');

        return response()->json(['success' => true, 'message' => 'Session created', 'data' => app(WidgetService::class)->start(ChatWidget::firstOrFail(), [], url('/'))]);
    }

    public function testMessage(Request $r)
    {
        ResourceRegistry::authorize($r, 'chatbot');
        $r->validate(['message' => 'required|string|max:3000', 'session_id' => 'required|uuid', 'token' => 'required|string']);
        $session = app(WidgetService::class)->authenticate($r->input('session_id'), $r->input('token'));

        return response()->json(['success' => true, 'message' => 'Success', 'data' => app(AIConversationService::class)->reply($session, $r->input('message'))]);
    }
}
