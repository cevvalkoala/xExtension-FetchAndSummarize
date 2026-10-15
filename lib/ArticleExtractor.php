<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class ArticleExtractor {
	public const MAX_HTML_BYTES = 2_000_000;
	public const MAX_TEXT_BYTES = 50_000;

	public function extract(string $html): string {
		if ($html === '' || strlen($html) > self::MAX_HTML_BYTES) {
			throw new \RuntimeException('The article page is empty or too large.');
		}
		return $this->htmlToText($html);
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
				throw new \RuntimeException('The page content could not be converted to text.');
			}
			foreach (['script', 'style', 'noscript', 'template', 'iframe'] as $tag) {
				$nodes = $document->getElementsByTagName($tag);
				for ($i = $nodes->length - 1; $i >= 0; $i--) {
					$node = $nodes->item($i);
					$node?->parentNode?->removeChild($node);
				}
			}
			$text = $this->collectText($document->documentElement);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$text = preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? '';
		$text = trim($text);
		if ($text === '') {
			throw new \RuntimeException('The page or feed did not contain extractable text.');
		}
		if (strlen($text) > self::MAX_TEXT_BYTES) {
			$text = self::truncateUtf8($text, self::MAX_TEXT_BYTES);
		}
		return $text;
	}

	private function collectText(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			return $node->nodeValue ?? '';
		}
		if (!$node instanceof \DOMElement) {
			return '';
		}
		$tag = strtolower($node->tagName);
		if ($tag === 'br' || $tag === 'hr') {
			return "\n";
		}
		$text = '';
		foreach ($node->childNodes as $child) {
			$text .= $this->collectText($child);
		}
		if (in_array($tag, ['address', 'article', 'aside', 'blockquote', 'body', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'td', 'th', 'tr', 'ul'], true)) {
			return "\n" . $text . "\n";
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
