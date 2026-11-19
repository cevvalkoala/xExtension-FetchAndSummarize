<?php
declare(strict_types=1);

class Minz_Extension {
	public function handleConfigureAction(): void {}
	public function getName(): string { return 'FetchAndSummarize'; }
	protected function saveFile(string $filename, string $content): void { $GLOBALS['fas_files'][$GLOBALS['fas_user']][$filename] = $content; }
	protected function removeFile(string $filename): void {
		$GLOBALS['fas_remove_calls']++;
		if ($GLOBALS['fas_remove_failure'] === 'throw') throw new RuntimeException('remove failed');
		if ($GLOBALS['fas_remove_failure'] !== 'noop') unset($GLOBALS['fas_files'][$GLOBALS['fas_user']][$filename]);
	}
	public function hasFile(string $filename): bool { return isset($GLOBALS['fas_files'][$GLOBALS['fas_user']][$filename]); }
	public function getFile(string $filename): ?string { return $GLOBALS['fas_files'][$GLOBALS['fas_user']][$filename] ?? null; }
}
final class FreshRSS_TestUserConfiguration {
	public array $extensions = [
		'FetchAndSummarize' => [
			'provider' => 'openai',
			'model' => 'old-model',
			'api_url' => 'https://api.openai.com/v1',
			'prompt' => 'old prompt',
			'api_key' => 'saved-secret',
			'other' => 'preserve-me',
		],
	];
	public bool $saved = false;
	public array $savedExtensions = [];
	public function _attribute(string $name, mixed $value): void { $this->$name = $value; }
	public function save(): void { $this->saved = true; $this->savedExtensions = $this->extensions; }
}
final class FreshRSS_Context {
	public static function userConf(): FreshRSS_TestUserConfiguration { return $GLOBALS['fas_user_conf']; }
}
final class FreshRSS_Auth {
	public static function hasAccess(): bool { return $GLOBALS['fas_authorized'] ?? true; }
	public static function isCsrfOk(): bool { return $GLOBALS['fas_csrf_ok'] ?? true; }
}
final class Minz_Request {
	public static function isPost(): bool { return $GLOBALS['fas_post'] ?? true; }
	public static function paramString(string $name, bool $plaintext = false): string { return is_string($_POST[$name] ?? null) ? $_POST[$name] : ''; }
	public static function paramBoolean(string $name): bool { return ($_POST[$name] ?? '') === '1'; }
	public static function setBadNotification(string $message): void { $GLOBALS['fas_notification'] = ['bad', $message]; }
	public static function setGoodNotification(string $message): void { $GLOBALS['fas_notification'] = ['good', $message]; }
}
final class Minz_Error { public static function error(int $status): never { throw new RuntimeException('HTTP ' . $status); } }
function _t(string $key): string { return $key; }

$scenario = $argv[1] ?? 'save';
$GLOBALS['fas_user_conf'] = new FreshRSS_TestUserConfiguration();
$GLOBALS['fas_notification'] = null;
$GLOBALS['fas_user'] = 'alice';
$GLOBALS['fas_files'] = [];
$GLOBALS['fas_remove_calls'] = 0;
$GLOBALS['fas_remove_failure'] = '';
$_POST = [
	'_csrf' => 'test-token',
	'provider' => 'openai',
	'model' => 'new-model',
	'api_url' => 'https://api.openai.com/v1',
	'prompt' => 'new prompt',
	'api_key' => '',
];
if ($scenario === 'change-provider') {
	$_POST['provider'] = 'gemini';
	$_POST['model'] = 'gemini-2.5-flash';
	$_POST['api_url'] = 'https://generativelanguage.googleapis.com';
} elseif ($scenario === 'clear-key') {
	$_POST['clear_api_key'] = '1';
} elseif ($scenario === 'replace-key') {
	$_POST['api_key'] = 'replacement-secret';
} elseif ($scenario === 'invalid') {
	$_POST['model'] = 'not a valid model';
}

require_once dirname(__DIR__) . '/extension.php';
$extension = new FetchAndSummarizeExtension();
if ($scenario === 'test-cache-clear') {
	$check = static function (bool $condition, string $message): void {
		if (!$condition) throw new RuntimeException($message);
	};
	$settings = ['provider' => 'openai', 'model' => 'model', 'api_url' => 'https://api.openai.com/v1', 'prompt' => ''];
	$originalSettings = $GLOBALS['fas_user_conf']->extensions;
	$extension->cacheSummary('123', 'https://example.com/article', 'feed', $settings, 'cached', 'article');
	$check($extension->getCachedSummary('123', 'https://example.com/article', 'feed', $settings) === ['summary' => 'cached', 'source' => 'article'], 'fixture cache not populated');
	$GLOBALS['fas_files']['alice']['other.json'] = 'keep';
	$GLOBALS['fas_files']['bob']['summaries.json'] = 'other user';
	$_POST['fas_action'] = 'clear_summary_cache';
	$extension->handleConfigureAction();
	$check($extension->getCachedSummary('123', 'https://example.com/article', 'feed', $settings) === null, 'cache not cleared');
	$check($GLOBALS['fas_files']['alice']['other.json'] === 'keep' && $GLOBALS['fas_files']['bob']['summaries.json'] === 'other user', 'unrelated files changed');
	$check(!$GLOBALS['fas_user_conf']->saved && $GLOBALS['fas_user_conf']->extensions === $originalSettings, 'settings changed');
	$check($GLOBALS['fas_notification'] === ['good', 'ext.fetch_and_summarize.configure.cache_cleared'], 'missing success notification');
	$GLOBALS['fas_notification'] = null;
	$extension->handleConfigureAction();
	$check($GLOBALS['fas_notification'] === ['good', 'ext.fetch_and_summarize.configure.cache_cleared'], 'missing idempotent success');
	foreach (['noop', 'throw'] as $failure) {
		$GLOBALS['fas_files']['alice']['summaries.json'] = 'keep on failure';
		$GLOBALS['fas_remove_failure'] = $failure;
		$extension->handleConfigureAction();
		$check($GLOBALS['fas_notification'] === ['bad', 'ext.fetch_and_summarize.configure.cache_clear_failed'], 'missing failure notification: ' . $failure);
		$check($GLOBALS['fas_files']['alice']['summaries.json'] === 'keep on failure', 'file changed on failure');
	}
	$GLOBALS['fas_remove_failure'] = '';
	foreach (['fas_authorized', 'fas_csrf_ok', 'fas_post'] as $blocker) {
		$GLOBALS[$blocker] = false;
		$calls = $GLOBALS['fas_remove_calls'];
		try {
			$extension->handleConfigureAction();
			$check($blocker === 'fas_post', 'missing 403: ' . $blocker);
		} catch (RuntimeException $e) {
			$check($blocker !== 'fas_post' && $e->getMessage() === 'HTTP 403', 'unexpected error: ' . $blocker);
		}
		$check($GLOBALS['fas_remove_calls'] === $calls && $GLOBALS['fas_files']['alice']['summaries.json'] === 'keep on failure', 'blocked request removed cache: ' . $blocker);
		$GLOBALS[$blocker] = true;
	}
	$check(!$GLOBALS['fas_user_conf']->saved && $GLOBALS['fas_user_conf']->extensions === $originalSettings, 'settings changed during failure or blocked request');
	echo "cache clear fixture passed\n";
	exit(0);
}
$extension->handleConfigureAction();
echo json_encode([
	'saved' => $GLOBALS['fas_user_conf']->saved,
	'extensions' => $GLOBALS['fas_user_conf']->savedExtensions,
	'notification' => $GLOBALS['fas_notification'],
], JSON_THROW_ON_ERROR);
