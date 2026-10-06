<?php

namespace Tests\Unit;

use App\Services\ContentExtractionService;
use App\Services\WebsiteCrawlerService;
use PHPUnit\Framework\TestCase;

class ContentExtractionTest extends TestCase
{
    public function test_useful_content_is_extracted_and_scripts_navigation_and_styles_are_removed(): void
    {
        $data = (new ContentExtractionService)->extract('<title>Test studio</title><nav>Navigation noise</nav><h1>Website development</h1><p>Pricing starts at INR 25,000.</p><script>alert("script noise")</script><style>style noise</style><footer>footer noise</footer><a href="/services">Services</a>');
        $this->assertSame('Test studio', $data['title']);
        $this->assertStringContainsString('25,000', $data['content']);
        $this->assertStringNotContainsString('noise', $data['content']);
        $this->assertContains('/services', $data['links']);
    }

    public function test_crawler_resolves_relative_links_and_rejects_non_html_targets(): void
    {
        $crawler = new WebsiteCrawlerService;
        $this->assertSame('https://example.com/services/seo', $crawler->absolute('https://example.com/services/web', 'seo#details'));
        $this->assertSame('https://example.com/about', $crawler->absolute('https://example.com/services/web', '../about'));
        $this->assertNull($crawler->absolute('https://example.com', 'javascript:alert(1)'));
        $this->assertNull($crawler->absolute('https://example.com', '/download.pdf'));
    }
}
