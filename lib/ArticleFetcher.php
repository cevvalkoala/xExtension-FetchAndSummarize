<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class ArticleFetcher {
	private const MAX_REDIRECTS = 4;
	private const MAX_BYTES = ArticleExtractor::MAX_HTML_BYTES;
	private const MAX_TOTAL_SECONDS = 45;
	/** @var \Closure(string):list<string> */
	private \Closure $resolver;
	/** @var \Closure(string,list<string>,int):array{status:int,headers:array<string,string>,body:string,primary_ip:string} */
	private \Closure $requester;

	public function __construct(?callable $resolver = null, ?callable $requester = null) {
		$this->resolver = $resolver === null ? self::resolvePublicAddresses(...) : \Closure::fromCallable($resolver);
		$this->requester = $requester === null ? $this->curlRequest(...) : \Closure::fromCallable($requester);
	}

	/** @return array{html:string,url:string} */
	public function fetch(string $url): array {
		$startedAt = microtime(true);
		for ($redirect = 0; ; $redirect++) {
			$parts = self::parsePublicHttpUrl($url);
			$addresses = ($this->resolver)($parts['host']);
			if ($addresses === []) {
				throw new \RuntimeException('The article host did not resolve to a public address.');
			}
			foreach ($addresses as $address) {
				if (!self::isPublicIp($address)) {
					throw new \RuntimeException('The article host resolves to a non-public address.');
				}
			}
			$remainingSeconds = (int)ceil(self::MAX_TOTAL_SECONDS - (microtime(true) - $startedAt));
			if ($remainingSeconds < 1) {
				throw new \RuntimeException('The article request exceeded its time limit.');
			}
			$response = ($this->requester)($url, $addresses, min(25, $remainingSeconds));
			if (!in_array(self::normalizedIp($response['primary_ip']), array_map(self::normalizedIp(...), $addresses), true)) {
				throw new \RuntimeException('The connection did not use a validated public address.');
			}
			$status = $response['status'];
			if (in_array($status, [301, 302, 303, 307, 308], true)) {
				$location = $response['headers']['location'] ?? '';
				if ($location === '' || $redirect >= self::MAX_REDIRECTS) {
					throw new \RuntimeException('The article redirect chain is invalid or too long.');
				}
				$url = self::resolveRedirect($url, $location);
				continue;
			}
			if ($status < 200 || $status >= 300) {
				throw new \RuntimeException('The article server returned an unsuccessful response.');
			}
			$type = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));
			if (!in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
				throw new \RuntimeException('The article URL did not return an HTML page.');
			}
			if ($response['body'] === '' || strlen($response['body']) > self::MAX_BYTES) {
				throw new \RuntimeException('The article page is empty or too large.');
			}
			return ['html' => $response['body'], 'url' => $url];
		}
	}

	/** @return array{scheme:string,host:string,port:int} */
	public static function parsePublicHttpUrl(string $url): array {
		if (strlen($url) > 8192 || preg_match('/[\x00-\x20\\\\]/', $url)) {
			throw new \RuntimeException('The article URL is invalid.');
		}
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			throw new \RuntimeException('The article URL is invalid.');
		}
		$scheme = strtolower($parts['scheme']);
		$host = strtolower($parts['host']);
		if (!in_array($scheme, ['http', 'https'], true) || $host === '' || str_ends_with($host, '.') || preg_match('/[^\x21-\x7e]/', $host)) {
			throw new \RuntimeException('Only valid HTTP(S) article URLs are allowed.');
		}
		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
		if ($port < 1 || $port > 65535) {
			throw new \RuntimeException('The article URL port is invalid.');
		}
		return ['scheme' => $scheme, 'host' => trim($host, '[]'), 'port' => $port];
	}

	public static function isPublicIp(string $ip): bool {
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return false;
		}
		if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			return false;
		}
		if (strlen($packed) === 4) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				return false;
			}
			foreach (['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
				if (self::inCidr($packed, $cidr)) {
					return false;
				}
			}
			return true;
		}
		if (strlen($packed) !== 16 || !self::inCidr($packed, '2000::/3')) {
			return false;
		}
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}
		foreach (['2001::/23', '2001:db8::/32', '2002::/16'] as $cidr) {
			if (self::inCidr($packed, $cidr)) {
				return false;
			}
		}
		return true;
	}

	private static function resolvePublicAddresses(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}
		$records = @dns_get_record($host . '.', DNS_A | DNS_AAAA);
		if (!is_array($records)) {
			return [];
		}
		$addresses = [];
		foreach ($records as $record) {
			if (isset($record['ip']) && is_string($record['ip'])) {
				$addresses[] = $record['ip'];
			} elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
				$addresses[] = $record['ipv6'];
			}
		}
		return array_values(array_unique($addresses));
	}

	private function curlRequest(string $url, array $addresses, int $timeout): array {
		if (!function_exists('curl_init')) {
			throw new \RuntimeException('The PHP cURL extension is required to fetch articles.');
		}
		$parts = self::parsePublicHttpUrl($url);
		$body = '';
		$headers = [];
		$handle = curl_init($url);
		if ($handle === false) {
			throw new \RuntimeException('The article connection could not be initialized.');
		}
		$options = [
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_PROXY => '',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9', 'Accept-Encoding: identity'],
			CURLOPT_USERAGENT => 'FreshRSS-FetchAndSummarize/1.0 (+server-side article fetch)',
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
				$length = strlen($line);
				if (preg_match('/^([A-Za-z0-9-]+):\s*(.*?)\s*$/', $line, $m)) {
					$headers[strtolower($m[1])] = $m[2];
				}
				return $length;
			},
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
				if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
					return 0;
				}
				$body .= $chunk;
				return strlen($chunk);
			},
		];
		if (filter_var($parts['host'], FILTER_VALIDATE_IP) === false) {
			$resolve = [];
			foreach ($addresses as $address) {
				$resolve[] = str_contains($address, ':') ? '[' . $address . ']' : $address;
			}
			$options[CURLOPT_RESOLVE] = [$parts['host'] . ':' . $parts['port'] . ':' . implode(',', $resolve)];
		}
		if (!curl_setopt_array($handle, $options)) {
			curl_close($handle);
			throw new \RuntimeException('The article connection could not be safely configured.');
		}
		$ok = curl_exec($handle);
		$code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$primary = (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
		$error = curl_errno($handle);
		curl_close($handle);
		if ($ok === false || $error !== 0) {
			throw new \RuntimeException('The article could not be fetched within the allowed limits.');
		}
		return ['status' => (int)$code, 'headers' => $headers, 'body' => $body, 'primary_ip' => $primary];
	}

	private static function resolveRedirect(string $base, string $location): string {
		$location = trim($location);
		if ($location === '' || preg_match('/[\x00-\x20\\\\]/', $location)) {
			throw new \RuntimeException('The article redirect target is invalid.');
		}
		if (preg_match('~^https?://~i', $location)) {
			return $location;
		}
		$baseParts = parse_url($base);
		if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
			throw new \RuntimeException('The article redirect target is invalid.');
		}
		$origin = $baseParts['scheme'] . '://' . (str_contains($baseParts['host'], ':') ? '[' . trim($baseParts['host'], '[]') . ']' : $baseParts['host']);
		if (isset($baseParts['port'])) {
			$origin .= ':' . $baseParts['port'];
		}
		if (str_starts_with($location, '//')) {
			return $baseParts['scheme'] . ':' . $location;
		}
		if (str_starts_with($location, '?')) {
			return $origin . ($baseParts['path'] ?? '/') . $location;
		}
		$locationParts = parse_url($location);
		if ($locationParts === false || isset($locationParts['scheme']) || isset($locationParts['host'])) {
			throw new \RuntimeException('The article redirect target is invalid.');
		}
		$path = $locationParts['path'] ?? '';
		if ($path === '') {
			$path = $baseParts['path'] ?? '/';
		} elseif (!str_starts_with($path, '/')) {
			$path = substr($baseParts['path'] ?? '/', 0, (int)strrpos($baseParts['path'] ?? '/', '/') + 1) . $path;
		}
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '..') {
				array_pop($segments);
			} elseif ($segment !== '.') {
				$segments[] = $segment;
			}
		}
		$path = implode('/', $segments);
		$query = isset($locationParts['query']) ? '?' . $locationParts['query'] : '';
		return $origin . ($path === '' ? '/' : $path) . $query;
	}

	private static function normalizedIp(string $ip): string {
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return '';
		}
		if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			$packed = substr($packed, 12);
		}
		return inet_ntop($packed) ?: '';
	}

	private static function inCidr(string $packed, string $cidr): bool {
		[$network, $bits] = explode('/', $cidr, 2);
		$networkPacked = inet_pton($network);
		if ($networkPacked === false || strlen($packed) !== strlen($networkPacked)) {
			return false;
		}
		$wholeBytes = intdiv((int)$bits, 8);
		$remainingBits = (int)$bits % 8;
		if (substr($packed, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
			return false;
		}
		if ($remainingBits === 0) {
			return true;
		}
		$mask = (0xff << (8 - $remainingBits)) & 0xff;
		return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
	}
}
