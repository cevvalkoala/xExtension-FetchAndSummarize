<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

final class FetchAndSummarizeExtension extends Minz_Extension {
	private const SUMMARY_CACHE_FILE = 'summaries.json';
	private const SUMMARY_CACHE_MAX_ENTRIES = 300;
	private const SUMMARY_CACHE_MAX_BYTES = 3_000_000;

	public function init(): void {
		parent::init();
		$this->registerTranslates();
		$this->registerHook('js_vars', [$this, 'jsVars']);
		Minz_View::appendStyle($this->getFileUrl('fetchandsummarize.css'));
		Minz_View::appendScript($this->getFileUrl('fetchandsummarize.js'), cond: false, defer: true, async: false);
		Minz_View::appendScript($this->getFileUrl('fetchandsummarize-configure.js'), cond: false, defer: true, async: false);
	}

	/** @param array<string,mixed> $vars @return array<string,mixed> */
	public function jsVars(array $vars): array {
		$vars['fetch_and_summarize'] = [
			'url' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName(), 'fas_action' => 'summarize']], 'php'),
			'cachedUrl' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName(), 'fas_action' => 'cached_summary']], 'php'),
			'modelsUrl' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName(), 'fas_action' => 'models']], 'php'),
			'unreadUrl' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName(), 'fas_action' => 'unread_ids']], 'php'),
			'i18n' => [
				'button' => _t('ext.fetch_and_summarize.button'),
				'loading' => _t('ext.fetch_and_summarize.loading'),
				'error' => _t('ext.fetch_and_summarize.error'),
				'summaryFeed' => _t('ext.fetch_and_summarize.summary_source_feed'),
				'summaryArticle' => _t('ext.fetch_and_summarize.summary_source_article'),
				'loadModels' => _t('ext.fetch_and_summarize.configure.load_models'),
				'loadingModels' => _t('ext.fetch_and_summarize.configure.loading_models'),
				'modelsLoaded' => _t('ext.fetch_and_summarize.configure.models_loaded'),
				'noModels' => _t('ext.fetch_and_summarize.configure.no_models'),
				'modelsError' => _t('ext.fetch_and_summarize.configure.models_error'),
				'unreadButton' => _t('ext.fetch_and_summarize.unread.button'),
				'unreadDescription' => _t('ext.fetch_and_summarize.unread.description'),
				'unreadProgress' => _t('ext.fetch_and_summarize.unread.progress'),
				'unreadComplete' => _t('ext.fetch_and_summarize.unread.complete'),
				'unreadPartial' => _t('ext.fetch_and_summarize.unread.partial'),
				'unreadEmpty' => _t('ext.fetch_and_summarize.unread.empty'),
				'unreadError' => _t('ext.fetch_and_summarize.unread.error'),
			],
		];
		return $vars;
	}

	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
		if (!Minz_Request::isPost()) {
			return;
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			Minz_Error::error(403);
		}
		$ajaxAction = Minz_Request::paramString('fas_action', plaintext: true);
		if (in_array($ajaxAction, ['models', 'summarize', 'unread_ids', 'cached_summary'], true)) {
			FetchAndSummarize\AjaxActions::dispatch($this, $ajaxAction);
			return;
		}

		$provider = Minz_Request::paramString('provider', plaintext: true);
		$model = trim(Minz_Request::paramString('model', plaintext: true));
		$apiUrl = trim(Minz_Request::paramString('api_url', plaintext: true));
		$prompt = is_string($_POST['prompt'] ?? null) ? $_POST['prompt'] : '';
		$key = Minz_Request::paramString('api_key', plaintext: true);
		$clearKey = Minz_Request::paramBoolean('clear_api_key');

		try {
			$config = FetchAndSummarize\Settings::validate($provider, $model, $apiUrl, $prompt);
			$oldProvider = $this->getSetting('provider', 'openai');
			$oldApiUrl = $this->getSetting('api_url', FetchAndSummarize\Settings::DEFAULT_URLS[$oldProvider] ?? '');
			$updatedKey = FetchAndSummarize\Settings::configurationApiKey(
				$oldProvider,
				$oldApiUrl,
				$provider,
				$config['api_url'],
				$this->getSetting('api_key'),
				$key,
				$clearKey
			);
		} catch (InvalidArgumentException $e) {
			Minz_Request::setBadNotification($e->getMessage());
			return;
		}

		$userConfiguration = FreshRSS_Context::userConf();
		$extensions = $userConfiguration->extensions;
		$current = $extensions[$this->getName()] ?? [];
		if (!is_array($current)) {
			$current = [];
		}
		$extensions[$this->getName()] = array_merge($current, $config, ['api_key' => $updatedKey]);
		$userConfiguration->_attribute('extensions', $extensions);
		$userConfiguration->save();
		Minz_Request::setGoodNotification(_t('ext.fetch_and_summarize.saved'));
	}

	public function getSetting(string $key, string $default = ''): string {
		$extensions = FreshRSS_Context::userConf()->extensions;
		$configuration = $extensions[$this->getName()] ?? [];
		$value = is_array($configuration) ? ($configuration[$key] ?? null) : null;
		return is_string($value) ? $value : $default;
	}

	/** @param array{provider:string,model:string,api_url:string,prompt:string} $settings @return array{summary:string,source:string}|null */
	public function getCachedSummary(string $entryId, string $link, string $feedContent, array $settings): ?array {
		if (!FetchAndSummarize\EntryId::isValid($entryId)) {
			return null;
		}
		$signature = self::summaryCacheSignature($link, $feedContent, $settings);
		foreach ($this->readSummaryCache() as $cache) {
			if ($cache['id'] === $entryId && $cache['signature'] === $signature) {
				return ['summary' => $cache['summary'], 'source' => $cache['source']];
			}
		}
		return null;
	}

	/** @param array{provider:string,model:string,api_url:string,prompt:string} $settings */
	public function cacheSummary(string $entryId, string $link, string $feedContent, array $settings, string $summary, string $source): void {
		if (!FetchAndSummarize\EntryId::isValid($entryId) || $summary === '' || strlen($summary) > FetchAndSummarize\ProviderClient::MAX_SUMMARY_BYTES || !in_array($source, ['article', 'feed'], true)) {
			return;
		}
		$entries = array_values(array_filter($this->readSummaryCache(), static fn (array $item): bool => $item['id'] !== $entryId));
		$entries[] = [
			'id' => $entryId,
			'signature' => self::summaryCacheSignature($link, $feedContent, $settings),
			'summary' => $summary,
			'source' => $source,
		];
		$encoded = self::encodeSummaryCache($entries);
		while ($entries !== [] && (count($entries) > self::SUMMARY_CACHE_MAX_ENTRIES || strlen($encoded) > self::SUMMARY_CACHE_MAX_BYTES)) {
			array_shift($entries);
			$encoded = self::encodeSummaryCache($entries);
		}
		if (strlen($encoded) <= self::SUMMARY_CACHE_MAX_BYTES) {
			$this->saveFile(self::SUMMARY_CACHE_FILE, $encoded);
		}
	}

	/** @return list<array{id:string,signature:string,summary:string,source:string}> */
	private function readSummaryCache(): array {
		$stored = $this->getFile(self::SUMMARY_CACHE_FILE);
		if (!is_string($stored) || strlen($stored) > self::SUMMARY_CACHE_MAX_BYTES) {
			return [];
		}
		$decoded = json_decode($stored, true);
		$entries = is_array($decoded) ? ($decoded['entries'] ?? null) : null;
		if (!is_array($entries)) {
			return [];
		}
		$valid = [];
		foreach ($entries as $entry) {
			if (!is_array($entry) || !is_string($entry['id'] ?? null) || !FetchAndSummarize\EntryId::isValid($entry['id']) || !is_string($entry['signature'] ?? null) || !is_string($entry['summary'] ?? null) || strlen($entry['summary']) > FetchAndSummarize\ProviderClient::MAX_SUMMARY_BYTES || !in_array($entry['source'] ?? null, ['article', 'feed'], true)) {
				continue;
			}
			$valid[] = ['id' => $entry['id'], 'signature' => $entry['signature'], 'summary' => $entry['summary'], 'source' => $entry['source']];
		}
		return $valid;
	}

	/** @param list<array{id:string,signature:string,summary:string,source:string}> $entries */
	private static function encodeSummaryCache(array $entries): string {
		return json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}


	/** @param array{provider:string,model:string,api_url:string,prompt:string} $settings */
	private static function summaryCacheSignature(string $link, string $feedContent, array $settings): string {
		return hash('sha256', serialize([
			$link,
			hash('sha256', $feedContent),
			$settings['provider'],
			$settings['model'],
			$settings['api_url'],
			FetchAndSummarize\Settings::prompt($settings['prompt']),
		]));
	}
}
