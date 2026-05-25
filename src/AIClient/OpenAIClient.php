<?php

require_once(__DIR__ . '/AIClientInterface.php');

class OpenAIClient implements AIClientInterface {
    private $apiUrl;
    private $apiKey;
    private $model;
    private $temperature;
    private $timeout;
    private $enableLogging;

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $model,
        float $temperature = 0.3,
        int $timeout = 30,
        bool $enableLogging = false
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->timeout = $timeout;
        $this->enableLogging = $enableLogging;
    }

    private function supportsTemperature(): bool {
        $noTemp = array('gpt-5', 'gpt-5-mini');
        foreach ($noTemp as $prefix) {
            if ($this->model === $prefix || strpos($this->model, $prefix . '-') === 0) {
                return false;
            }
        }
        return true;
    }

    public function complete(array $messages, bool $jsonMode = true): string {
        $payload = array(
            'model' => $this->model,
            'messages' => $messages,
        );

        if ($this->supportsTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        if ($jsonMode) {
            $payload['response_format'] = array('type' => 'json_object');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new \Exception('Failed to encode request: ' . json_last_error_msg());
        }

        if ($this->enableLogging) {
            error_log('AI Response Suggester [OpenAI] Request: ' . $jsonPayload);
        }

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
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
            error_log('AI Response Suggester [OpenAI] Response (HTTP ' . $httpCode . '): ' . $response);
        }

        $json = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = $json['error']['message'] ?? $response;
            throw new \Exception('OpenAI API error (HTTP ' . $httpCode . '): ' . $errorMsg);
        }

        if (isset($json['choices'][0]['message']['content'])) {
            return (string) $json['choices'][0]['message']['content'];
        }

        throw new \Exception('Unexpected OpenAI response format');
    }
}
