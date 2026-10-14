<?php
declare(strict_types=1);

namespace FetchAndSummarize;

final class EntryId {
	public static function isValid(string $id): bool {
		return $id !== '' && strlen($id) <= 20 && ctype_digit($id);
	}
}
