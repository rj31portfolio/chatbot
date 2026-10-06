<?php

namespace App\Services;

use App\Models\KnowledgeDocument;
use App\Models\WebsitePage;
use App\Models\WebsiteSource;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class WebsiteCrawlerService
{
    public function allowed(string $robots, string $path): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(explode('#', $line)[0]);
            if (! $line || ! str_contains($line, ':')) {
                continue;
            }
            [$field,$value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($rules) {
                    $groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                }
                $agents[] = strtolower($value);
            } elseif (in_array($field, ['allow', 'disallow'], true) && $value !== '' && $agents) {
                $rules[] = [$field, $value];
            }
        }
        $groups[] = ['agents' => $agents, 'rules' => $rules];
        $specific = array_filter($groups, fn ($g) => in_array('aileadagentbot', $g['agents'], true));
        $selected = $specific ?: array_filter($groups, fn ($g) => in_array('*', $g['agents'], true));
        $matches = [];
        foreach ($selected as $group) {
            foreach ($group['rules'] as [$field,$pattern]) {
                $expression = str_replace(['\\*', '\\$'], ['.*', '$'], preg_quote($pattern, '~'));
                if (preg_match('~^'.$expression.'~', $path)) {
                    $matches[] = ['allowed' => $field === 'allow', 'length' => strlen(str_replace(['*', '$'], '', $pattern))];
                }
            }
        }
        usort($matches, fn ($a, $b) => ($b['length'] <=> $a['length']) ?: ($b['allowed'] <=> $a['allowed']));

        return ! $matches || $matches[0]['allowed'];
    }

    public function crawl(WebsiteSource $source): void
    {
        $http = app(SafeHttpService::class);
        $http->resolve($source->url);
        $host = parse_url($source->url, PHP_URL_HOST);
        $scheme = parse_url($source->url, PHP_URL_SCHEME);
        $origin = $scheme.'://'.$host;
        $robotsResponse = $http->fetch($origin.'/robots.txt');
        if (! $robotsResponse->successful() && ! in_array($robotsResponse->status(), [404, 410], true)) {
            throw new \RuntimeException('Could not verify robots.txt. Import stopped.');
        }
        $robots = $robotsResponse->successful() ? $robotsResponse->body() : '';
        $queue = [$source->url];
        $seen = [];
        $attempts = 0;
        $limit = app(UsageService::class)->limit('website_pages');
        $source->update(['status' => 'processing', 'error' => null]);
        while ($queue && $attempts < min($limit * 3, 6000)) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $attempts++;
            if (! $this->allowed($robots, parse_url($url, PHP_URL_PATH) ?: '/')) {
                continue;
            }
            $existing = WebsitePage::where('website_source_id', $source->id)->where('url_hash', hash('sha256', $url))->first();
            if (! $existing && WebsitePage::count() >= $limit) {
                break;
            }
            $response = $http->fetch($url);
            if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type') ?? ''), 'text/html')) {
                continue;
            }
            $data = app(ContentExtractionService::class)->extract($response->body());
            if (! $data['content']) {
                continue;
            }
            $store = fn () => WebsitePage::updateOrCreate(['website_source_id' => $source->id, 'url_hash' => hash('sha256', $url)], ['url' => $url, 'title' => $data['title'], 'content' => $data['content']]);
            $existing ? $store() : app(UsageService::class)->createWithinLimit('website_pages', WebsitePage::class, $store);
            $doc = KnowledgeDocument::where('source_type', 'website')->where('source_url', $url)->first();
            app(KnowledgeService::class)->save(['source_type' => 'website', 'source_url' => $url, 'title' => $data['title'], 'content' => $data['content']], $doc);
            $source->update(['pages_crawled' => $source->pages()->count()]);
            foreach ($data['links'] as $link) {
                $next = $this->absolute($url, $link);
                if ($next && parse_url($next, PHP_URL_HOST) === $host && parse_url($next, PHP_URL_SCHEME) === $scheme && ! isset($seen[$next]) && count($queue) < $limit * 3) {
                    $queue[] = $next;
                }
            }
        }
        $source->update(['status' => 'completed', 'last_crawled_at' => now()]);
    }

    public function absolute(string $base, string $link): ?string
    {
        if (preg_match('/^(mailto:|tel:|javascript:|data:|#)/i', $link)) {
            return null;
        }
        try {
            $url = (string) UriResolver::resolve(new Uri($base), new Uri($link));
        } catch (\Throwable) {
            return null;
        }
        $url = explode('#',$url)[0];
        if (preg_match('/\.(pdf|jpg|png|gif|svg|zip|mp4|css|js)(\?|$)/i',$url)) {
            return null;
        }

        return $url;
    }
}
