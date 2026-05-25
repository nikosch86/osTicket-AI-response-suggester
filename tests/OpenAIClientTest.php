<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AIClient/OpenAIClient.php';

class OpenAIClientTest extends TestCase {
    public function testConstructorSetsProperties(): void {
        $client = new OpenAIClient(
            'https://api.openai.com/v1/chat/completions',
            'sk-test',
            'gpt-4o-mini',
            0.7,
            45,
            true
        );

        $this->assertProperty($client, 'apiUrl', 'https://api.openai.com/v1/chat/completions');
        $this->assertProperty($client, 'apiKey', 'sk-test');
        $this->assertProperty($client, 'model', 'gpt-4o-mini');
        $this->assertProperty($client, 'temperature', 0.7);
        $this->assertProperty($client, 'timeout', 45);
        $this->assertProperty($client, 'enableLogging', true);
    }

    public function testApiUrlTrailingSlashIsTrimmed(): void {
        $client = new OpenAIClient(
            'https://api.openai.com/v1/chat/completions/',
            'sk-test',
            'gpt-4o-mini'
        );

        $ref = new ReflectionProperty(OpenAIClient::class, 'apiUrl');
        $ref->setAccessible(true);

        $this->assertEquals('https://api.openai.com/v1/chat/completions', $ref->getValue($client));
    }

    public function testDefaultParameterValues(): void {
        $client = new OpenAIClient(
            'https://api.openai.com/v1/chat/completions',
            'sk-test',
            'gpt-4o-mini'
        );

        $this->assertProperty($client, 'temperature', 0.3);
        $this->assertProperty($client, 'timeout', 30);
        $this->assertProperty($client, 'enableLogging', false);
    }

    public function testImplementsInterface(): void {
        $client = new OpenAIClient(
            'https://api.openai.com/v1/chat/completions',
            'sk-test',
            'gpt-4o-mini'
        );

        $this->assertInstanceOf(AIClientInterface::class, $client);
    }

    public function testCompleteMethodAcceptsJsonModeParameter(): void {
        $client = new OpenAIClient(
            'https://api.openai.com/v1/chat/completions',
            'sk-test',
            'gpt-4o-mini'
        );

        $method = new ReflectionMethod(OpenAIClient::class, 'complete');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('messages', $params[0]->getName());
        $this->assertEquals('jsonMode', $params[1]->getName());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertTrue($params[1]->getDefaultValue());
    }

    private function assertProperty($object, string $name, $expected): void {
        $ref = new ReflectionProperty(get_class($object), $name);
        $ref->setAccessible(true);
        $this->assertEquals($expected, $ref->getValue($object));
    }
}
