<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	$prefixes = [
		'FetchAndSummarize\\' => __DIR__ . '/',
		'fivefilters\\Readability\\' => dirname(__DIR__) . '/vendor/fivefilters/readability.php/src/',
		'Masterminds\\' => dirname(__DIR__) . '/vendor/masterminds/html5/src/',
		'League\\Uri\\Interfaces\\' => dirname(__DIR__) . '/vendor/league/uri-interfaces/',
		'League\\Uri\\' => dirname(__DIR__) . '/vendor/league/uri/',
		'Psr\\Http\\Message\\' => [dirname(__DIR__) . '/vendor/psr/http-message/src/', dirname(__DIR__) . '/vendor/psr/http-factory/src/'],
		'Psr\\Log\\' => dirname(__DIR__) . '/vendor/psr/log/src/',
	];
	foreach ($prefixes as $prefix => $directory) {
		if (str_starts_with($class, $prefix)) {
			$directories = is_array($directory) ? $directory : [$directory];
			foreach ($directories as $baseDirectory) {
				$file = $baseDirectory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file)) {
					require_once $file;
					return;
				}
			}
			return;
		}
	}
});
