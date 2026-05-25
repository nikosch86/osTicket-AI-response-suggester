<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AIClient/AnthropicClient.php';

class AnthropicClientTest extends TestCase {
    public function testMergeConsecutiveRoles(): void {
        $client = new AnthropicClient(
            'https://api.anthropic.com/v1/messages',
            'test-key',
            'claude-sonnet-4-20250514'
        );

        $method = new ReflectionMethod(AnthropicClient::class, 'mergeConsecutiveRoles');
        $method->setAccessible(true);

        $messages = array(
            array('role' => 'user', 'content' => 'Hello'),
            array('role' => 'user', 'content' => 'More context'),
            array('role' => 'assistant', 'content' => 'Hi there'),
        );

        $merged = $method->invoke($client, $messages);

        $this->assertCount(2, $merged);
        $this->assertEquals('user', $merged[0]['role']);
        $this->assertStringContainsString('Hello', $merged[0]['content']);
        $this->assertStringContainsString('More context', $merged[0]['content']);
        $this->assertEquals('assistant', $merged[1]['role']);
    }

    public function testMergeEnsuresFirstMessageIsUser(): void {
        $client = new AnthropicClient(
            'https://api.anthropic.com/v1/messages',
            'test-key',
            'claude-sonnet-4-20250514'
        );

        $method = new ReflectionMethod(AnthropicClient::class, 'mergeConsecutiveRoles');
        $method->setAccessible(true);

        $messages = array(
            array('role' => 'assistant', 'content' => 'I start first'),
        );

        $merged = $method->invoke($client, $messages);

        $this->assertEquals('user', $merged[0]['role']);
        $this->assertEquals('assistant', $merged[1]['role']);
    }

    public function testMergeEmptyMessagesReturnsPlaceholder(): void {
        $client = new AnthropicClient(
            'https://api.anthropic.com/v1/messages',
            'test-key',
            'claude-sonnet-4-20250514'
        );

        $method = new ReflectionMethod(AnthropicClient::class, 'mergeConsecutiveRoles');
        $method->setAccessible(true);

        $merged = $method->invoke($client, array());

        $this->assertCount(1, $merged);
        $this->assertEquals('user', $merged[0]['role']);
    }

    public function testConstructorSetsProperties(): void {
        $client = new AnthropicClient(
            'https://api.anthropic.com/v1/messages',
            'my-key',
            'claude-sonnet-4-20250514',
            0.5,
            60,
            true,
            8192
        );

        $this->assertProperty($client, 'apiUrl', 'https://api.anthropic.com/v1/messages');
        $this->assertProperty($client, 'apiKey', 'my-key');
        $this->assertProperty($client, 'model', 'claude-sonnet-4-20250514');
        $this->assertProperty($client, 'temperature', 0.5);
        $this->assertProperty($client, 'timeout', 60);
        $this->assertProperty($client, 'enableLogging', true);
        $this->assertProperty($client, 'maxTokens', 8192);
    }

    public function testCompleteMethodAcceptsJsonModeParameter(): void {
        $client = new AnthropicClient(
            'https://api.anthropic.com/v1/messages',
            'test-key',
            'claude-sonnet-4-20250514'
        );

        $method = new ReflectionMethod(AnthropicClient::class, 'complete');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('jsonMode', $params[1]->getName());
        $this->assertTrue($params[1]->getDefaultValue());
    }

    private function assertProperty($object, string $name, $expected): void {
        $ref = new ReflectionProperty(get_class($object), $name);
        $ref->setAccessible(true);
        $this->assertEquals($expected, $ref->getValue($object));
    }
}
