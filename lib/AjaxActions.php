<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class AjaxActions {
	public static function dispatch(\FetchAndSummarizeExtension $extension, string $action): never {
		if (!\FreshRSS_Auth::hasAccess()) {
			self::respond(403, ['error' => 'Your FreshRSS session is not authorized for this action.']);
		}
		if (!\Minz_Request::isPost()) {
			self::respond(405, ['error' => 'Use the extension settings or an article action.']);
		}
		if (!\FreshRSS_Auth::isCsrfOk()) {
			self::respond(403, ['error' => 'The security token expired. Reload FreshRSS and try again.']);
		}
		if ($action === 'models') {
			self::models($extension);
		}
		if ($action === 'summarize') {
			self::summarize($extension);
		}
		self::respond(400, ['error' => 'The requested extension action is invalid.']);
	}

	private static function models(\FetchAndSummarizeExtension $extension): never {
		try {
			$provider = \Minz_Request::paramString('provider', plaintext: true);
			$submittedKey = \Minz_Request::paramString('api_key', plaintext: true);
			$lookup = Settings::validateModelLookup(
				$provider,
				trim(\Minz_Request::paramString('api_url', plaintext: true)),
				$submittedKey
			);
			$savedProvider = $extension->getSetting('provider', 'openai');
			$lookup['api_key'] = Settings::modelLookupKey(
				$lookup['provider'],
				$lookup['api_url'],
				$lookup['api_key'],
				$savedProvider,
				$extension->getSetting('api_url', Settings::DEFAULT_URLS[$savedProvider] ?? ''),
				$extension->getSetting('api_key')
			);
			if ($provider !== 'ollama' && trim($lookup['api_key']) === '') {
				self::respond(400, ['error' => 'Enter an API key before loading provider models.']);
			}
			self::respond(200, ['models' => (new ProviderClient())->listModels($lookup)]);
		} catch (\InvalidArgumentException $e) {
			self::respond(400, ['error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			\Minz_Log::warning('FetchAndSummarize model lookup failed (' . get_class($e) . ').');
			$message = $e instanceof \RuntimeException ? $e->getMessage() : 'The provider models could not be loaded. Check the provider settings and try again.';
			self::respond(422, ['error' => $message]);
		}
	}

	private static function summarize(\FetchAndSummarizeExtension $extension): never {
		$id = \Minz_Request::paramString('id', plaintext: true);
		if (!EntryId::isValid($id)) {
			self::respond(400, ['error' => 'The article identifier is invalid.']);
		}
		$entry = \FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null || trim($entry->link()) === '') {
			self::respond(404, ['error' => 'This article is no longer available in your FreshRSS account.']);
		}

		try {
			$provider = $extension->getSetting('provider', 'openai');
			$config = Settings::validate(
				$provider,
				$extension->getSetting('model'),
				$extension->getSetting('api_url', Settings::DEFAULT_URLS[$provider] ?? ''),
				$extension->getSetting('prompt')
			);
			$config['api_key'] = $extension->getSetting('api_key');
			if ($provider !== 'ollama' && trim($config['api_key']) === '') {
				self::respond(400, ['error' => 'Save an API key in the extension settings before requesting a summary.']);
			}
			$extractor = new ArticleExtractor();
			$feedExcerpt = false;
			try {
				$page = (new ArticleFetcher())->fetch($entry->link());
				$text = $extractor->extract($page['html'], $page['url']);
			} catch (\RuntimeException $fetchError) {
				try {
					$text = $extractor->extractFeedContent((string)$entry->content(false));
					$feedExcerpt = true;
				} catch (\RuntimeException) {
					throw $fetchError;
				}
			}
			self::respond(200, [
				'summary' => (new ProviderClient())->summarize($config, $text, $feedExcerpt),
				'source' => $feedExcerpt ? 'feed' : 'article',
			]);
		} catch (\InvalidArgumentException $e) {
			self::respond(400, ['error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			// Do not log exception messages: transport libraries may include URLs or other sensitive data.
			\Minz_Log::warning('FetchAndSummarize request failed (' . get_class($e) . ').');
			$message = $e instanceof \RuntimeException ? $e->getMessage() : 'The article could not be summarized. Check the extension settings and try again.';
			self::respond(422, ['error' => $message]);
		}
	}

	/** @param array<string,mixed> $payload */
	private static function respond(int $status, array $payload): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
		exit;
	}
}
