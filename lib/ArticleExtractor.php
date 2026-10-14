<?php
declare(strict_types=1);

namespace FetchAndSummarize;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;

final class ArticleExtractor {
	public const MAX_HTML_BYTES = 2_000_000;
	public const MAX_TEXT_BYTES = 50_000;

	public function extract(string $html, string $url): string {
		if ($html === '' || strlen($html) > self::MAX_HTML_BYTES) {
			throw new \RuntimeException('The article page is empty or too large.');
		}
		$config = new Configuration([
			'CharThreshold' => 80,
			'FixRelativeURLs' => false,
			'OriginalURL' => $url,
			'DisableJSONLD' => true,
		]);
		$parser = new Readability($config);
		try {
			$parsed = $parser->parse($html);
		} catch (\Throwable) {
			throw new \RuntimeException('No readable article content was found.');
		}
		if (!$parsed) {
			throw new \RuntimeException('No readable article content was found.');
		}
		$content = $parser->getContent();
		if (!is_string($content) || $content === '') {
			throw new \RuntimeException('No readable article content was found.');
		}
		return $this->htmlToText($content);
	}

	public function extractFeedContent(string $html): string {
		if ($html === '' || strlen($html) > self::MAX_HTML_BYTES) {
			throw new \RuntimeException('The feed did not include usable article text.');
		}
		return $this->htmlToText($html);
	}

	private function htmlToText(string $html): string {
		$document = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		try {
			if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
				throw new \RuntimeException('The article content could not be converted to text.');
			}
			foreach (['script', 'style', 'noscript', 'template', 'iframe'] as $tag) {
				$nodes = $document->getElementsByTagName($tag);
				for ($i = $nodes->length - 1; $i >= 0; $i--) {
					$node = $nodes->item($i);
					$node?->parentNode?->removeChild($node);
				}
			}
			$text = $document->textContent;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$text = preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? '';
		$text = trim($text);
		if ($text === '') {
			throw new \RuntimeException('The page or feed did not contain extractable article text.');
		}
		if (strlen($text) > self::MAX_TEXT_BYTES) {
			$text = self::truncateUtf8($text, self::MAX_TEXT_BYTES);
		}
		return $text;
	}

	private static function truncateUtf8(string $text, int $maxBytes): string {
		$text = substr($text, 0, $maxBytes);
		while ($text !== '' && preg_match('//u', $text) !== 1) {
			$text = substr($text, 0, -1);
		}
		return $text;
	}
}
