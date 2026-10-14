<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class Settings {
	public const DEFAULT_PROMPT = 'Fetch the article from the link. If the content is shorter than 400 words, tell me and stop there. If otherwise, give a 200-word summary for this article in FreshRSS interface language.';

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

	public static function prompt(string $prompt): string {
		return trim($prompt) === '' ? self::DEFAULT_PROMPT : $prompt;
	}
}
