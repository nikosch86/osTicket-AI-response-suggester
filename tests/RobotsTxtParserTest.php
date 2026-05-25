<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/RobotsTxtParser.php';

class RobotsTxtParserTest extends TestCase {
    public function testEmptyContentAllowsEverything(): void {
        $parser = new RobotsTxtParser('');
        $this->assertTrue($parser->isAllowed('https://example.com/anything', 'MyBot'));
    }

    public function testDisallowAll(): void {
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /");
        $this->assertFalse($parser->isAllowed('https://example.com/', 'MyBot'));
        $this->assertFalse($parser->isAllowed('https://example.com/page', 'MyBot'));
    }

    public function testEmptyDisallowAllowsEverything(): void {
        $parser = new RobotsTxtParser("User-agent: *\nDisallow:");
        $this->assertTrue($parser->isAllowed('https://example.com/anything', 'MyBot'));
    }

    public function testDisallowSpecificPath(): void {
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /admin/");
        $this->assertFalse($parser->isAllowed('https://example.com/admin/users', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/public', 'MyBot'));
    }

    public function testSpecificUserAgentWinsOverWildcard(): void {
        $content = "User-agent: *\nDisallow: /\n\nUser-agent: MyBot\nAllow: /";
        $parser = new RobotsTxtParser($content);
        $this->assertTrue($parser->isAllowed('https://example.com/page', 'MyBot'));
        $this->assertFalse($parser->isAllowed('https://example.com/page', 'OtherBot'));
    }

    public function testUserAgentMatchingIsCaseInsensitive(): void {
        $parser = new RobotsTxtParser("User-agent: mybot\nDisallow: /admin/");
        $this->assertFalse($parser->isAllowed('https://example.com/admin/x', 'MyBot'));
        $this->assertFalse($parser->isAllowed('https://example.com/admin/x', 'MYBOT'));
    }

    public function testUserAgentMatchesByPrefix(): void {
        // robots.txt agent should match the start of our crawler's UA token
        $parser = new RobotsTxtParser("User-agent: osTicket\nDisallow: /private/");
        $this->assertFalse(
            $parser->isAllowed('https://example.com/private/x', 'osTicket-AI-Crawler/1.0')
        );
    }

    public function testLongestMatchWins(): void {
        // Pattern length determines specificity per RFC 9309
        $content = "User-agent: *\nAllow: /folder/\nDisallow: /folder/private/";
        $parser = new RobotsTxtParser($content);
        $this->assertTrue($parser->isAllowed('https://example.com/folder/public', 'MyBot'));
        $this->assertFalse($parser->isAllowed('https://example.com/folder/private/x', 'MyBot'));
    }

    public function testAllowWinsOnTie(): void {
        // When Allow and Disallow have equal-length patterns, Allow wins
        $content = "User-agent: *\nDisallow: /page\nAllow: /page";
        $parser = new RobotsTxtParser($content);
        $this->assertTrue($parser->isAllowed('https://example.com/page', 'MyBot'));
    }

    public function testWildcardStarMatchesAnyChars(): void {
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /*.pdf");
        $this->assertFalse($parser->isAllowed('https://example.com/docs/file.pdf', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/docs/file.html', 'MyBot'));
    }

    public function testDollarAnchorsEndOfPath(): void {
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /private$");
        $this->assertFalse($parser->isAllowed('https://example.com/private', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/private/sub', 'MyBot'));
    }

    public function testCommentsAndBlankLinesIgnored(): void {
        $content = "# top comment\n\nUser-agent: *  # trailing\nDisallow: /admin  # also trailing\n";
        $parser = new RobotsTxtParser($content);
        $this->assertFalse($parser->isAllowed('https://example.com/admin/x', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/public', 'MyBot'));
    }

    public function testMatchUsesPathOnly(): void {
        // Host/scheme should not affect matching
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /x");
        $this->assertFalse($parser->isAllowed('http://other.example.com/x/y', 'MyBot'));
    }

    public function testUnknownDirectivesIgnored(): void {
        // Sitemap, Crawl-delay, etc. should not break parsing
        $content = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nCrawl-delay: 10\nDisallow: /a";
        $parser = new RobotsTxtParser($content);
        $this->assertFalse($parser->isAllowed('https://example.com/a', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/b', 'MyBot'));
    }

    public function testMultipleAgentsShareRules(): void {
        $content = "User-agent: BotA\nUser-agent: BotB\nDisallow: /shared/";
        $parser = new RobotsTxtParser($content);
        $this->assertFalse($parser->isAllowed('https://example.com/shared/x', 'BotA'));
        $this->assertFalse($parser->isAllowed('https://example.com/shared/x', 'BotB'));
        $this->assertTrue($parser->isAllowed('https://example.com/shared/x', 'BotC'));
    }
}
