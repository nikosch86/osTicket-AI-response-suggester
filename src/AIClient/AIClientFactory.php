<?php

require_once(__DIR__ . '/AIClientInterface.php');
require_once(__DIR__ . '/OpenAIClient.php');
require_once(__DIR__ . '/AnthropicClient.php');

class AIClientFactory {
    /**
     * Create the appropriate AI client based on plugin configuration.
     *
     * @param PluginConfig $config
     * @return AIClientInterface
     * @throws Exception if configuration is invalid
     */
    public static function create($config): AIClientInterface {
        $provider = $config->get('ai_provider') ?: 'openai';
        $apiKey = $config->get('api_key');
        $apiUrl = $config->get('api_url');
        $model = $config->get('model') ?: 'gpt-4o-mini';
        $temperature = (float) ($config->get('temperature') ?: 0.3);
        $timeout = (int) ($config->get('timeout') ?: 30);
        $enableLogging = (bool) $config->get('enable_logging');

        if (!$apiKey) {
            throw new \Exception('API key is not configured.');
        }

        switch ($provider) {
            case 'openai':
                $url = $apiUrl ?: 'https://api.openai.com/v1/chat/completions';
                return new OpenAIClient($url, $apiKey, $model, $temperature, $timeout, $enableLogging);

            case 'anthropic':
                $url = $apiUrl ?: 'https://api.anthropic.com/v1/messages';
                return new AnthropicClient($url, $apiKey, $model, $temperature, $timeout, $enableLogging);

            case 'custom':
                if (!$apiUrl) {
                    throw new \Exception('API URL is required for custom provider.');
                }
                return new OpenAIClient($apiUrl, $apiKey, $model, $temperature, $timeout, $enableLogging);

            default:
                throw new \Exception('Unknown AI provider: ' . $provider);
        }
    }
}
