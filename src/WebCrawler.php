<?php

class WebCrawler {
    private $maxDepth;
    private $maxPages;
    private $maxContentSize;
    private $timeout;

    public function __construct(
        int $maxDepth = 3,
        int $maxPages = 50,
        int $maxContentSize = 51200,
        int $timeout = 15
    ) {
        $this->maxDepth = $maxDepth;
        $this->maxPages = $maxPages;
        $this->maxContentSize = $maxContentSize;
        $this->timeout = $timeout;
    }

    /**
     * BFS crawl from a base URL, staying within the same domain.
     *
     * @param string $baseUrl Starting URL
     * @return array Array of ['url', 'title', 'content', 'depth']
     */
    public function crawl(string $baseUrl): array {
        $parsed = parse_url($baseUrl);
        if (!$parsed || empty($parsed['host'])) {
            throw new \Exception('Invalid base URL: ' . $baseUrl);
        }

        $baseDomain = $parsed['host'];
        $visited = array();
        $results = array();
        $queue = array(array('url' => $baseUrl, 'depth' => 0));

        while (!empty($queue) && count($results) < $this->maxPages) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            $normalizedUrl = $this->normalizeUrl($url);
            if (isset($visited[$normalizedUrl])) {
                continue;
            }
            $visited[$normalizedUrl] = true;

            $html = $this->fetch($url);
            if ($html === false) {
                continue;
            }

            $title = $this->extractTitle($html);
            $content = $this->extractContent($html);

            if (strlen($content) > $this->maxContentSize) {
                $content = substr($content, 0, $this->maxContentSize);
            }

            $results[] = array(
                'url' => $url,
                'title' => $title,
                'content' => $content,
                'depth' => $depth,
            );

            // Only follow links if we haven't reached max depth
            if ($depth < $this->maxDepth) {
                $links = $this->extractLinks($html, $url);
                foreach ($links as $link) {
                    $linkParsed = parse_url($link);
                    if (!$linkParsed || empty($linkParsed['host'])) {
                        continue;
                    }
                    if ($linkParsed['host'] !== $baseDomain) {
                        continue;
                    }
                    $linkNorm = $this->normalizeUrl($link);
                    if (!isset($visited[$linkNorm])) {
                        $queue[] = array('url' => $link, 'depth' => $depth + 1);
                    }
                }
            }
        }

        return $results;
    }

    private function fetch(string $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'osTicket-AI-Crawler/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return false;
        }

        // Only process HTML content
        if ($contentType && stripos($contentType, 'text/html') === false) {
            return false;
        }

        return $response;
    }

    private function extractTitle(string $html): string {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }
        return '';
    }

    private function extractContent(string $html): string {
        // Remove unwanted elements
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $html);
        $html = preg_replace('/<aside[^>]*>.*?<\/aside>/is', '', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function extractLinks(string $html, string $baseUrl): array {
        $links = array();
        if (!preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $links;
        }

        foreach ($matches[1] as $href) {
            // Skip anchors, javascript, mailto
            if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $href)) {
                continue;
            }
            // Skip common non-content files
            if (preg_match('/\.(pdf|zip|tar|gz|jpg|jpeg|png|gif|svg|css|js|xml|json)$/i', $href)) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $baseUrl);
            if ($resolved) {
                $links[] = $resolved;
            }
        }

        return array_unique($links);
    }

    private function resolveUrl(string $href, string $baseUrl): ?string {
        // Already absolute
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (strpos($href, '//') === 0) {
            return $scheme . ':' . $href;
        }

        $base = $scheme . '://' . $host . $port;

        if (strpos($href, '/') === 0) {
            return $base . $href;
        }

        // Relative path
        $path = $parsed['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $base . $dir . $href;
    }

    private function normalizeUrl(string $url): string {
        $parsed = parse_url($url);
        $norm = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (!empty($parsed['port'])) {
            $norm .= ':' . $parsed['port'];
        }
        $norm .= rtrim($parsed['path'] ?? '/', '/');
        return strtolower($norm);
    }
}
