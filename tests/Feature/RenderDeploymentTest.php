<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RenderDeploymentTest extends TestCase
{
    /** @var array<string, array{env: string|null, server: string|null, process: string|false}> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
        foreach ($this->originalEnvironment as $name => $original) {
            if ($original['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $original['env'];
            }
            if ($original['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $original['server'];
            }
            putenv($original['process'] === false ? $name : $name.'='.$original['process']);
        }
    }

    private function setEnvironment(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'process' => getenv($name)];
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function registerProxyProbe(): void
    {
        Route::get('/deployment-proxy-probe', fn () => response()->json([
            'secure' => request()->isSecure(),
            'login_url' => route('login'),
        ]));
    }

    public function test_render_forwarded_https_generates_secure_links(): void
    {
        $this->setEnvironment('RENDER', 'true');
        $this->refreshApplication();
        $this->registerProxyProbe();

        $this->assertSame('*', config('app.trusted_proxies'));
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://business.onrender.com/deployment-proxy-probe')
            ->assertOk()
            ->assertJson(['secure' => true, 'login_url' => 'https://business.onrender.com/login']);
    }

    public function test_forwarded_headers_are_not_trusted_by_default_outside_render(): void
    {
        $this->setEnvironment('RENDER', 'false');
        $this->refreshApplication();
        $this->registerProxyProbe();

        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/deployment-proxy-probe')
            ->assertOk()
            ->assertJson(['secure' => false]);
    }

    public function test_render_postgres_url_is_used_when_db_url_is_empty(): void
    {
        $this->setEnvironment('DB_URL', '');
        $this->setEnvironment('DATABASE_URL', 'postgresql://user:password@database:5432/business');
        $this->refreshApplication();

        $this->assertSame('postgresql://user:password@database:5432/business', config('database.connections.pgsql.url'));
    }

    public function test_explicit_db_url_takes_precedence_over_render_url(): void
    {
        $this->setEnvironment('DB_URL', 'postgresql://user:password@primary:5432/business');
        $this->setEnvironment('DATABASE_URL', 'postgresql://user:password@fallback:5432/business');
        $this->refreshApplication();

        $this->assertSame('postgresql://user:password@primary:5432/business', config('database.connections.pgsql.url'));
    }
}
