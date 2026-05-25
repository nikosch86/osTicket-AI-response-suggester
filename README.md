# AI Response Suggester for osTicket

An osTicket plugin that helps support agents by suggesting responses to tickets using AI. It combines three sources of context to produce high-quality suggestions:

1. **Ticket context** -- subject, body, and conversation history
2. **Canned responses** -- existing department-filtered templates, matched and customized by the AI
3. **Knowledge base** -- content crawled from your documentation website

Supports **OpenAI**, **Anthropic**, and any **OpenAI-compatible** API endpoint.

## Requirements

- osTicket 1.18+
- PHP 8.0+
- cURL extension enabled
- An API key from OpenAI or Anthropic (or a self-hosted OpenAI-compatible endpoint)

## Installation

Two artifacts are published with each [GitHub Release](https://github.com/nikosch86/osTicket-AI-response-suggester/releases):

| Artifact | When to pick it |
|---|---|
| **`ai-response-suggester-X.Y.Z.phar`** | Recommended default. Single file, PHP-signed (SHA-256) — load-time integrity is automatic, atomic replacement on upgrade. Same shape as osTicket's official `storage-fs.phar`. |
| **`ai-response-suggester-X.Y.Z.tar.gz`** | Pick when you want to inspect or patch the files locally, or when your environment can't load phars. |

A `SHA256SUMS` file is published alongside both for out-of-band verification. Pick one artifact — don't install both.

### Recommended: phar install

```bash
VERSION=1.0.1
BASE="https://github.com/nikosch86/osTicket-AI-response-suggester/releases/download/v${VERSION}"
curl -fsSLO "${BASE}/ai-response-suggester-${VERSION}.phar"
curl -fsSLO "${BASE}/SHA256SUMS"
sha256sum --ignore-missing -c SHA256SUMS

install -m 0644 -o www-data -g www-data \
  "ai-response-suggester-${VERSION}.phar" \
  /path/to/osticket/include/plugins/ai-response-suggester.phar
```

PHP also verifies the phar's built-in SHA-256 signature every time it's loaded — so the external `sha256sum` check is belt-and-braces, not strictly required.

### Alternative: tarball install

```bash
VERSION=1.0.1
BASE="https://github.com/nikosch86/osTicket-AI-response-suggester/releases/download/v${VERSION}"
curl -fsSLO "${BASE}/ai-response-suggester-${VERSION}.tar.gz"
curl -fsSLO "${BASE}/SHA256SUMS"
sha256sum --ignore-missing -c SHA256SUMS

tar -xzf "ai-response-suggester-${VERSION}.tar.gz" -C /path/to/osticket/include/plugins/
chown -R www-data:www-data /path/to/osticket/include/plugins/ai-response-suggester
```

This creates `/path/to/osticket/include/plugins/ai-response-suggester/`. Adjust ownership to whatever user PHP-FPM runs as on your host.

### Enable in osTicket

1. Log in to the osTicket **Admin Panel**.
2. Navigate to **Manage → Plugins → Add New Plugin**.
3. Select **AI Response Suggester** and click **Install**.
4. Click the plugin name to open its configuration page (continue with [Configuration](#configuration)).

## Upgrade

**Phar install** — replace the file:

```bash
VERSION=1.0.2
# download + verify as in Installation
install -m 0644 -o www-data -g www-data \
  "ai-response-suggester-${VERSION}.phar" \
  /path/to/osticket/include/plugins/ai-response-suggester.phar
```

**Tarball install** — extract over the existing directory:

```bash
VERSION=1.0.2
# download + verify as in Installation
tar -xzf "ai-response-suggester-${VERSION}.tar.gz" -C /path/to/osticket/include/plugins/
```

Either way, osTicket detects the new manifest version (`plugin.php`'s `version` field) on the next admin page load and surfaces an "upgrade available" prompt where appropriate.

> **Schema changes between versions are not automated yet** — see [Limitations](#limitations). Until that gap is closed, any release that requires a schema change documents the manual `ALTER` in its release notes.

## Deployer responsibilities

This plugin does not orchestrate its own deployment. If you are scripting installs (Ansible, Docker, CI/CD pipelines), the following are your responsibility, not the plugin's:

- **Opcache invalidation.** PHP-FPM running with `opcache.validate_timestamps=0` will keep old code in memory until php-fpm is reloaded. Freshly-extracted files will appear to do nothing until you `systemctl reload php-fpm` (or equivalent).
- **File ownership and permissions.** The user PHP-FPM runs as must be able to read the deployed artifact — either every file under `ai-response-suggester/` (tarball install) or the single `ai-response-suggester.phar` file (phar install).
- **One-time admin install/enable on a fresh install.** osTicket's plugin registry lives in the database. The first time the plugin is deployed, a human must click **Install** and **Enable** in the admin UI (or write directly to `ost_plugin`). Subsequent file-only upgrades do not need this step.

## Configuration

After installing, configure the plugin under **Admin Panel > Manage > Plugins > AI Response Suggester**.

### AI Provider Settings

| Field | Description |
|---|---|
| **AI Provider** | Choose `OpenAI`, `Anthropic`, or `Custom (OpenAI-compatible)`. The API URL is auto-set for OpenAI and Anthropic. |
| **API Key** | Your provider API key (required). |
| **API URL** | Only required for `Custom` provider. Auto-populated for OpenAI/Anthropic on save. |
| **Model** | Model name to use (default: `gpt-4o-mini`). Examples: `gpt-4o`, `claude-sonnet-4-20250514`. |

### Response Settings

| Field | Description |
|---|---|
| **Custom System Prompt** | Optional additional instructions appended to the default system prompt. |
| **Response Template** | Optional wrapper around the AI output. Tokens: `{ai_text}`, `{ticket_number}`, `{user_name}`, `{agent_name}`. |
| **Confidence Threshold** | Score (0-100, default: 60) below which a warning banner is shown. |
| **Max Canned Responses** | Maximum canned responses sent to the AI for analysis (default: 15). |
| **Temperature** | Controls randomness (0.0-2.0, default: 0.3). Lower values produce more deterministic output. |
| **API Timeout** | Maximum seconds to wait for AI response (default: 30). |

### Knowledge Base Crawler

| Field | Description |
|---|---|
| **Knowledge Base URL** | Base URL to crawl for documentation content. A **Crawl Now** button and **View Content** button appear next to this field. |
| **Crawl Depth** | Maximum link depth to follow (1-10, default: 3). |
| **Max Pages to Crawl** | Maximum number of pages to crawl (1-200, default: 50). |
| **Summarize with AI** | When enabled, each crawled page is sent through the AI to extract only support-relevant content (procedures, FAQs, policies). This produces cleaner knowledge base context but uses API tokens per page. |
| **Respect robots.txt** | When enabled (default), the crawler fetches `/robots.txt` from the target host and skips URLs disallowed for `osTicket-AI-Crawler`. Disable only when crawling internal docs you control. |
| **Skip Patterns** | Optional list of URL patterns (one per line) to skip in addition to `robots.txt`. Matched against path+query. Wildcards: `*` = any chars, `$` = end-of-path. Patterns starting with `/` anchor to the path start; otherwise they may match anywhere. Example: `/admin/`, `*/drafts/*`, `/private$`. |

### Debug

| Field | Description |
|---|---|
| **Enable Debug Logging** | Logs AI requests and responses to the PHP error log. |

## Usage

### Suggesting a Response

1. Open a ticket in the **Staff Panel**.
2. Click the **AI Suggest Response** button next to the canned response dropdown.
3. Wait for the AI to analyze the ticket and generate a suggestion.
4. Review the suggestion panel, which shows:
   - **Confidence score** (color-coded: green/yellow/red)
   - Whether the suggestion is **based on a canned response** or **freely generated**
   - The AI's **reasoning** for its choice
   - A **preview** of the suggested response
   - A **low-confidence warning** if the score is below your threshold
5. Click **Use This Response** to insert the text into the reply editor.

### Crawling a Knowledge Base

1. Set the **Knowledge Base URL** in the plugin configuration.
2. Click the **Crawl Now** button that appears next to the URL field.
3. Wait for the crawl to complete — the status text will update with the number of pages crawled.
4. The crawler performs a breadth-first traversal of same-domain pages, stripping navigation, headers, footers, scripts, and styles to extract clean text content.
5. Subsequent AI suggestions will automatically incorporate the crawled knowledge base as additional context.

The current crawl status (page count and last crawl date) is shown next to the button when pages have been previously crawled.

### Viewing and Managing Crawled Content

1. Click **View Content** next to the Knowledge Base URL field.
2. A modal opens showing all crawled pages in a table: URL, title, content preview, depth.
3. Pages that were AI-summarized show a "summarized" badge — the summary is what gets sent to the AI at suggest time, not the raw text.
4. Click **Delete** on any row to remove a single page from the knowledge base.

## How It Works

```
Ticket opened by customer
         │
         ▼
┌─────────────────────┐
│ TicketContextBuilder │──► subject, body, thread history
└─────────────────────┘
         │
         ▼
┌───────────────────────┐
│ CannedResponseProvider │──► department-filtered canned responses
└───────────────────────┘
         │
         ▼
┌──────────────┐
│ ContentStore │──► crawled knowledge base text
└──────────────┘
         │
         ▼
┌───────────────┐
│ PromptBuilder │──► system + user messages with JSON output instructions
└───────────────┘
         │
         ▼
┌─────────────────┐
│ AIClientFactory │──► OpenAI / Anthropic / Custom client
└─────────────────┘
         │
         ▼
   AI returns JSON:
   {
     "response_text": "...",
     "based_on_canned_id": 42 or null,
     "confidence": 85,
     "reasoning": "..."
   }
         │
         ▼
   Displayed in suggestion panel
```

## Project Structure

```
ai-response-suggester/
├── plugin.php                          # Plugin manifest
├── src/
│   ├── Plugin.php                      # Bootstrap, signals, asset injection
│   ├── Config.php                      # Admin configuration form
│   ├── AjaxController.php              # AJAX endpoints (suggest, crawl, crawl-status)
│   ├── TicketContextBuilder.php        # Extracts ticket context
│   ├── CannedResponseProvider.php      # Fetches department-filtered canned responses
│   ├── PromptBuilder.php               # Assembles AI prompt from all sources
│   ├── WebCrawler.php                  # BFS website crawler (same-domain, depth-limited)
│   ├── RobotsTxtParser.php             # RFC 9309 robots.txt matcher
│   ├── ContentStore.php                # DB table CRUD for crawled content
│   └── AIClient/
│       ├── AIClientInterface.php       # Common interface
│       ├── OpenAIClient.php            # OpenAI Chat Completions API
│       ├── AnthropicClient.php         # Anthropic Messages API
│       └── AIClientFactory.php         # Creates correct client from config
├── assets/
│   ├── .htaccess                       # Allow browser access to static files
│   ├── js/main.js                      # Button injection, AJAX, suggestion panel
│   └── css/style.css                   # Panel styles, confidence badges
├── tests/                              # PHPUnit tests
│   ├── bootstrap.php
│   ├── AIClientFactoryTest.php
│   ├── AnthropicClientTest.php
│   ├── OpenAIClientTest.php
│   ├── PromptBuilderTest.php
│   └── WebCrawlerTest.php
├── docs/
│   └── adr/                            # Architecture decision records
├── .github/
│   └── workflows/                      # CI on push/PR; release builds + publishes on v* tag push
├── bin/
│   └── build-phar.php                  # Builds a signed .phar from the staged plugin tree
├── Makefile                            # Docker-based test/lint/build targets
├── composer.json
└── phpunit.xml
```

## Development

All development commands use Docker (no local PHP required).

```bash
# Install dependencies
make install-dev

# Run tests (33 tests, 85 assertions)
make test

# Run linter (requires: make install-lint)
make lint

# Build both release artifacts (.tar.gz + signed .phar) plus SHA256SUMS into dist/
# (VERSION auto-extracted from plugin.php; override with VERSION=x.y.z)
make build

# Clean build artifacts
make clean
```

Releases are tag-driven and built by CI — see [`docs/adr/0001-release-pipeline.md`](docs/adr/0001-release-pipeline.md). Cutting a release is: bump `plugin.php`'s `version`, commit, `git tag vX.Y.Z && git push --tags`. The workflow validates the tag against the manifest, builds both artifacts, and publishes the Release.

## Limitations

- **No schema migration mechanism.** The plugin creates its `ai_crawler_content` table on first load via `CREATE TABLE IF NOT EXISTS`, but has no mechanism to `ALTER` an existing table when the schema changes between versions. The first release that requires a schema change will need to ship an in-plugin migration runner alongside it. See [`docs/adr/0001-release-pipeline.md`](docs/adr/0001-release-pipeline.md).

## License

[Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)](https://creativecommons.org/licenses/by-nc/4.0/) — you may use, modify, and redistribute this plugin for non-commercial purposes provided you give appropriate credit. See [LICENSE](LICENSE) for the full legal text.
