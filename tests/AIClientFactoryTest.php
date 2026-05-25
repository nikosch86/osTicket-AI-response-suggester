<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AIClient/AIClientFactory.php';

class AIClientFactoryTest extends TestCase {
    public function testCreateOpenAIClient(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'openai',
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o-mini',
        ));

        $client = AIClientFactory::create($config);

        $this->assertInstanceOf(OpenAIClient::class, $client);
    }

    public function testCreateAnthropicClient(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'anthropic',
            'api_key' => 'sk-ant-test-key',
            'model' => 'claude-sonnet-4-20250514',
        ));

        $client = AIClientFactory::create($config);

        $this->assertInstanceOf(AnthropicClient::class, $client);
    }

    public function testCreateCustomClientUsesOpenAIClient(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'custom',
            'api_key' => 'test-key',
            'api_url' => 'https://my-llm.example.com/v1/chat/completions',
            'model' => 'local-model',
        ));

        $client = AIClientFactory::create($config);

        $this->assertInstanceOf(OpenAIClient::class, $client);
    }

    public function testMissingApiKeyThrowsException(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'openai',
            'api_key' => '',
            'model' => 'gpt-4o-mini',
        ));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API key is not configured');

        AIClientFactory::create($config);
    }

    public function testCustomProviderWithoutUrlThrowsException(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'custom',
            'api_key' => 'test-key',
            'api_url' => '',
            'model' => 'model',
        ));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API URL is required');

        AIClientFactory::create($config);
    }

    public function testUnknownProviderThrowsException(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'unknown',
            'api_key' => 'test-key',
            'model' => 'model',
        ));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown AI provider');

        AIClientFactory::create($config);
    }

    public function testDefaultsAreApplied(): void {
        $config = $this->createConfig(array(
            'ai_provider' => 'openai',
            'api_key' => 'sk-test',
            'model' => '',
            'temperature' => '',
            'timeout' => '',
        ));

        $client = AIClientFactory::create($config);
        $this->assertInstanceOf(OpenAIClient::class, $client);

        // Verify model default via reflection
        $ref = new ReflectionProperty(OpenAIClient::class, 'model');
        $ref->setAccessible(true);
        $this->assertEquals('gpt-4o-mini', $ref->getValue($client));
    }

    private function createConfig(array $data): PluginConfig {
        $config = new class extends PluginConfig {
            public $data = array();
            public function get($key, $default = null) {
                return $this->data[$key] ?? $default;
            }
        };
        $config->data = $data;
        return $config;
    }
}
