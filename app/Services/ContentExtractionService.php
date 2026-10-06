<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class ContentExtractionService
{
    public function extract(string $html): array
    {
        $document = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $xpath = new DOMXPath($document);
        $title = trim($xpath->evaluate('string(//title)'));
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $links[] = $a->getAttribute('href');
        }
        foreach ($xpath->query('//script|//style|//nav|//footer|//noscript|//svg|//form') as $node) {
            $node->parentNode->removeChild($node);
        }
        $text = [];
        foreach ($xpath->query('//h1|//h2|//h3|//p|//li|//td|//th|//address') as $node) {
            $text[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        }
        $content = trim(implode("\n", array_filter(array_unique($text))));
        if (! $content) {
            $content = trim(preg_replace('/\s+/u', ' ', $document->textContent));
        }

        return ['title' => mb_substr($title ?: 'Website page', 0, 255), 'content' => mb_substr($content, 0, 100000), 'links' => array_unique($links)];
    }
}
