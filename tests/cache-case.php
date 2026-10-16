<?php
declare(strict_types=1);

class Minz_Extension {
	private array $files = [];
	protected function saveFile(string $filename, string $content): void { $this->files[$filename] = $content; }
	public function getFile(string $filename): ?string { return $this->files[$filename] ?? null; }
	public function storedFiles(): array { return $this->files; }
}

require_once dirname(__DIR__) . '/extension.php';
$extension = new FetchAndSummarizeExtension();
$settings = [
	'provider' => 'openai',
	'model' => 'gpt-4o-mini',
	'api_url' => 'https://api.openai.com/v1',
	'prompt' => '',
	'api_key' => 'must-not-be-cached',
];
$link = 'https://news.example/article';
$feedContent = '<p>feed source</p>';
$initialMiss = $extension->getCachedSummary('123', $link, $feedContent, $settings);
$extension->cacheSummary('123', $link, $feedContent, $settings, 'cached result', 'article');
$hit = $extension->getCachedSummary('123', $link, $feedContent, $settings);
$settingsChanged = $settings;
$settingsChanged['model'] = 'another-model';
$configMiss = $extension->getCachedSummary('123', $link, $feedContent, $settingsChanged);
$sourceMiss = $extension->getCachedSummary('123', $link, '<p>updated feed</p>', $settings);
$extension->cacheSummary('123', $link, $feedContent, $settings, 'feed result', 'feed');
$feedHit = $extension->getCachedSummary('123', $link, $feedContent, $settings);
$largeSummary = str_repeat('s', FetchAndSummarize\ProviderClient::MAX_SUMMARY_BYTES);
for ($id = 1000; $id < 1260; $id++) {
	$extension->cacheSummary((string)$id, $link . '/' . $id, $feedContent, $settings, $largeSummary, 'article');
}
$cacheFile = $extension->getFile('summaries.json');
$cacheData = json_decode((string)$cacheFile, true);
$countExtension = new FetchAndSummarizeExtension();
for ($id = 2000; $id < 2320; $id++) {
	$countExtension->cacheSummary((string)$id, $link . '/' . $id, $feedContent, $settings, 'short summary', 'article');
}
$countCache = json_decode((string)$countExtension->getFile('summaries.json'), true);
echo json_encode([
	'initialMiss' => $initialMiss,
	'hit' => $hit,
	'configMiss' => $configMiss,
	'sourceMiss' => $sourceMiss,
	'feedHit' => $feedHit,
	'cacheEntryCount' => count($cacheData['entries'] ?? []),
	'cacheBytes' => strlen((string)$cacheFile),
	'countCappedEntryCount' => count($countCache['entries'] ?? []),
	'files' => $extension->storedFiles(),
], JSON_THROW_ON_ERROR);
