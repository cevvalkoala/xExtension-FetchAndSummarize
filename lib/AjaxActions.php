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
		if ($action === 'unread_ids') {
			self::unreadIds();
		}
		if ($action === 'cached_summary') {
			self::cachedSummary($extension);
		}
		self::respond(400, ['error' => 'The requested extension action is invalid.']);
	}

	private static function unreadIds(): never {
		$feedId = filter_var(
			\Minz_Request::paramString('feed_id', plaintext: true),
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1]]
		);
		if ($feedId === false) {
			self::respond(400, ['error' => 'The feed identifier is invalid.']);
		}

		try {
			$dao = \FreshRSS_Factory::createEntryDao();
			$ids = [];
			$offset = 0;
			do {
				$page = $dao->listIdsWhere('f', $feedId, \FreshRSS_Entry::STATE_NOT_READ, null, '0', '0', 'ASC', '0', [], 1000, $offset);
				if ($page === null) {
					break;
				}
				if (!is_array($page)) {
					self::respond(500, ['error' => 'Unread entries for this feed could not be loaded.']);
				}
				foreach ($page as $id) {
					$id = (string)$id;
					if (EntryId::isValid($id)) {
						$ids[] = $id;
					}
				}
				$offset += count($page);
			} while (count($page) === 1000);
			self::respond(200, ['ids' => $ids]);
		} catch (\Throwable $e) {
			\Minz_Log::warning('FetchAndSummarize unread feed lookup failed (' . get_class($e) . ').');
			self::respond(500, ['error' => 'Unread entries for this feed could not be loaded.']);
		}
	}

	private static function cachedSummary(\FetchAndSummarizeExtension $extension): never {
		$id = \Minz_Request::paramString('id', plaintext: true);
		if (!EntryId::isValid($id)) {
			self::respond(400, ['error' => 'The article identifier is invalid.']);
		}
		$entry = \FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null || trim($entry->link()) === '') {
			self::respond(200, ['cached' => false]);
		}
		try {
			$provider = $extension->getSetting('provider', 'openai');
			$settings = Settings::validate(
				$provider,
				$extension->getSetting('model'),
				$extension->getSetting('api_url', Settings::DEFAULT_URLS[$provider] ?? ''),
				$extension->getSetting('prompt')
			);
			$cached = $extension->getCachedSummary($id, $entry->link(), (string)$entry->content(false), $settings);
			self::respond(200, $cached === null ? ['cached' => false] : $cached + ['cached' => true]);
		} catch (\Throwable $e) {
			\Minz_Log::warning('FetchAndSummarize automatic cache lookup failed (' . get_class($e) . ').');
			self::respond(200, ['cached' => false]);
		}
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
			$feedContent = (string)$entry->content(false);
			try {
				$cached = $extension->getCachedSummary($id, $entry->link(), $feedContent, $config);
			} catch (\Throwable $e) {
				$cached = null;
				\Minz_Log::warning('FetchAndSummarize summary cache read failed (' . get_class($e) . ').');
			}
			if ($cached !== null) {
				self::respond(200, $cached + ['cached' => true]);
			}
			$config['api_key'] = $extension->getSetting('api_key');
			if ($provider !== 'ollama' && trim($config['api_key']) === '') {
				self::respond(400, ['error' => 'Save an API key in the extension settings before requesting a summary.']);
			}
			$extractor = new ArticleExtractor();
			$feedExcerpt = false;
			try {
				$page = (new ArticleFetcher())->fetch($entry->link());
				$text = $extractor->extract($page['html']);
			} catch (\RuntimeException $fetchError) {
				try {
					$text = $extractor->extractFeedContent($feedContent);
					$feedExcerpt = true;
				} catch (\RuntimeException) {
					throw $fetchError;
				}
			}
			$summary = (new ProviderClient())->summarize($config, $text, $feedExcerpt);
			$source = $feedExcerpt ? 'feed' : 'article';
			try {
				$extension->cacheSummary($id, $entry->link(), $feedContent, $config, $summary, $source);
			} catch (\Throwable $e) {
				\Minz_Log::warning('FetchAndSummarize summary cache write failed (' . get_class($e) . ').');
			}
			self::respond(200, ['summary' => $summary, 'source' => $source, 'cached' => false]);
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
