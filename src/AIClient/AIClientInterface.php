<?php

interface AIClientInterface {
    /**
     * Send a chat completion request.
     *
     * @param array $messages Array of ['role' => string, 'content' => string]
     * @param bool $jsonMode When true, request structured JSON output (OpenAI response_format)
     * @return string The AI response text
     * @throws Exception on failure
     */
    public function complete(array $messages, bool $jsonMode = true): string;
}
