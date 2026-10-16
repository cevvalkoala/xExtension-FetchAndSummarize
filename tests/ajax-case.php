<?php
declare(strict_types=1);

final class FreshRSS_Auth {
	public static function hasAccess(): bool { return ($GLOBALS['fas_case'] ?? '') !== 'unauthorized'; }
	public static function isCsrfOk(): bool { return !in_array($GLOBALS['fas_case'] ?? '', ['bad-csrf', 'models-bad-csrf', 'unread-bad-csrf'], true); }
}
final class Minz_Request {
	public static function isPost(): bool { return !in_array($GLOBALS['fas_case'] ?? '', ['get', 'models-get', 'unread-get'], true); }
	public static function paramString(string $name, bool $plaintext = false): string {
		if ($name === 'id') return ($GLOBALS['fas_case'] ?? '') === 'invalid-id' ? '1/not-an-id' : '12345678901234567890';
		if ($name === 'feed_id') return ($GLOBALS['fas_case'] ?? '') === 'unread-invalid-feed' ? 'nope' : '77';
		if ($name === 'provider') return 'openai';
		if ($name === 'api_url') return 'https://api.openai.com/v1';
		return '';
	}
}
final class FreshRSS_Factory {
	public static function createEntryDao(): object {
		return new class {
			public function searchById(string $id): ?object { return null; }
			public function listIdsWhere(...$args): ?array { $GLOBALS['fas_dao_args'] = $args; return ['987', '654']; }
		};
	}
}
final class FreshRSS_Entry { public const STATE_NOT_READ = 2; }
final class Minz_Log { public static function warning(string $message): void {} }
final class FetchAndSummarizeExtension {
	public function getSetting(string $key, string $default = ''): string {
		return match ($key) {
			'provider' => 'openai',
			'api_url' => 'https://api.openai.com/v1',
			default => $default,
		};
	}
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';
$GLOBALS['fas_case'] = $argv[1] ?? '';
register_shutdown_function(static function (): void { echo "\n__HTTP__" . http_response_code(); });
$action = str_starts_with($GLOBALS['fas_case'], 'models-') ? 'models' : (str_starts_with($GLOBALS['fas_case'], 'unread-') ? 'unread_ids' : 'summarize');
FetchAndSummarize\AjaxActions::dispatch(new FetchAndSummarizeExtension(), $action);
