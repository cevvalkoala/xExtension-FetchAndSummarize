<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

final class FetchAndSummarizeExtension extends Minz_Extension {
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
			'modelsUrl' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName(), 'fas_action' => 'models']], 'php'),
			'i18n' => [
				'button' => _t('ext.fetch_and_summarize.button'),
				'loading' => _t('ext.fetch_and_summarize.loading'),
				'error' => _t('ext.fetch_and_summarize.error'),
				'loadModels' => _t('ext.fetch_and_summarize.configure.load_models'),
				'loadingModels' => _t('ext.fetch_and_summarize.configure.loading_models'),
				'modelsLoaded' => _t('ext.fetch_and_summarize.configure.models_loaded'),
				'noModels' => _t('ext.fetch_and_summarize.configure.no_models'),
				'modelsError' => _t('ext.fetch_and_summarize.configure.models_error'),
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
		if (in_array($ajaxAction, ['models', 'summarize'], true)) {
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
}
