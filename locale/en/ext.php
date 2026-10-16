<?php
declare(strict_types=1);

return [
	'fetch_and_summarize' => [
		'button' => 'F&S',
		'loading' => 'Fetching article and creating summary...',
		'error' => 'The summary could not be created. Check the extension settings and try again.',
		'summary_source_feed' => 'Summary source: text provided in the feed item.',
		'summary_source_article' => 'Summary source: article fetched from the publisher\'s server.',
		'saved' => 'Fetch and Summarize settings saved.',
		'configure' => [
			'title' => 'Fetch and Summarize',
			'description' => 'Choose a provider and model. Keys are stored in this user configuration when saved; loading models sends the form key to the selected provider without saving it.',
			'provider' => 'AI provider',
			'api_url' => 'API base URL',
			'api_url_help' => 'The URL is sent only by the server. API URLs cannot contain credentials, queries, or fragments. HTTPS is required except for Ollama.',
			'model' => 'Model name',
			'model_help' => 'Examples: gpt-4o-mini, claude-3-7-sonnet-latest, gemini-2.5-flash, openai/gpt-4o-mini, or a model installed in Ollama.',
			'api_key' => 'API key',
			'api_key_help' => 'Enter a key to load models. Loading does not save it. On Save, a blank key is retained only if the provider and API URL stay unchanged; changing either clears the old key. Saved keys are never shown again.',
			'clear_key' => 'Clear the saved API key',
			'prompt' => 'Custom prompt',
			'prompt_help' => 'Leave blank to use the default prompt. Article text is separately identified as untrusted data.',
			'load_models' => 'Load models',
			'loading_models' => 'Loading models...',
			'models_loaded' => 'Loaded {count} models. Choose one from the suggestions or enter a model name.',
			'no_models' => 'No compatible models were returned. You can still enter a model name manually.',
			'models_error' => 'Models could not be loaded. Check the provider, API URL, and API key.',
			'save' => 'Save settings',
		],
	],
];
