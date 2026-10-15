<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class Settings {
	public const DEFAULT_PROMPT = 'Fetch the article from the link. Analyze the article page and disregard unrelated things from the source. If the content is shorter than 350 words, tell me and stop there. If otherwise, give a 200-word summary for this article in FreshRSS interface language.';

	/** @var array<string,string> */
	public const DEFAULT_URLS = [
		'openai' => 'https://api.openai.com/v1',
		'anthropic' => 'https://api.anthropic.com',
		'gemini' => 'https://generativelanguage.googleapis.com',
		'openrouter' => 'https://openrouter.ai/api/v1',
		'ollama' => 'http://127.0.0.1:11435',
	];

	/** @return array{provider:string,model:string,api_url:string,prompt:string} */
	public static function validate(string $provider, string $model, string $apiUrl, string $prompt): array {
		if (!isset(self::DEFAULT_URLS[$provider])) {
			throw new \InvalidArgumentException('Select a supported provider.');
		}
		if (strlen($model) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $model)) {
			throw new \InvalidArgumentException('Enter a valid model name (up to 128 characters).');
		}
		if (strlen($prompt) > 4000 || preg_match('//u', $prompt) !== 1) {
			throw new \InvalidArgumentException('The prompt must be valid UTF-8 and no longer than 4,000 bytes.');
		}
		return [
			'provider' => $provider,
			'model' => $model,
			'api_url' => ProviderClient::normalizeApiUrl($provider, $apiUrl),
			'prompt' => $prompt,
		];
	}

	public static function updateApiKey(string $existing, string $submitted, bool $clear): string {
		if (strlen($submitted) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $submitted)) {
			throw new \InvalidArgumentException('The API key is invalid or too long.');
		}
		if ($clear) {
			return '';
		}
		return $submitted === '' ? $existing : $submitted;
	}

	public static function configurationApiKey(string $oldProvider, string $oldUrl, string $newProvider, string $newUrl, string $existing, string $submitted, bool $clear): string {
		if ($submitted !== '' || $clear) {
			return self::updateApiKey($existing, $submitted, $clear);
		}
		if ($oldProvider !== $newProvider) {
			return '';
		}
		try {
			$oldUrl = ProviderClient::normalizeApiUrl($oldProvider, $oldUrl);
		} catch (\InvalidArgumentException) {
			return '';
		}
		return $oldUrl === $newUrl ? self::updateApiKey($existing, '', false) : '';
	}

	/** @return array{provider:string,api_url:string,api_key:string} */
	public static function validateModelLookup(string $provider, string $apiUrl, string $apiKey): array {
		if (!isset(self::DEFAULT_URLS[$provider])) {
			throw new \InvalidArgumentException('Select a supported provider.');
		}
		$apiKey = self::updateApiKey('', $apiKey, false);
		return [
			'provider' => $provider,
			'api_url' => ProviderClient::normalizeApiUrl($provider, $apiUrl),
			'api_key' => $apiKey,
		];
	}

	public static function modelLookupKey(string $provider, string $apiUrl, string $submittedKey, string $savedProvider, string $savedApiUrl, string $savedKey): string {
		$submittedKey = self::updateApiKey('', $submittedKey, false);
		if ($provider === 'ollama') {
			return '';
		}
		if ($submittedKey !== '' || $provider !== $savedProvider) {
			return $submittedKey;
		}
		try {
			$savedApiUrl = ProviderClient::normalizeApiUrl($savedProvider, $savedApiUrl);
		} catch (\InvalidArgumentException) {
			return '';
		}
		if ($apiUrl !== $savedApiUrl) {
			return '';
		}
		return self::updateApiKey('', $savedKey, false);
	}

	public static function prompt(string $prompt): string {
		return trim($prompt) === '' ? self::DEFAULT_PROMPT : $prompt;
	}
}
