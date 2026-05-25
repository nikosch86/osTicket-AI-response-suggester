<?php

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(INCLUDE_DIR . 'class.canned.php');
require_once(__DIR__ . '/AIClient/AIClientFactory.php');
require_once(__DIR__ . '/TicketContextBuilder.php');
require_once(__DIR__ . '/CannedResponseProvider.php');
require_once(__DIR__ . '/PromptBuilder.php');
require_once(__DIR__ . '/ContentStore.php');
require_once(__DIR__ . '/WebCrawler.php');

class AIResponseSuggesterAjax extends AjaxController {

    function suggest() {
        global $thisstaff;
        $this->staffOnly();

        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        if (!$ticketId || !($ticket = Ticket::lookup($ticketId))) {
            Http::response(404, $this->encode(array(
                'success' => false,
                'error' => __('Ticket not found.'),
            )));
            return;
        }

        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY)) {
            Http::response(403, $this->encode(array(
                'success' => false,
                'error' => __('Permission denied.'),
            )));
            return;
        }

        $cfg = $this->getPluginConfig();
        if (!$cfg) {
            Http::response(500, $this->encode(array(
                'success' => false,
                'error' => __('Plugin not configured.'),
            )));
            return;
        }

        try {
            $contextBuilder = new TicketContextBuilder();
            $ticketContext = $contextBuilder->build($ticket);

            $maxCanned = (int) ($cfg->get('max_canned_responses') ?: 15);
            $cannedProvider = new CannedResponseProvider();
            $cannedResponses = $cannedProvider->getForTicket($ticket, $maxCanned);

            $knowledgeBase = '';
            $inst = $cfg->getInstance();
            $instanceId = $inst ? $inst->getId() : 0;
            if ($instanceId) {
                $store = new ContentStore();
                $knowledgeBase = $store->getContent($instanceId);
            }

            $promptBuilder = new PromptBuilder();
            $messages = $promptBuilder->build($ticketContext, $cannedResponses, $knowledgeBase, $cfg);

            $client = AIClientFactory::create($cfg);
            $rawResponse = $client->complete($messages);

            $result = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
                throw new \Exception('AI returned invalid JSON: ' . json_last_error_msg());
            }

            $responseText = $result['response_text'] ?? '';
            $basedOnId = $result['based_on_canned_id'] ?? null;
            $confidence = isset($result['confidence']) ? (int) $result['confidence'] : 0;
            $reasoning = $result['reasoning'] ?? '';

            // Apply response template if configured
            $template = trim((string) $cfg->get('response_template'));
            if ($template && $responseText) {
                $responseText = $this->expandTemplate($template, $ticket, $responseText, $thisstaff);
            }

            // Resolve canned response title if based on one
            $basedOnTitle = null;
            if ($basedOnId) {
                foreach ($cannedResponses as $cr) {
                    if ($cr['id'] == $basedOnId) {
                        $basedOnTitle = $cr['title'];
                        break;
                    }
                }
            }

            return $this->encode(array(
                'success' => true,
                'response_text' => $responseText,
                'based_on_canned_id' => $basedOnId,
                'based_on_canned_title' => $basedOnTitle,
                'confidence' => $confidence,
                'reasoning' => $reasoning,
                'debug_prompt' => $messages,
            ));

        } catch (\Throwable $e) {
            Http::response(500, $this->encode(array(
                'success' => false,
                'error' => $e->getMessage(),
            )));
        }
    }

    function crawl() {
        global $thisstaff;
        $this->staffOnly();

        if (!$thisstaff->isAdmin()) {
            Http::response(403, $this->encode(array(
                'success' => false,
                'error' => __('Admin access required.'),
            )));
            return;
        }

        $cfg = $this->getPluginConfig();
        if (!$cfg) {
            Http::response(500, $this->encode(array(
                'success' => false,
                'error' => __('Plugin not configured.'),
            )));
            return;
        }

        $baseUrl = trim((string) $cfg->get('crawl_url'));
        if (!$baseUrl) {
            Http::response(400, $this->encode(array(
                'success' => false,
                'error' => __('No crawl URL configured.'),
            )));
            return;
        }

        $maxDepth = (int) ($cfg->get('crawl_depth') ?: 3);
        $maxPages = (int) ($cfg->get('crawl_max_pages') ?: 50);

        $inst = $cfg->getInstance();
        $instanceId = $inst ? $inst->getId() : 0;

        $useAi = (bool) $cfg->get('crawl_use_ai');

        try {
            $store = new ContentStore();
            $store->clear($instanceId);

            $crawler = new WebCrawler($maxDepth, $maxPages);
            $pages = $crawler->crawl($baseUrl);

            $stored = 0;
            $storeError = '';
            foreach ($pages as $page) {
                if ($store->store(
                    $instanceId,
                    $page['url'],
                    $page['title'],
                    $page['content'],
                    $page['depth']
                )) {
                    $stored++;
                } elseif (!$storeError) {
                    $storeError = function_exists('db_error') ? db_error() : 'store() returned false';
                }
            }

            // Optionally summarize each page with the AI
            $summarized = 0;
            if ($useAi && $stored > 0) {
                try {
                    $client = AIClientFactory::create($cfg);
                    $allPages = $store->getAll($instanceId);

                    foreach ($allPages as $row) {
                        $rawContent = $row['content_preview'];
                        // Re-fetch full content for summarization — preview is truncated
                        // We use the original crawl data instead
                        $pageContent = '';
                        foreach ($pages as $p) {
                            if ($p['url'] === $row['url']) {
                                $pageContent = $p['content'];
                                break;
                            }
                        }
                        if (!$pageContent || strlen($pageContent) < 50) {
                            continue;
                        }

                        $sumMessages = array(
                            array('role' => 'system', 'content' =>
                                'Extract the key information from this web page that would be useful for answering customer support tickets. '
                                . 'Remove navigation, boilerplate, and irrelevant content. '
                                . 'Keep specific procedures, FAQs, product details, and policies. '
                                . 'Be concise. Return only the extracted text.'),
                            array('role' => 'user', 'content' =>
                                "Page title: " . ($row['title'] ?: '(untitled)') . "\n\n" . $pageContent),
                        );

                        $summary = $client->complete($sumMessages, false);
                        if ($summary) {
                            $store->updateSummary((int) $row['id'], $instanceId, $summary);
                            $summarized++;
                        }
                    }
                } catch (\Throwable $e) {
                    // Summarization is best-effort — don't fail the whole crawl
                    if ((bool) $cfg->get('enable_logging')) {
                        error_log('AI Response Suggester: Summarization error: ' . $e->getMessage());
                    }
                }
            }

            $response = array(
                'success' => true,
                'pages_crawled' => $stored,
                'pages_summarized' => $summarized,
            );
            if ($storeError) {
                $response['store_error'] = $storeError;
            }
            return $this->encode($response);

        } catch (\Throwable $e) {
            Http::response(500, $this->encode(array(
                'success' => false,
                'error' => $e->getMessage(),
            )));
        }
    }

    function crawlStatus() {
        global $thisstaff;
        $this->staffOnly();

        $cfg = $this->getPluginConfig();
        if (!$cfg) {
            return $this->encode(array('success' => false, 'pages' => 0));
        }

        $inst = $cfg->getInstance();
        $instanceId = $inst ? $inst->getId() : 0;

        $store = new ContentStore();
        $stats = $store->getStats($instanceId);

        return $this->encode(array(
            'success' => true,
            'pages' => $stats['count'],
            'last_crawled' => $stats['last_crawled'],
        ));
    }

    function crawlContent() {
        global $thisstaff;
        $this->staffOnly();

        if (!$thisstaff->isAdmin()) {
            Http::response(403, $this->encode(array(
                'success' => false,
                'error' => __('Admin access required.'),
            )));
            return;
        }

        $cfg = $this->getPluginConfig();
        if (!$cfg) {
            return $this->encode(array('success' => false, 'pages' => array()));
        }

        $inst = $cfg->getInstance();
        $instanceId = $inst ? $inst->getId() : 0;

        $store = new ContentStore();
        $pages = $store->getAll($instanceId);

        return $this->encode(array(
            'success' => true,
            'pages' => $pages,
        ));
    }

    function crawlDelete() {
        global $thisstaff;
        $this->staffOnly();

        if (!$thisstaff->isAdmin()) {
            Http::response(403, $this->encode(array(
                'success' => false,
                'error' => __('Admin access required.'),
            )));
            return;
        }

        $pageId = (int) ($_POST['page_id'] ?? 0);
        if (!$pageId) {
            Http::response(400, $this->encode(array(
                'success' => false,
                'error' => __('Page ID required.'),
            )));
            return;
        }

        $cfg = $this->getPluginConfig();
        if (!$cfg) {
            Http::response(500, $this->encode(array(
                'success' => false,
                'error' => __('Plugin not configured.'),
            )));
            return;
        }

        $inst = $cfg->getInstance();
        $instanceId = $inst ? $inst->getId() : 0;

        $store = new ContentStore();
        $store->delete($pageId, $instanceId);

        return $this->encode(array('success' => true));
    }

    private function getPluginConfig() {
        $cfg = AIResponseSuggesterPlugin::getActiveConfig();
        if (!$cfg) {
            $configs = AIResponseSuggesterPlugin::getAllConfigs();
            if ($configs) {
                $cfg = reset($configs);
            }
        }
        return $cfg;
    }

    private function expandTemplate($template, $ticket, $aiText, $staff = null) {
        $user = $ticket->getOwner();
        $agentName = '';
        if ($staff && is_object($staff) && method_exists($staff, 'getName')) {
            $agentName = (string) $staff->getName();
        }

        $replacements = array(
            '{ai_text}' => (string) $aiText,
            '{ticket_number}' => (string) $ticket->getNumber(),
            '{user_name}' => $user ? (string) $user->getName() : '',
            '{agent_name}' => $agentName,
        );

        return strtr($template, $replacements);
    }
}
