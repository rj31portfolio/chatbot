<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatWidget;
use App\Models\User;
use App\Services\BusinessService;
use App\Services\SafeHttpService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WidgetInstallationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private string $widgetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create();
        $this->business = app(BusinessService::class)->create($owner, ['name' => 'Installation Studio', 'description' => 'Business services', 'website_url' => 'https://example.com', 'timezone' => 'UTC']);
        $this->widgetId = app(TenantContext::class)->run($this->business, fn () => ChatWidget::firstOrFail()->public_id);
        $this->actingAs($owner)->withSession(['business_id' => $this->business->id]);
        Http::preventStrayRequests();
        $safe = \Mockery::mock(SafeHttpService::class)->makePartial();
        $safe->shouldReceive('resolve')->with(\Mockery::on(fn (string $url): bool => parse_url($url, PHP_URL_HOST) === 'example.com'))->andReturn(['example.com', 443, '93.184.216.34']);
        $this->app->instance(SafeHttpService::class, $safe);
    }

    private function check(): TestResponse
    {
        return $this->from('/installation')->post('/installation/'.$this->widgetId.'/check');
    }

    private function installedScript(): string
    {
        return '<html><body><script src="'.url('/widget.js').'" data-widget-id="'.$this->widgetId.'" defer></script></body></html>';
    }

    public function test_installed_widget_is_detected_with_certificate_verification_enabled(): void
    {
        config(['services.http.ca_bundle' => null]);
        Http::fake(function ($request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($this->installedScript());
        });
        $this->check()->assertRedirect('/installation')->assertSessionHasNoErrors()->assertSessionHas('status', 'Widget installation detected on your homepage.');
        app(TenantContext::class)->run($this->business, fn () => $this->assertNotNull(ChatWidget::firstOrFail()->installed_at));
    }

    public function test_configured_certificate_bundle_is_used(): void
    {
        config(['services.http.ca_bundle' => 'C:/trusted/cacert.pem']);
        Http::fake(function ($request, array $options) {
            $this->assertSame('C:/trusted/cacert.pem', $options['verify']);

            return Http::response($this->installedScript());
        });
        $this->check()->assertRedirect('/installation')->assertSessionHasNoErrors();
    }

    public function test_relative_redirects_are_followed_and_final_page_is_checked(): void
    {
        Http::fake(['https://example.com' => Http::response('', 301, ['Location' => '/home']), 'https://example.com/home' => Http::response($this->installedScript())]);
        $this->check()->assertRedirect('/installation')->assertSessionHasNoErrors()->assertSessionHas('status', 'Widget installation detected on your homepage.');
        Http::assertSentCount(2);
    }

    public function test_certificate_failure_returns_helpful_error_instead_of_server_error(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 60: SSL certificate unable to get local issuer certificate')]);
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'The server could not verify your website HTTPS certificate. Please contact platform support to check the certificate configuration.']);
        $this->followingRedirects()->check()->assertOk()->assertSee('could not verify your website HTTPS certificate');
    }

    public function test_network_timeout_returns_helpful_error(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Connection timed out')]);
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'Could not connect to your website. Check that it is online and the URL in business settings is correct, then try again.']);
    }

    public function test_http_error_is_reported_without_marking_widget_installed(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'Your website returned HTTP 403. Check that the page is public and does not block the installation checker.']);
        app(TenantContext::class)->run($this->business, fn () => $this->assertNull(ChatWidget::firstOrFail()->installed_at));
    }

    public function test_missing_script_returns_not_detected_result(): void
    {
        Http::fake(['*' => Http::response('<html><body>No widget here</body></html>')]);
        $this->check()->assertRedirect('/installation')->assertSessionHasNoErrors()->assertSessionHas('status', 'Widget not detected on your homepage. Check the code or test a page where it is installed.');
        app(TenantContext::class)->run($this->business, fn () => $this->assertNull(ChatWidget::firstOrFail()->installed_at));
    }

    public function test_redirect_loop_is_reported(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com'])]);
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'Your website redirects in a loop. Check the website URL in business settings.']);
        Http::assertSentCount(1);
    }

    public function test_redirect_limit_prevents_unbounded_requests(): void
    {
        Http::fake(function ($request) {
            $page = (int) trim(parse_url($request->url(), PHP_URL_PATH) ?? '', '/');

            return Http::response('', 302, ['Location' => '/'.($page + 1)]);
        });
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'Your website has an invalid redirect or too many redirects. Update the website URL to the final published address.']);
        Http::assertSentCount(6);
    }

    public function test_oversized_response_does_not_cause_server_error(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Response too large.'));
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'The website response could not be checked. Try again, or open your published website and send a test message to verify the widget.']);
    }

    public function test_redirect_to_private_network_is_rejected_before_fetching(): void
    {
        Http::fake(['https://example.com' => Http::response('', 302, ['Location' => 'http://127.0.0.1'])]);
        $safe = $this->app->make(SafeHttpService::class);
        $safe->shouldReceive('resolve')->with('http://127.0.0.1')->passthru();
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['installation' => 'The website or its redirect must use a public HTTP/HTTPS address on port 80 or 443. Check the URL in business settings.']);
        Http::assertSentCount(1);
    }

    public function test_missing_website_url_prompts_for_business_settings(): void
    {
        $this->business->update(['website_url' => null]);
        $this->check()->assertRedirect('/installation')->assertSessionHasErrors(['website' => 'Add your website URL in business settings first.']);
        Http::assertNothingSent();
    }
}
