<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/PromptBuilder.php';

class PromptBuilderTest extends TestCase {
    private PromptBuilder $builder;

    protected function setUp(): void {
        $this->builder = new PromptBuilder();
    }

    public function testBuildReturnsMessagesArray(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);
        $this->assertEquals('system', $messages[0]['role']);
        $this->assertEquals('user', $messages[1]['role']);
    }

    public function testSystemPromptContainsJsonInstructions(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertStringContainsString('response_text', $messages[0]['content']);
        $this->assertStringContainsString('confidence', $messages[0]['content']);
        $this->assertStringContainsString('JSON', $messages[0]['content']);
    }

    public function testCustomSystemPromptIsAppended(): void {
        $config = $this->createMockConfig(array(
            'system_prompt' => 'Always reply in haiku format.',
        ));
        $ticketContext = $this->createTicketContext();

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertStringContainsString('Always reply in haiku format.', $messages[0]['content']);
    }

    public function testUserMessageContainsTicketInfo(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertStringContainsString('TK-12345', $messages[1]['content']);
        $this->assertStringContainsString('Password reset not working', $messages[1]['content']);
        $this->assertStringContainsString('Support', $messages[1]['content']);
    }

    public function testUserMessageIncludesCannedResponses(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();
        $canned = array(
            array('id' => 1, 'title' => 'Password Reset', 'content' => 'Please follow these steps...'),
            array('id' => 2, 'title' => 'Account Locked', 'content' => 'Your account has been locked...'),
        );

        $messages = $this->builder->build($ticketContext, $canned, '', $config);

        $this->assertStringContainsString('Password Reset', $messages[1]['content']);
        $this->assertStringContainsString('Account Locked', $messages[1]['content']);
        $this->assertStringContainsString('ID: 1', $messages[1]['content']);
    }

    public function testUserMessageIncludesKnowledgeBase(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();
        $kb = "To reset your password, visit /account/reset and enter your email.";

        $messages = $this->builder->build($ticketContext, array(), $kb, $config);

        $this->assertStringContainsString('KNOWLEDGE BASE', $messages[1]['content']);
        $this->assertStringContainsString('/account/reset', $messages[1]['content']);
    }

    public function testEmptyCannedResponsesShowsFreelyGenerateMessage(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext();

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertStringContainsString('No canned responses available', $messages[1]['content']);
    }

    public function testThreadHistoryIncluded(): void {
        $config = $this->createMockConfig();
        $ticketContext = $this->createTicketContext(true);

        $messages = $this->builder->build($ticketContext, array(), '', $config);

        $this->assertStringContainsString('CONVERSATION HISTORY', $messages[1]['content']);
        $this->assertStringContainsString('Agent Smith', $messages[1]['content']);
    }

    private function createMockConfig(array $overrides = array()): PluginConfig {
        $config = new class extends PluginConfig {
            public $data = array();
            public function get($key, $default = null) {
                return $this->data[$key] ?? $default;
            }
        };
        $config->data = array_merge(array(
            'system_prompt' => '',
        ), $overrides);
        return $config;
    }

    private function createTicketContext(bool $withHistory = false): array {
        $context = array(
            'id' => 42,
            'number' => 'TK-12345',
            'subject' => 'Password reset not working',
            'dept_id' => 1,
            'department' => 'Support',
            'priority' => 'High',
            'content' => 'I tried to reset my password but got an error.',
            'thread' => array(
                array(
                    'role' => 'customer',
                    'poster' => 'John Doe',
                    'body' => 'I tried to reset my password but got an error.',
                    'type' => 'M',
                ),
            ),
        );

        if ($withHistory) {
            $context['thread'][] = array(
                'role' => 'agent',
                'poster' => 'Agent Smith',
                'body' => 'Could you try again in an incognito window?',
                'type' => 'R',
            );
        }

        return $context;
    }
}
