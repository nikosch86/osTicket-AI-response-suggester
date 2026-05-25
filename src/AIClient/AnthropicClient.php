<?php

require_once(__DIR__ . '/AIClientInterface.php');

class AnthropicClient implements AIClientInterface {
    private $apiUrl;
    private $apiKey;
    private $model;
    private $temperature;
    private $timeout;
    private $enableLogging;
    private $maxTokens;

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $model,
        float $temperature = 0.3,
        int $timeout = 30,
        bool $enableLogging = false,
        int $maxTokens = 4096
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->timeout = $timeout;
        $this->enableLogging = $enableLogging;
        $this->maxTokens = $maxTokens;
    }

    public function complete(array $messages, bool $jsonMode = true): string {
        $system = '';
        $filteredMessages = array();

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= ($system ? "\n\n" : '') . $msg['content'];
            } else {
                $filteredMessages[] = $msg;
            }
        }

        // Anthropic requires alternating user/assistant messages starting with user
        // Merge consecutive same-role messages if needed
        $mergedMessages = $this->mergeConsecutiveRoles($filteredMessages);

        $payload = array(
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => $mergedMessages,
        );

        if ($system) {
            $payload['system'] = $system;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new \Exception('Failed to encode request: ' . json_last_error_msg());
        }

        if ($this->enableLogging) {
            error_log('AI Response Suggester [Anthropic] Request: ' . $jsonPayload);
        }

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        );

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $err);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->enableLogging) {
            error_log('AI Response Suggester [Anthropic] Response (HTTP ' . $httpCode . '): ' . $response);
        }

        $json = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = $json['error']['message'] ?? $response;
            throw new \Exception('Anthropic API error (HTTP ' . $httpCode . '): ' . $errorMsg);
        }

        if (isset($json['content'][0]['text'])) {
            return (string) $json['content'][0]['text'];
        }

        throw new \Exception('Unexpected Anthropic response format');
    }

    private function mergeConsecutiveRoles(array $messages): array {
        if (empty($messages)) {
            return array(array('role' => 'user', 'content' => ''));
        }

        $merged = array();
        $last = null;

        foreach ($messages as $msg) {
            if ($last !== null && $last['role'] === $msg['role']) {
                $last['content'] .= "\n\n" . $msg['content'];
            } else {
                if ($last !== null) {
                    $merged[] = $last;
                }
                $last = $msg;
            }
        }
        if ($last !== null) {
            $merged[] = $last;
        }

        // Ensure first message is 'user'
        if (!empty($merged) && $merged[0]['role'] !== 'user') {
            array_unshift($merged, array('role' => 'user', 'content' => 'Please proceed.'));
        }

        return $merged;
    }
}
