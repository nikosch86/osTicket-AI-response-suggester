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

1. Download or clone this repository into your osTicket plugins directory:

   ```bash
   cp -r ai-response-suggester /path/to/osticket/include/plugins/
   ```

2. Log in to the osTicket **Admin Panel**.

3. Navigate to **Manage > Plugins > Add New Plugin**.

4. Select **AI Response Suggester** from the list and click **Install**.

5. After installation, click the plugin name to open its configuration page.

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

# Build distributable archive
make build

# Clean build artifacts
make clean
```

## License

[Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)](https://creativecommons.org/licenses/by-nc/4.0/) — you may use, modify, and redistribute this plugin for non-commercial purposes provided you give appropriate credit. See [LICENSE](LICENSE) for the full legal text.
