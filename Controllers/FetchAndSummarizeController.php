<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

final class FreshExtension_fetchAndSummarize_Controller extends Minz_ActionController {
	private ?FetchAndSummarizeExtension $extension = null;

	public function init(): void {
		$extension = Minz_ExtensionManager::findExtension('FetchAndSummarize');
		$this->extension = $extension instanceof FetchAndSummarizeExtension ? $extension : null;
	}

	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			$this->respond(403, ['error' => 'Your FreshRSS session is not authorized for this action.']);
		}
	}

	public function summarizeAction(): void {
		if (!Minz_Request::isPost()) {
			$this->respond(405, ['error' => 'Use the Fetch and Summarize button from an article.']);
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			$this->respond(403, ['error' => 'The security token expired. Reload FreshRSS and try again.']);
		}
		$id = Minz_Request::paramString('id', plaintext: true);
		if (!FetchAndSummarize\EntryId::isValid($id)) {
			$this->respond(400, ['error' => 'The article identifier is invalid.']);
		}
		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null || trim($entry->link()) === '') {
			$this->respond(404, ['error' => 'This article is no longer available in your FreshRSS account.']);
		}
		if ($this->extension === null) {
			$this->respond(500, ['error' => 'Fetch and Summarize is not configured for this user.']);
		}

		try {
			$provider = $this->extension->getSetting('provider', 'openai');
			$config = FetchAndSummarize\Settings::validate(
				$provider,
				$this->extension->getSetting('model'),
				$this->extension->getSetting('api_url', FetchAndSummarize\Settings::DEFAULT_URLS[$provider] ?? ''),
				$this->extension->getSetting('prompt')
			);
			$config['api_key'] = $this->extension->getSetting('api_key');
			if ($provider !== 'ollama' && trim($config['api_key']) === '') {
				$this->respond(400, ['error' => 'Save an API key in the extension settings before requesting a summary.']);
			}
			$page = (new FetchAndSummarize\ArticleFetcher())->fetch($entry->link());
			$text = (new FetchAndSummarize\ArticleExtractor())->extract($page['html'], $page['url']);
			$summary = (new FetchAndSummarize\ProviderClient())->summarize($config, $text);
			$this->respond(200, ['summary' => $summary]);
		} catch (\InvalidArgumentException $e) {
			$this->respond(400, ['error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			// Do not log exception messages: transport libraries may include URLs or other sensitive data.
			Minz_Log::warning('FetchAndSummarize request failed (' . get_class($e) . ').');
			$message = $e instanceof \RuntimeException ? $e->getMessage() : 'The article could not be summarized. Check the extension settings and try again.';
			$this->respond(422, ['error' => $message]);
		}
	}

	public function modelsAction(): void {
		if (!Minz_Request::isPost()) {
			$this->respond(405, ['error' => 'Use the Load models button from the extension settings.']);
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			$this->respond(403, ['error' => 'The security token expired. Reload FreshRSS and try again.']);
		}
		if ($this->extension === null) {
			$this->respond(500, ['error' => 'Fetch and Summarize is not configured for this user.']);
		}

		try {
			$provider = Minz_Request::paramString('provider', plaintext: true);
			$submittedKey = Minz_Request::paramString('api_key', plaintext: true);
			$lookup = FetchAndSummarize\Settings::validateModelLookup(
				$provider,
				trim(Minz_Request::paramString('api_url', plaintext: true)),
				$submittedKey
			);
			$savedProvider = $this->extension->getSetting('provider', 'openai');
			$lookup['api_key'] = FetchAndSummarize\Settings::modelLookupKey(
				$lookup['provider'],
				$lookup['api_url'],
				$lookup['api_key'],
				$savedProvider,
				$this->extension->getSetting('api_url', FetchAndSummarize\Settings::DEFAULT_URLS[$savedProvider] ?? ''),
				$this->extension->getSetting('api_key')
			);
			if ($provider !== 'ollama' && trim($lookup['api_key']) === '') {
				$this->respond(400, ['error' => 'Enter an API key before loading provider models.']);
			}
			$models = (new FetchAndSummarize\ProviderClient())->listModels($lookup);
			$this->respond(200, ['models' => $models]);
		} catch (\InvalidArgumentException $e) {
			$this->respond(400, ['error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			Minz_Log::warning('FetchAndSummarize model lookup failed (' . get_class($e) . ').');
			$message = $e instanceof \RuntimeException ? $e->getMessage() : 'The provider models could not be loaded. Check the provider settings and try again.';
			$this->respond(422, ['error' => $message]);
		}
	}

	/** @param array<string,mixed> $payload */
	private function respond(int $status, array $payload): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
		exit;
	}
}
