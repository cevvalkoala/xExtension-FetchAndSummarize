<?php
declare(strict_types=1);

return [
	'fetch_and_summarize' => [
		'button' => 'Fetch and Summarize',
		'loading' => 'Fetching article and creating summary...',
		'error' => 'The summary could not be created. Check the extension settings and try again.',
		'saved' => 'Fetch and Summarize settings saved.',
		'configure' => [
			'title' => 'Fetch and Summarize',
			'description' => 'Choose a provider and model. The API key is stored in this FreshRSS user configuration.',
			'provider' => 'AI provider',
			'api_url' => 'API base URL',
			'api_url_help' => 'The URL is sent only by the server. API URLs cannot contain credentials, queries, or fragments. HTTPS is required except for Ollama.',
			'model' => 'Model name',
			'model_help' => 'Examples: gpt-4o-mini, claude-3-7-sonnet-latest, gemini-2.5-flash, openai/gpt-4o-mini, or a model installed in Ollama.',
			'api_key' => 'API key',
			'api_key_help' => 'Leave blank to keep the saved key. Keys are never shown again after saving.',
			'clear_key' => 'Clear the saved API key',
			'prompt' => 'Custom prompt',
			'prompt_help' => 'Leave blank to use the default prompt. Article text is separately identified as untrusted data.',
			'save' => 'Save settings',
		],
	],
];
