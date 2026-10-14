<?php
declare(strict_types=1);

class Minz_Extension {
	public function handleConfigureAction(): void {}
	public function getName(): string { return 'FetchAndSummarize'; }
}
final class Minz_Dispatcher {
	public static array $registrations = [];
	public static function registerController(string $name, string $path): void { self::$registrations[$name] = $path; }
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
	public static function hasAccess(): bool { return true; }
	public static function isCsrfOk(): bool { return true; }
}
final class Minz_Request {
	public static function isPost(): bool { return true; }
	public static function paramString(string $name, bool $plaintext = false): string { return is_string($_POST[$name] ?? null) ? $_POST[$name] : ''; }
	public static function paramBoolean(string $name): bool { return ($_POST[$name] ?? '') === '1'; }
	public static function setBadNotification(string $message): void { $GLOBALS['fas_notification'] = ['bad', $message]; }
	public static function setGoodNotification(string $message): void { $GLOBALS['fas_notification'] = ['good', $message]; }
}
final class Minz_Error { public static function error(int $status): never { exit($status); } }
function _t(string $key): string { return $key; }

$scenario = $argv[1] ?? 'save';
$GLOBALS['fas_user_conf'] = new FreshRSS_TestUserConfiguration();
$GLOBALS['fas_notification'] = null;
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
$extension->handleConfigureAction();
echo json_encode([
	'saved' => $GLOBALS['fas_user_conf']->saved,
	'extensions' => $GLOBALS['fas_user_conf']->savedExtensions,
	'notification' => $GLOBALS['fas_notification'],
	'controllers' => Minz_Dispatcher::$registrations,
], JSON_THROW_ON_ERROR);
