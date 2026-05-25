<?php

class PromptBuilder {
    /**
     * Build the messages array for the AI completion request.
     *
     * @param array $ticketContext From TicketContextBuilder::build()
     * @param array $cannedResponses From CannedResponseProvider::getForTicket()
     * @param string $knowledgeBase Crawled content text
     * @param PluginConfig $config
     * @return array Messages array suitable for AI client
     */
    public function build(
        array $ticketContext,
        array $cannedResponses,
        string $knowledgeBase,
        $config
    ): array {
        $messages = array();

        // System message
        $systemPrompt = $this->buildSystemPrompt($config);
        $messages[] = array('role' => 'system', 'content' => $systemPrompt);

        // User message with all context
        $userContent = $this->buildUserMessage($ticketContext, $cannedResponses, $knowledgeBase);
        $messages[] = array('role' => 'user', 'content' => $userContent);

        return $messages;
    }

    private function buildSystemPrompt($config): string {
        $prompt = "You are an expert support agent assistant. Your task is to suggest the best response to a support ticket.\n\n";
        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "1. Review the ticket details, available canned responses, and knowledge base content.\n";
        $prompt .= "2. If a canned response matches the ticket well, customize it for the specific situation.\n";
        $prompt .= "3. If no canned response is a good fit, generate a new response freely.\n";
        $prompt .= "4. Match the language of the ticket (e.g., reply in German if the ticket is in German).\n";
        $prompt .= "5. Match the tone of the ticket: if the customer writes formally, respond formally. If unclear, default to formal.\n";
        $prompt .= "6. Be professional and helpful. Keep responses short and to the point — avoid filler, unnecessary repetition, and overly verbose explanations.\n\n";
        $prompt .= "OUTPUT FORMAT: You MUST respond with valid JSON only:\n";
        $prompt .= "{\n";
        $prompt .= "  \"response_text\": \"<the suggested reply text, HTML allowed>\",\n";
        $prompt .= "  \"based_on_canned_id\": <ID of the canned response used, or null if freely generated>,\n";
        $prompt .= "  \"confidence\": <0-100 score of how confident you are in this suggestion>,\n";
        $prompt .= "  \"reasoning\": \"<brief explanation of your choice>\"\n";
        $prompt .= "}\n";

        $customPrompt = $config ? trim((string) $config->get('system_prompt')) : '';
        if ($customPrompt) {
            $prompt .= "\nADDITIONAL INSTRUCTIONS:\n" . $customPrompt . "\n";
        }

        return $prompt;
    }

    private function buildUserMessage(
        array $ticketContext,
        array $cannedResponses,
        string $knowledgeBase
    ): string {
        $msg = "TICKET INFORMATION:\n";
        $msg .= "Number: " . ($ticketContext['number'] ?? '') . "\n";
        $msg .= "Subject: " . ($ticketContext['subject'] ?? '') . "\n";
        $msg .= "Department: " . ($ticketContext['department'] ?? '') . "\n";
        $msg .= "Priority: " . ($ticketContext['priority'] ?? '') . "\n";
        if (!empty($ticketContext['help_topic'])) {
            $msg .= "Help Topic: " . $ticketContext['help_topic'] . "\n";
        }
        if (!empty($ticketContext['custom_fields'])) {
            foreach ($ticketContext['custom_fields'] as $cf) {
                $msg .= $cf['label'] . ": " . $cf['value'] . "\n";
            }
        }
        $msg .= "\n";

        $msg .= "TICKET CONTENT:\n";
        $msg .= ($ticketContext['content'] ?? '') . "\n\n";

        if (!empty($ticketContext['thread']) && count($ticketContext['thread']) > 1) {
            $msg .= "CONVERSATION HISTORY:\n";
            foreach (array_slice($ticketContext['thread'], 1) as $entry) {
                $msg .= sprintf(
                    "[%s - %s]: %s\n",
                    $entry['role'],
                    $entry['poster'],
                    $entry['body']
                );
            }
            $msg .= "\n";
        }

        if (!empty($cannedResponses)) {
            $msg .= "AVAILABLE CANNED RESPONSES:\n";
            foreach ($cannedResponses as $cr) {
                $msg .= sprintf(
                    "--- ID: %d | Title: %s ---\n%s\n\n",
                    $cr['id'],
                    $cr['title'],
                    $cr['content']
                );
            }
        } else {
            $msg .= "No canned responses available. Generate a response freely.\n\n";
        }

        if ($knowledgeBase) {
            $msg .= "KNOWLEDGE BASE CONTENT:\n";
            $msg .= $knowledgeBase . "\n\n";
        }

        $msg .= "Based on the above, suggest the best response. Return JSON only.";

        return $msg;
    }
}
