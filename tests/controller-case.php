<?php
declare(strict_types=1);

class Minz_ActionController {}
final class Minz_ExtensionManager {
	public static function findExtension(string $name): ?object { return null; }
}
final class Minz_Log {
	public static function warning(string $message): void {}
}
final class FreshRSS_Auth {
	public static function hasAccess(): bool { return ($GLOBALS['fas_case'] ?? '') !== 'unauthorized'; }
	public static function isCsrfOk(): bool { return ($GLOBALS['fas_case'] ?? '') !== 'bad-csrf'; }
}
final class Minz_Request {
	public static function isPost(): bool { return ($GLOBALS['fas_case'] ?? '') !== 'get'; }
	public static function paramString(string $name, bool $plaintext = false): string {
		return $name === 'id' ? (($GLOBALS['fas_case'] ?? '') === 'invalid-id' ? '1/not-an-id' : '12345678901234567890') : '';
	}
}
final class FreshRSS_Factory {
	public static function createEntryDao(): object {
		return new class {
			public function searchById(string $id): ?object { return null; }
		};
	}
}

require_once dirname(__DIR__) . '/Controllers/FetchAndSummarizeController.php';
$GLOBALS['fas_case'] = $argv[1] ?? '';
register_shutdown_function(static function (): void { echo "\n__HTTP__" . http_response_code(); });
$controller = new FreshExtension_FetchAndSummarize_Controller();
$controller->firstAction();
$controller->summarizeAction();
