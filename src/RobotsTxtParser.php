<?php

/**
 * Parses robots.txt content per RFC 9309 and answers allow/disallow questions.
 *
 * Wildcards: `*` matches any sequence; `$` anchors to end-of-path (RFC 9309 §2.2.3).
 * Group selection: case-insensitive prefix match against the crawler's product
 * token, falling back to the `*` group; longest matching agent string wins.
 * Conflict resolution: longest matching rule pattern wins; on a tie, Allow wins.
 */
class RobotsTxtParser {
    /** @var array<int, array{agents: string[], rules: array<int, array{type: string, pattern: string}>}> */
    private $groups = array();

    public function __construct(string $content = '') {
        $this->parse($content);
    }

    public function isAllowed(string $url, string $userAgent): bool {
        $path = $this->extractPath($url);
        $rules = $this->rulesForAgent($userAgent);

        $bestType = null;
        $bestLen = -1;

        foreach ($rules as $rule) {
            if ($rule['pattern'] === '') {
                continue;
            }
            $len = $this->matchLength($rule['pattern'], $path);
            if ($len === null) {
                continue;
            }
            if ($len > $bestLen
                || ($len === $bestLen && $rule['type'] === 'allow' && $bestType === 'disallow')) {
                $bestLen = $len;
                $bestType = $rule['type'];
            }
        }

        if ($bestType === null) {
            return true;
        }
        return $bestType === 'allow';
    }

    private function parse(string $content): void {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lastIdx = -1;
        $expectingAgent = true;

        foreach ($lines as $line) {
            $line = preg_replace('/#.*$/', '', $line);
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            $value = trim($m[2]);

            if ($field === 'user-agent') {
                if ($lastIdx === -1 || !$expectingAgent) {
                    $this->groups[] = array('agents' => array(), 'rules' => array());
                    $lastIdx = count($this->groups) - 1;
                    $expectingAgent = true;
                }
                $this->groups[$lastIdx]['agents'][] = strtolower($value);
            } elseif ($field === 'allow' || $field === 'disallow') {
                if ($lastIdx === -1) {
                    continue;
                }
                $expectingAgent = false;
                $this->groups[$lastIdx]['rules'][] = array(
                    'type' => $field,
                    'pattern' => $value,
                );
            }
        }
    }

    /**
     * Return the rule list for the most specific group that matches $userAgent.
     * Falls back to the `*` group, then to an empty list.
     */
    private function rulesForAgent(string $userAgent): array {
        $userAgent = strtolower($userAgent);
        $specificRules = null;
        $specificLen = -1;
        $wildcardRules = null;

        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    if ($wildcardRules === null) {
                        $wildcardRules = $group['rules'];
                    } else {
                        $wildcardRules = array_merge($wildcardRules, $group['rules']);
                    }
                } elseif ($agent !== '' && strpos($userAgent, $agent) === 0) {
                    if (strlen($agent) > $specificLen) {
                        $specificRules = $group['rules'];
                        $specificLen = strlen($agent);
                    }
                }
            }
        }

        if ($specificRules !== null) {
            return $specificRules;
        }
        return $wildcardRules ?? array();
    }

    private function extractPath(string $url): string {
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

    /**
     * Returns the matched pattern length (used for specificity), or null on no match.
     * `*` is converted to `.*`; trailing `$` anchors to end-of-path.
     */
    private function matchLength(string $pattern, string $path): ?int {
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
        if (preg_match('#^' . $regex . '#', $path)) {
            return $len;
        }
        return null;
    }
}
