<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

use FetchAndSummarize\ArticleExtractor;
use FetchAndSummarize\ArticleFetcher;
use FetchAndSummarize\EntryId;
use FetchAndSummarize\ProviderClient;
use FetchAndSummarize\Settings;

$passed = 0;
$failed = 0;
$test = static function (string $name, callable $assertion) use (&$passed, &$failed): void {
	try {
		$assertion();
		$passed++;
		echo "PASS {$name}\n";
	} catch (Throwable $e) {
		$failed++;
		fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n");
	}
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
};
$throws = static function (callable $callback, string $class = RuntimeException::class): Throwable {
	try {
		$callback();
	} catch (Throwable $e) {
		if (!$e instanceof $class) {
			throw new RuntimeException('expected ' . $class . ', got ' . get_class($e));
		}
		return $e;
	}
	throw new RuntimeException('expected ' . $class . ' to be thrown');
};

$test('public IP policy rejects private and reserved IPv4/IPv6', static function () use ($assert): void {
	foreach (['127.0.0.1', '10.1.2.3', '172.20.1.1', '192.168.1.1', '169.254.10.20', '100.64.0.1', '192.0.2.1', '198.51.100.4', '203.0.113.2', '0.0.0.0', '224.0.0.1', '240.0.0.1', '::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', 'fe80::1', 'fc00::1', '2001:db8::1', '2002::1'] as $ip) {
		$assert(!ArticleFetcher::isPublicIp($ip), 'unexpectedly accepted ' . $ip);
	}
	foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
		$assert(ArticleFetcher::isPublicIp($ip), 'unexpectedly rejected ' . $ip);
	}
});

$test('article URL accepts only HTTP(S) without userinfo', static function () use ($assert, $throws): void {
	$parsed = ArticleFetcher::parsePublicHttpUrl('https://news.example/story?id=1');
	$assert($parsed['host'] === 'news.example' && $parsed['port'] === 443);
	$fragment = ArticleFetcher::parsePublicHttpUrl('http://example.com/#fragment');
	$assert($fragment['host'] === 'example.com');
	foreach (['file:///etc/passwd', 'ftp://example.com/a', 'http://user@example.com/'] as $url) {
		$throws(static fn () => ArticleFetcher::parsePublicHttpUrl($url));
	}
});

$test('DNS answers are all checked before making a request', static function () use ($assert, $throws): void {
	$requests = 0;
	$fetcher = new ArticleFetcher(
		static fn (string $host): array => ['8.8.8.8', '10.0.0.4'],
		static function () use (&$requests): array { $requests++; return []; }
	);
	$throws(static fn () => $fetcher->fetch('https://news.example/'));
	$assert($requests === 0, 'unsafe DNS answer reached transport');
});

$test('redirects are revalidated before a second connection', static function () use ($assert, $throws): void {
	$requests = 0;
	$fetcher = new ArticleFetcher(
		static fn (string $host): array => $host === 'news.example' ? ['8.8.8.8'] : ['127.0.0.1'],
		static function (string $url, array $addresses) use (&$requests): array {
			$requests++;
			return ['status' => 302, 'headers' => ['location' => 'http://127.0.0.1/private'], 'body' => '', 'primary_ip' => $addresses[0]];
		}
	);
	$throws(static fn () => $fetcher->fetch('https://news.example/story'));
	$assert($requests === 1, 'redirect to loopback reached transport');
});

$test('connected IP must match a validated DNS answer', static function () use ($throws): void {
	$fetcher = new ArticleFetcher(
		static fn (): array => ['8.8.8.8'],
		static fn (): array => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<p>ok</p>', 'primary_ip' => '1.1.1.1']
	);
	$throws(static fn () => $fetcher->fetch('https://news.example/'));
});

$test('article fetch accepts bounded HTML from a pinned public host', static function () use ($assert): void {
	$fetcher = new ArticleFetcher(
		static fn (): array => ['8.8.8.8'],
		static fn (string $url, array $addresses): array => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => '<html>article</html>', 'primary_ip' => $addresses[0]]
	);
	$result = $fetcher->fetch('https://news.example/story');
	$assert($result['html'] === '<html>article</html>');
});

$test('redirect limit and non-HTML response checks are enforced', static function () use ($assert, $throws): void {
	$requests = 0;
	$redirecting = new ArticleFetcher(
		static fn (): array => ['8.8.8.8'],
		static function (string $url, array $addresses) use (&$requests): array {
			$requests++;
			return ['status' => 302, 'headers' => ['location' => '/again'], 'body' => '', 'primary_ip' => $addresses[0]];
		}
	);
	$throws(static fn () => $redirecting->fetch('https://news.example/story'));
	$assert($requests === 5, 'redirect limit should allow four hops then reject');
	$nonHtml = new ArticleFetcher(
		static fn (): array => ['8.8.8.8'],
		static fn (string $url, array $addresses): array => ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => 'pdf', 'primary_ip' => $addresses[0]]
	);
	$throws(static fn () => $nonHtml->fetch('https://news.example/document'));
});

$test('Readability extracts text and strips page noise', static function () use ($assert): void {
	$html = '<html><head><title>News</title><script>doNotInclude()</script></head><body><nav>Noise navigation</nav><article><h1>Useful story</h1><p>This article has a substantial amount of readable text that should be extracted from the page and sent to the selected model as plain text.</p><p>A second paragraph gives the extraction enough context and keeps this test independent of any public website or network service.</p></article></body></html>';
	$text = (new ArticleExtractor())->extract($html, 'https://news.example/story');
	$assert(str_contains($text, 'Useful story') && str_contains($text, 'second paragraph'));
	$assert(!str_contains($text, 'doNotInclude') && !str_contains($text, 'Noise navigation'));
});

$test('empty extraction is rejected', static function () use ($throws): void {
	$throws(static fn () => (new ArticleExtractor())->extract('<html><body></body></html>', 'https://news.example/'));
});

$test('default prompt is exact and custom prompt remains verbatim', static function () use ($assert): void {
	$assert(Settings::prompt('') === 'Fetch the article from the link. If the content is shorter than 400 words, tell me and stop there. If otherwise, give a 200-word summary for this article in FreshRSS interface language.');
	$custom = "  Summarize in two bullets.\nKeep names unchanged.  ";
	$assert(Settings::prompt($custom) === $custom);
});

$test('API key is preserved, replaced, or cleared explicitly', static function () use ($assert, $throws): void {
	$assert(Settings::updateApiKey('saved-secret', '', false) === 'saved-secret');
	$assert(Settings::updateApiKey('saved-secret', 'replacement', false) === 'replacement');
	$assert(Settings::updateApiKey('saved-secret', '', true) === '');
	$throws(static fn () => Settings::updateApiKey('', "bad\r\nInjected: yes", false), InvalidArgumentException::class);
	$assert(Settings::configurationApiKey('openai', Settings::DEFAULT_URLS['openai'], 'openai', Settings::DEFAULT_URLS['openai'], 'saved-secret', '', false) === 'saved-secret');
	$assert(Settings::configurationApiKey('openai', Settings::DEFAULT_URLS['openai'], 'gemini', Settings::DEFAULT_URLS['gemini'], 'saved-secret', '', false) === '');
	$assert(Settings::configurationApiKey('openai', Settings::DEFAULT_URLS['openai'], 'openai', 'https://custom.example/v1', 'saved-secret', '', false) === '');
	$assert(Settings::configurationApiKey('openai', Settings::DEFAULT_URLS['openai'], 'gemini', Settings::DEFAULT_URLS['gemini'], 'saved-secret', 'new-secret', false) === 'new-secret');
	$assert(Settings::modelLookupKey('openai', Settings::DEFAULT_URLS['openai'], '', 'openai', Settings::DEFAULT_URLS['openai'], 'saved-secret') === 'saved-secret');
	$assert(Settings::modelLookupKey('gemini', Settings::DEFAULT_URLS['gemini'], '', 'openai', Settings::DEFAULT_URLS['openai'], 'saved-secret') === '');
	$assert(Settings::modelLookupKey('openai', 'https://custom.example', '', 'openai', Settings::DEFAULT_URLS['openai'], 'saved-secret') === '');
});

$test('FreshRSS configuration is saved atomically and keeps provider keys scoped', static function () use ($assert): void {
	$cases = [
		'save' => static function (array $data) use ($assert): void {
			$settings = $data['extensions']['FetchAndSummarize'];
			$assert($data['saved'] === true && $settings['model'] === 'new-model' && $settings['prompt'] === 'new prompt');
			$assert($settings['api_key'] === 'saved-secret' && $settings['other'] === 'preserve-me');
		},
		'change-provider' => static function (array $data) use ($assert): void {
			$settings = $data['extensions']['FetchAndSummarize'];
			$assert($settings['provider'] === 'gemini' && $settings['api_key'] === '');
		},
		'clear-key' => static function (array $data) use ($assert): void {
			$assert($data['extensions']['FetchAndSummarize']['api_key'] === '');
		},
		'replace-key' => static function (array $data) use ($assert): void {
			$assert($data['extensions']['FetchAndSummarize']['api_key'] === 'replacement-secret');
		},
		'invalid' => static function (array $data) use ($assert): void {
			$assert($data['saved'] === false && $data['notification'][0] === 'bad');
		},
	];
	foreach ($cases as $scenario => $verify) {
		$pipes = [];
		$process = proc_open([PHP_BINARY, __DIR__ . '/configure-case.php', $scenario], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
		if (!is_resource($process)) throw new RuntimeException('could not start configure case');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$assert($status === 0, $scenario . ' child failed: ' . $error);
		$data = json_decode($output, true);
		$assert(is_array($data), $scenario . ' returned invalid configuration state');
		$verify($data);
	}
});

$test('settings reject invalid providers, models, and API URL schemes', static function () use ($throws): void {
	$throws(static fn () => Settings::validate('unknown', 'model', 'https://api.example', ''), InvalidArgumentException::class);
	$throws(static fn () => Settings::validate('openai', 'bad model', 'https://api.openai.com/v1', ''), InvalidArgumentException::class);
	$throws(static fn () => Settings::validate('openai', 'model', 'http://api.example', ''), InvalidArgumentException::class);
	$throws(static fn () => Settings::validate('ollama', 'model', 'http://user:pass@localhost:11435', ''), InvalidArgumentException::class);
	$throws(static fn () => Settings::validate('openai', 'model', 'https://api.example/?token=secret', ''), InvalidArgumentException::class);
	$throws(static fn () => Settings::validate('openai', 'model', Settings::DEFAULT_URLS['openai'], str_repeat('x', 4001)), InvalidArgumentException::class);
});

$test('entry IDs remain numeric strings and never convert to integers', static function () use ($assert): void {
	$assert(EntryId::isValid('98765432101234567890'));
	$assert(!EntryId::isValid('1/2') && !EntryId::isValid('') && !EntryId::isValid(str_repeat('1', 21)));
});

$providerCases = [
	'openai' => ['model' => 'gpt-test', 'endpoint' => '/v1/chat/completions', 'headers' => 'Authorization', 'body' => ['choices' => [['message' => ['content' => 'OpenAI summary']]]]],
	'anthropic' => ['model' => 'claude-test', 'endpoint' => '/v1/messages', 'headers' => 'x-api-key', 'body' => ['content' => [['type' => 'text', 'text' => 'Anthropic summary']]]],
	'gemini' => ['model' => 'gemini-test', 'endpoint' => '/v1beta/models/gemini-test:generateContent', 'headers' => 'x-goog-api-key', 'body' => ['candidates' => [['content' => ['parts' => [['text' => 'Gemini summary']]]]]]],
	'openrouter' => ['model' => 'vendor/model', 'endpoint' => '/v1/chat/completions', 'headers' => 'Authorization', 'body' => ['choices' => [['message' => ['content' => 'OpenRouter summary']]]]],
	'ollama' => ['model' => 'local-model', 'endpoint' => '/api/chat', 'headers' => null, 'body' => ['message' => ['content' => 'Ollama summary']]],
];
foreach ($providerCases as $provider => $case) {
	$test('provider request/response mapping: ' . $provider, static function () use ($assert, $provider, $case): void {
		$captured = null;
		$client = new ProviderClient(static function (string $url, array $headers, string $json, int $timeout, int $connectTimeout) use (&$captured, $case): array {
			$captured = ['url' => $url, 'headers' => $headers, 'payload' => json_decode($json, true), 'timeout' => $timeout, 'connect' => $connectTimeout];
			return ['status' => 200, 'body' => json_encode($case['body'], JSON_THROW_ON_ERROR)];
		});
		$base = Settings::DEFAULT_URLS[$provider];
		$summary = $client->summarize(['provider' => $provider, 'model' => $case['model'], 'api_url' => $base, 'prompt' => 'Exact custom prompt', 'api_key' => $provider === 'ollama' ? '' : 'top-secret'], 'article body');
		$assert($summary !== '');
		$assert($captured['timeout'] === 180 && $captured['connect'] === 8);
		$assert(str_ends_with($captured['url'], $case['endpoint']));
		$assert(!str_contains($captured['url'], 'top-secret'));
		if ($case['headers'] !== null) $assert(isset($captured['headers'][$case['headers']]));
		$payload = $captured['payload'];
		$assert(($payload['stream'] ?? true) === ($provider === 'ollama' ? false : true));
		$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
		$assert(str_contains($encoded, 'Exact custom prompt') && str_contains($encoded, 'article body'));
		$assert(str_contains($encoded, 'untrusted source data'));
	});
}

$modelListCases = [
	'openai' => ['endpoint' => '/v1/models', 'header' => 'Authorization', 'json' => ['data' => [['id' => 'gpt-alpha'], ['id' => 'text-embedding-model']]]],
	'anthropic' => ['endpoint' => '/v1/models?limit=1000', 'header' => 'x-api-key', 'json' => ['data' => [['id' => 'claude-alpha']]]],
	'gemini' => ['endpoint' => '/v1beta/models', 'header' => 'x-goog-api-key', 'json' => ['models' => [['name' => 'models/gemini-alpha', 'supportedGenerationMethods' => ['generateContent']], ['name' => 'models/embedding-only', 'supportedGenerationMethods' => ['embedContent']]]]],
	'openrouter' => ['endpoint' => '/v1/models', 'header' => 'Authorization', 'json' => ['data' => [['id' => 'vendor/model-alpha']]]],
	'ollama' => ['endpoint' => '/api/tags', 'header' => null, 'json' => ['models' => [['name' => 'llama-alpha:latest']]]],
];
foreach ($modelListCases as $provider => $case) {
	$test('model listing request and filtering: ' . $provider, static function () use ($assert, $provider, $case): void {
		$captured = null;
		$client = new ProviderClient(null, static function (string $url, array $headers, int $timeout, int $connectTimeout) use (&$captured, $case): array {
			$captured = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout, 'connect' => $connectTimeout];
			return ['status' => 200, 'body' => json_encode($case['json'], JSON_THROW_ON_ERROR)];
		});
		$models = $client->listModels([
			'provider' => $provider,
			'api_url' => Settings::DEFAULT_URLS[$provider],
			'api_key' => $provider === 'ollama' ? '' : 'model-list-key',
		]);
		$assert(str_ends_with($captured['url'], $case['endpoint']));
		$assert($captured['timeout'] === 30 && $captured['connect'] === 8);
		$assert(!str_contains($captured['url'], 'model-list-key'));
		if ($case['header'] !== null) $assert(isset($captured['headers'][$case['header']]));
		$assert(count($models) > 0);
		if ($provider === 'gemini') $assert($models === ['gemini-alpha']);
	});
}

$test('missing provider key prevents any provider request', static function () use ($throws): void {
	$called = false;
	$modelListCalled = false;
	$client = new ProviderClient(
		static function () use (&$called): array { $called = true; return ['status' => 200, 'body' => '{}']; },
		static function () use (&$modelListCalled): array { $modelListCalled = true; return ['status' => 200, 'body' => '{}']; }
	);
	$throws(static fn () => $client->summarize(['provider' => 'openai', 'model' => 'gpt-test', 'api_url' => Settings::DEFAULT_URLS['openai'], 'prompt' => '', 'api_key' => ''], 'article'));
	$throws(static fn () => $client->listModels(['provider' => 'openai', 'api_url' => Settings::DEFAULT_URLS['openai'], 'api_key' => '']));
	if ($called) throw new RuntimeException('provider was contacted without a key');
	if ($modelListCalled) throw new RuntimeException('model list endpoint was contacted without a key');
});

$test('provider error bodies are never returned to the caller', static function () use ($assert, $throws): void {
	$secret = 'do-not-expose';
	$client = new ProviderClient(static fn (): array => ['status' => 401, 'body' => $secret]);
	$error = $throws(static fn () => $client->summarize(['provider' => 'openai', 'model' => 'gpt-test', 'api_url' => Settings::DEFAULT_URLS['openai'], 'prompt' => '', 'api_key' => 'private-key'], 'article'));
	$assert(!str_contains($error->getMessage(), $secret) && !str_contains($error->getMessage(), 'private-key'));
});

$test('core extension AJAX actions reject missing auth, CSRF, POST, and invalid IDs', static function () use ($assert): void {
	$cases = [
		'unauthorized' => ['unauthorized', 403],
		'bad-csrf' => ['bad-csrf', 403],
		'wrong-method' => ['get', 405],
		'invalid-id' => ['invalid-id', 400],
		'missing-entry' => ['valid', 404],
		'model-wrong-method' => ['models-get', 405],
		'model-bad-csrf' => ['models-bad-csrf', 403],
		'model-missing-key' => ['models-no-key', 400],
	];
	foreach ($cases as $name => [$scenario, $expectedStatus]) {
		$command = [PHP_BINARY, __DIR__ . '/ajax-case.php', $scenario];
		$pipes = [];
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
		if (!is_resource($process)) throw new RuntimeException('could not start AJAX action case');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$assert($status === 0, $name . ' child failed: ' . $error);
		$parts = explode("\n__HTTP__", $output, 2);
		$data = json_decode($parts[0] ?? '', true);
		$assert(is_array($data) && isset($data['error']), $name . ' did not return a JSON error');
		$assert(isset($parts[1]) && (int)$parts[1] === $expectedStatus, $name . ' returned the wrong HTTP status');
	}
});

$test('publishable metadata and all pinned dependency licenses are present', static function () use ($assert): void {
	$root = dirname(__DIR__);
	$metadata = json_decode((string)file_get_contents($root . '/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
	$dependencies = json_decode((string)file_get_contents($root . '/vendor/dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
	$assert($metadata['entrypoint'] === 'FetchAndSummarize' && $metadata['type'] === 'user' && $metadata['license'] === 'AGPL-3.0-or-later');
	$extension = (string)file_get_contents($root . '/extension.php');
	$ajax = (string)file_get_contents($root . '/lib/AjaxActions.php');
	$assert(str_contains($extension, "'c' => 'extension', 'a' => 'configure'"));
	$assert(substr_count($extension, "], 'php')") >= 2, 'JavaScript endpoints must not HTML-escape ampersands');
	$assert(str_contains($extension, 'AjaxActions::dispatch($this, $ajaxAction)'));
	$assert(str_contains($extension, "'fas_action' => 'summarize'"));
	$assert(str_contains($extension, "'fas_action' => 'models'"));
	$assert(str_contains($ajax, "if (\$action === 'models')"));
	$licensePaths = [
		'fivefilters/readability.php' => 'vendor/fivefilters/readability.php/LICENSE',
		'masterminds/html5' => 'vendor/masterminds/html5/LICENSE.txt',
		'league/uri' => 'vendor/league/uri/LICENSE',
		'league/uri-interfaces' => 'vendor/league/uri-interfaces/LICENSE',
		'psr/http-factory' => 'vendor/psr/http-factory/LICENSE',
		'psr/http-message' => 'vendor/psr/http-message/LICENSE',
		'psr/log' => 'vendor/psr/log/LICENSE',
	];
	foreach ($licensePaths as $package => $path) {
		$assert(isset($dependencies['packages'][$package]), 'missing pinned package ' . $package);
		$assert(is_file($root . '/' . $path), 'missing bundled license for ' . $package);
	}
});

echo "\n{$passed} passed; {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
