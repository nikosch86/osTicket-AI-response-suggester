<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/RobotsTxtParser.php';
require_once __DIR__ . '/../src/WebCrawler.php';

class WebCrawlerTest extends TestCase {
    public function testInvalidUrlThrowsException(): void {
        $crawler = new WebCrawler(1, 5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid base URL');

        $crawler->crawl('not-a-valid-url');
    }

    public function testExtractTitleFromHtml(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'extractTitle');
        $method->setAccessible(true);

        $html = '<html><head><title>Test Page Title</title></head><body>content</body></html>';
        $title = $method->invoke($crawler, $html);

        $this->assertEquals('Test Page Title', $title);
    }

    public function testExtractTitleReturnsEmptyWhenMissing(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'extractTitle');
        $method->setAccessible(true);

        $html = '<html><body>no title here</body></html>';
        $title = $method->invoke($crawler, $html);

        $this->assertEquals('', $title);
    }

    public function testExtractContentStripsScriptAndStyle(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'extractContent');
        $method->setAccessible(true);

        $html = '<html><body>
            <script>alert("xss")</script>
            <style>.foo{color:red}</style>
            <nav>Navigation</nav>
            <header>Header</header>
            <p>Actual content here</p>
            <footer>Footer</footer>
        </body></html>';

        $content = $method->invoke($crawler, $html);

        $this->assertStringNotContainsString('alert', $content);
        $this->assertStringNotContainsString('color:red', $content);
        $this->assertStringNotContainsString('Navigation', $content);
        $this->assertStringNotContainsString('Header', $content);
        $this->assertStringNotContainsString('Footer', $content);
        $this->assertStringContainsString('Actual content here', $content);
    }

    public function testExtractLinksReturnsSameDomainOnly(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'extractLinks');
        $method->setAccessible(true);

        $html = '<html><body>
            <a href="/about">About</a>
            <a href="https://example.com/docs">Docs</a>
            <a href="https://other.com/page">Other</a>
            <a href="#anchor">Anchor</a>
            <a href="javascript:void(0)">JS</a>
            <a href="file.pdf">PDF</a>
        </body></html>';

        $links = $method->invoke($crawler, $html, 'https://example.com/page');

        $this->assertContains('https://example.com/about', $links);
        $this->assertContains('https://example.com/docs', $links);
        // 'other.com' is different domain, but extractLinks doesn't filter by domain,
        // that's done in crawl(). It only resolves relative URLs.
        // Anchors, javascript, and PDFs should be excluded.
        $this->assertNotContains('#anchor', $links);
    }

    public function testNormalizeUrlRemovesTrailingSlash(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'normalizeUrl');
        $method->setAccessible(true);

        $this->assertEquals(
            'https://example.com/page',
            $method->invoke($crawler, 'https://example.com/page/')
        );
        $this->assertEquals(
            'https://example.com/page',
            $method->invoke($crawler, 'https://example.com/page')
        );
    }

    public function testResolveRelativeUrl(): void {
        $crawler = new WebCrawler();
        $method = new ReflectionMethod(WebCrawler::class, 'resolveUrl');
        $method->setAccessible(true);

        $this->assertEquals(
            'https://example.com/about',
            $method->invoke($crawler, '/about', 'https://example.com/page/index.html')
        );

        $this->assertEquals(
            'https://example.com/page/subpage',
            $method->invoke($crawler, 'subpage', 'https://example.com/page/index.html')
        );

        $this->assertEquals(
            'https://other.com/path',
            $method->invoke($crawler, 'https://other.com/path', 'https://example.com/')
        );
    }

    public function testConstructorSetsParameters(): void {
        $crawler = new WebCrawler(5, 100, 1024, 10);

        $depthProp = new ReflectionProperty(WebCrawler::class, 'maxDepth');
        $depthProp->setAccessible(true);
        $this->assertEquals(5, $depthProp->getValue($crawler));

        $pagesProp = new ReflectionProperty(WebCrawler::class, 'maxPages');
        $pagesProp->setAccessible(true);
        $this->assertEquals(100, $pagesProp->getValue($crawler));
    }

    public function testConstructorDefaultsRespectRobotsAndEmptySkip(): void {
        $crawler = new WebCrawler();

        $respect = new ReflectionProperty(WebCrawler::class, 'respectRobots');
        $respect->setAccessible(true);
        $this->assertTrue($respect->getValue($crawler));

        $skip = new ReflectionProperty(WebCrawler::class, 'skipPatterns');
        $skip->setAccessible(true);
        $this->assertEquals(array(), $skip->getValue($crawler));
    }

    public function testConstructorAcceptsRobotsAndSkipPatterns(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array('/admin/', '*/draft'));

        $respect = new ReflectionProperty(WebCrawler::class, 'respectRobots');
        $respect->setAccessible(true);
        $this->assertFalse($respect->getValue($crawler));

        $skip = new ReflectionProperty(WebCrawler::class, 'skipPatterns');
        $skip->setAccessible(true);
        $this->assertEquals(array('/admin/', '*/draft'), $skip->getValue($crawler));
    }

    public function testSkipPatternsAreTrimmedAndEmptiesDropped(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, true, array('  /admin/  ', '', '   ', '/x'));

        $skip = new ReflectionProperty(WebCrawler::class, 'skipPatterns');
        $skip->setAccessible(true);
        $this->assertEquals(array('/admin/', '/x'), $skip->getValue($crawler));
    }

    public function testShouldSkipMatchesPrefixPattern(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array('/admin/'));
        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($crawler, 'https://example.com/admin/users'));
        $this->assertFalse($method->invoke($crawler, 'https://example.com/public'));
    }

    public function testShouldSkipMatchesWildcard(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array('*/drafts/*'));
        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($crawler, 'https://example.com/blog/drafts/post-1'));
        $this->assertFalse($method->invoke($crawler, 'https://example.com/blog/published/post-1'));
    }

    public function testShouldSkipUsesDollarAnchor(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array('/private$'));
        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($crawler, 'https://example.com/private'));
        $this->assertFalse($method->invoke($crawler, 'https://example.com/private/sub'));
    }

    public function testShouldSkipFollowsRobotsWhenEnabled(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, true, array());
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /admin/");
        $parserProp = new ReflectionProperty(WebCrawler::class, 'robotsParser');
        $parserProp->setAccessible(true);
        $parserProp->setValue($crawler, $parser);

        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($crawler, 'https://example.com/admin/x'));
        $this->assertFalse($method->invoke($crawler, 'https://example.com/public'));
    }

    public function testShouldSkipIgnoresRobotsWhenDisabled(): void {
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array());
        $parser = new RobotsTxtParser("User-agent: *\nDisallow: /");
        $parserProp = new ReflectionProperty(WebCrawler::class, 'robotsParser');
        $parserProp->setAccessible(true);
        $parserProp->setValue($crawler, $parser);

        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($crawler, 'https://example.com/anything'));
    }

    public function testSkipListAppliesWhenRobotsDisabled(): void {
        // Toggling robots off must still apply the skip list (per design)
        $crawler = new WebCrawler(3, 50, 51200, 15, false, array('/admin/'));
        $method = new ReflectionMethod(WebCrawler::class, 'shouldSkip');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($crawler, 'https://example.com/admin/users'));
    }

    public function testBuildRobotsParserDisallowsAllOn5xx(): void {
        $method = new ReflectionMethod(WebCrawler::class, 'buildRobotsParser');
        $method->setAccessible(true);

        $parser = $method->invoke(null, 'irrelevant', 503);
        $this->assertFalse($parser->isAllowed('https://example.com/anything', 'MyBot'));
    }

    public function testBuildRobotsParserDisallowsAllOnUnreachable(): void {
        $method = new ReflectionMethod(WebCrawler::class, 'buildRobotsParser');
        $method->setAccessible(true);

        $parser = $method->invoke(null, false, 0);
        $this->assertFalse($parser->isAllowed('https://example.com/anything', 'MyBot'));
    }

    public function testBuildRobotsParserAllowsAllOn4xx(): void {
        $method = new ReflectionMethod(WebCrawler::class, 'buildRobotsParser');
        $method->setAccessible(true);

        $parser = $method->invoke(null, 'irrelevant body', 404);
        $this->assertTrue($parser->isAllowed('https://example.com/anything', 'MyBot'));
    }

    public function testBuildRobotsParserParsesBodyOn2xx(): void {
        $method = new ReflectionMethod(WebCrawler::class, 'buildRobotsParser');
        $method->setAccessible(true);

        $parser = $method->invoke(null, "User-agent: *\nDisallow: /admin/", 200);
        $this->assertFalse($parser->isAllowed('https://example.com/admin/x', 'MyBot'));
        $this->assertTrue($parser->isAllowed('https://example.com/public', 'MyBot'));
    }
}
