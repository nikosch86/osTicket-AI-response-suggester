<?php

require_once __DIR__ . '/RobotsTxtParser.php';

class WebCrawler {
    const USER_AGENT = 'osTicket-AI-Crawler/1.0';

    private $maxDepth;
    private $maxPages;
    private $maxContentSize;
    private $timeout;
    private $respectRobots;
    private $skipPatterns;
    private $robotsParser;

    public function __construct(
        int $maxDepth = 3,
        int $maxPages = 50,
        int $maxContentSize = 51200,
        int $timeout = 15,
        bool $respectRobots = true,
        array $skipPatterns = array()
    ) {
        $this->maxDepth = $maxDepth;
        $this->maxPages = $maxPages;
        $this->maxContentSize = $maxContentSize;
        $this->timeout = $timeout;
        $this->respectRobots = $respectRobots;
        $cleaned = array();
        foreach ($skipPatterns as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $cleaned[] = $p;
            }
        }
        $this->skipPatterns = $cleaned;
        $this->robotsParser = null;
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

        if ($this->respectRobots) {
            $this->loadRobotsFor($parsed);
        }

        if ($this->shouldSkip($baseUrl)) {
            return $results;
        }

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
                    if ($this->shouldSkip($link)) {
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
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
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

    private function shouldSkip(string $url): bool {
        if (!empty($this->skipPatterns)) {
            $path = $this->extractPathQuery($url);
            foreach ($this->skipPatterns as $pattern) {
                if ($this->patternMatches($pattern, $path)) {
                    return true;
                }
            }
        }
        if ($this->respectRobots && $this->robotsParser !== null) {
            if (!$this->robotsParser->isAllowed($url, self::USER_AGENT)) {
                return true;
            }
        }
        return false;
    }

    private function patternMatches(string $pattern, string $path): bool {
        $len = strlen($pattern);
        $regex = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '*') {
                $regex .= '.*';
            } elseif ($c === '$' && $i === $len - 1) {
                $regex .= '$';
            } else {
                $regex .= preg_quote($c, '#');
            }
        }
        // Anchor at path start when the pattern itself starts with '/';
        // otherwise allow it to match anywhere in the path.
        $anchor = ($len > 0 && $pattern[0] === '/') ? '^' : '';
        return (bool) preg_match('#' . $anchor . $regex . '#', $path);
    }

    private function extractPathQuery(string $url): string {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return $path;
    }

    private function loadRobotsFor(array $parsedBaseUrl): void {
        $scheme = $parsedBaseUrl['scheme'] ?? 'https';
        $host = $parsedBaseUrl['host'] ?? '';
        $port = isset($parsedBaseUrl['port']) ? ':' . $parsedBaseUrl['port'] : '';
        $robotsUrl = $scheme . '://' . $host . $port . '/robots.txt';

        $ch = curl_init($robotsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->robotsParser = self::buildRobotsParser($response, $httpCode);
    }

    /**
     * RFC 9309 §2.3.1: 5xx or unreachable -> assume complete disallow;
     * 4xx -> treat as no robots.txt (allow everything); 2xx/3xx -> parse body.
     *
     * @param string|false $body
     */
    private static function buildRobotsParser($body, int $httpCode): RobotsTxtParser {
        if ($body === false || $httpCode >= 500) {
            return new RobotsTxtParser("User-agent: *\nDisallow: /");
        }
        if ($httpCode >= 400) {
            return new RobotsTxtParser('');
        }
        return new RobotsTxtParser((string) $body);
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
