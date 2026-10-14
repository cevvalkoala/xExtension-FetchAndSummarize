<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

final class FetchAndSummarizeExtension extends Minz_Extension {
	public function init(): void {
		parent::init();
		$this->registerTranslates();
		$this->registerController('FetchAndSummarize');
		$this->registerHook('js_vars', [$this, 'jsVars']);
		Minz_View::appendStyle($this->getFileUrl('fetchandsummarize.css'));
		Minz_View::appendScript($this->getFileUrl('fetchandsummarize.js'), cond: false, defer: true, async: false);
	}

	/** @param array<string,mixed> $vars @return array<string,mixed> */
	public function jsVars(array $vars): array {
		$vars['fetch_and_summarize'] = [
			'url' => Minz_Url::display(['c' => 'FetchAndSummarize', 'a' => 'summarize']),
			'i18n' => [
				'button' => _t('ext.fetch_and_summarize.button'),
				'loading' => _t('ext.fetch_and_summarize.loading'),
				'error' => _t('ext.fetch_and_summarize.error'),
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

		$provider = Minz_Request::paramString('provider', plaintext: true);
		$model = trim(Minz_Request::paramString('model', plaintext: true));
		$apiUrl = trim(Minz_Request::paramString('api_url', plaintext: true));
		$prompt = is_string($_POST['prompt'] ?? null) ? $_POST['prompt'] : '';
		$key = Minz_Request::paramString('api_key', plaintext: true);
		$clearKey = Minz_Request::paramBoolean('clear_api_key');

		try {
			$config = FetchAndSummarize\Settings::validate($provider, $model, $apiUrl, $prompt);
			$updatedKey = FetchAndSummarize\Settings::updateApiKey($this->getSetting('api_key'), $key, $clearKey);
		} catch (InvalidArgumentException $e) {
			Minz_Request::setBadNotification($e->getMessage());
			return;
		}

		foreach ($config as $name => $value) {
			$this->setUserConfigurationValue($name, $value);
		}
		$this->setUserConfigurationValue('api_key', $updatedKey);
		Minz_Request::setGoodNotification(_t('ext.fetch_and_summarize.saved'));
	}

	public function getSetting(string $key, string $default = ''): string {
		return $this->getUserConfigurationString($key) ?? $default;
	}
}
