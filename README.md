# Fetch and Summarize

A per-user FreshRSS extension that fetches each article's original web page, extracts its text, and requests an AI summary. If a publisher blocks server-side fetching, the extension can fall back to the text included in the FreshRSS feed entry; this may be only an excerpt. It does not save article HTML or extracted text. Summaries are cached per user and rendered as plain text in the article stream.

## This extension is built completely via AI.
I don't know much about FreshRSS extensions and I'm just a novice with PHP. So, I won't claim that I thoroughly understand how it works. But I tested it on my personal single-user FreshRSS setup for weeks without issues.

I understand most people have reservations about using slopcoded applications. Still, feel free to analyze, use, debug, revise, or otherwise comment on the code. The code is yours, hoping it will make your RSS setup a bit more useful.

## Requirements

- FreshRSS 1.28.2 or newer.
- PHP 8.1 or newer with `curl`, `dom`, `xml`, `mbstring`, and `filter` extensions enabled.
- Outbound HTTPS access to the selected provider. Ollama must be reachable from the FreshRSS server.

## Installation
Assuming you already have a running FreshRSS instance, installing the extension is just about dropping its folder into FreshRSS's `extensions/` directory and enabling it from the web UI.

### 1. Put the files in place

The goal is to have the extension's files at `<FreshRSS>/extensions/xExtension-FetchAndSummarize/`:

```bash
cd /.../FreshRSS/extensions
# or depending on your setup, /.../FreshRSS/public/extensions
sudo git clone https://github.com/cevvalkoala/xExtension-FetchAndSummarize
# make sure the web server user can read it (skip if not applicable)
sudo chown -R www-data:www-data xExtension-FetchAndSummarize
```

### 2. Enable it in FreshRSS

1. Open FreshRSS → **Settings (gear) → Extensions**.
2. Find **Fetch and Summarize** in the list and click **Enable**.
3. Click on the gears icon next to the extension's name, to open its configuration page.
4. Select a provider, set the API URL, enter the API key if required.
5. Click **Load models**. The models provided by your provider (or Ollama setup) will be listed in the box. Choose the one you like. Click **Save**.
6. Open the normal article stream and use **F&S** on an article.
7. Use **F&S Unread** beside the selected feed name to summarize all unread entries in that feed. The batch processes every unread item; it does not stop at the cache limit.

The summary action accepts only a FreshRSS entry ID and looks up its link in the current user's entry store. The **F&S Unread** action accepts a feed ID and queries unread entry IDs from that same user's store. Neither action accepts article URLs from the browser.

## Provider settings

The API URL is a base URL; the extension adds the provider endpoint. HTTPS is required for hosted providers. URLs cannot contain credentials, queries, or fragments. Model names are entered manually; there is no model-catalog request.

| Provider | Default API URL | Endpoint | Example model | API key |
| --- | --- | --- | --- | --- |
| OpenAI | `https://api.openai.com/v1` | `/chat/completions` | `gpt-4o-mini` | Required |
| Anthropic | `https://api.anthropic.com` | `/v1/messages` | `claude-3-7-sonnet-latest` | Required |
| Google Gemini | `https://generativelanguage.googleapis.com` | `/v1beta/models/{model}:generateContent` | `gemini-2.5-flash` | Required |
| OpenRouter | `https://openrouter.ai/api/v1` | `/chat/completions` | `openai/gpt-4o-mini` | Required |
| Ollama | `http://127.0.0.1:11435` | `/api/chat` | `llama3.2` | Not used |

Use **Load models** after choosing the provider and entering its API key to request model names. Keys are sent from the browser only to this FreshRSS server and are used server-side for the provider request; model lookup does not save them. The model field remains editable for custom or unavailable model names. For Ollama, the model must already be installed on the Ollama server. Ollama's default API port is commonly `11434`; this extension uses the planned example `11435`, so set the base URL to the port actually configured on your host. A typical same-host endpoint is `http://127.0.0.1:11434`.

When FreshRSS runs in a container, `127.0.0.1` addresses that container, not the Docker host. Configure the API URL to a service name or host address reachable from the FreshRSS container, and restrict that network path. Do not expose Ollama's unauthenticated API to an untrusted network.

## Configuration and privacy

Provider, model, API URL, prompt, and key are stored in the current user's FreshRSS configuration. The key field is always blank after saving; leaving it blank preserves the saved key only while the provider and API URL remain unchanged. Changing provider or URL clears the old key to prevent forwarding it to another endpoint. **Clear the saved API key** removes it explicitly. The per-user summary cache contains entry IDs, summaries, source labels, and non-reversible signatures; it does not contain API keys or article text. The cache is capped at 300 entries and 3 MB, evicting the oldest summaries when new entries exceed either limit. If an unread-feed batch contains more summaries than the cache can hold, the oldest results are evicted as the batch continues; requesting an evicted result later may generate it again. Protect the data directory and backups with host filesystem permissions. Use HTTPS for FreshRSS and handle backups as sensitive data.

With hosted providers, extracted article text and the custom prompt are sent to that third party. Ollama can keep model processing on your own server, subject to its network configuration. Review each provider's privacy terms before use.

Article fetching is restricted to HTTP(S) public-routable destinations. Every resolved address is checked, the connection is pinned to a checked address, the connected address is verified, and every redirect is checked again. Private, loopback, link-local, reserved, and non-public addresses are rejected. The fetcher does not forward browser cookies or authorization headers. These controls apply to article URLs, not administrator-configured provider URLs.

Custom provider URLs, especially Ollama URLs, are server-side network destinations. Only trusted FreshRSS users should be permitted to configure them. On multi-user installations, restrict who can enable/configure this extension and apply outbound firewall controls; per-user settings alone do not make custom endpoints safe for untrusted users.

Fetched pages, feed excerpts, and model responses are treated as untrusted data. Article or feed text is sent as plain text with an explicit untrusted-data instruction; feed-provided text is identified to the model as potentially incomplete. Model output is inserted with text-only DOM APIs. Article HTML and extracted article/feed text are not persisted. JavaScript-rendered, paywalled, anti-bot-protected, or otherwise inaccessible pages may only provide the shorter text available in the feed, or may not yield usable text at all.

## Timeouts and limits

Article fetching has an 8-second connection timeout, a 45-second total budget, at most four redirects, and a 2 MB response cap. Extracted article text is capped at 50,000 bytes. Provider requests have an 8-second connection timeout and a 180-second total timeout, with no automatic retries. Make sure PHP's `max_execution_time`, PHP-FPM/FastCGI request timeout, and any reverse-proxy/web-server timeout allow at least four minutes plus overhead; otherwise the host may terminate the request before the provider returns.

## Development tests

Run the dependency-free test harness with PHP 8.1+ and the required extensions:

```sh
php tests/run.php
node tests/frontend.test.js
```

The tests use fake provider transports and fake DNS/HTTP transports. They do not call public websites or AI services. Manual live verification should use a public article URL and each configured provider; verify the Ollama URL from the FreshRSS host itself.

## License

This extension is dedicated to the public domain under CC0 1.0 Universal. See `LICENSE` for the full legal code.
