<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\Language;
use ProcessWire\Languages;

use function ProcessWire\wire;

/**
 * @return list<list<string>>
 */
function parseHtmxCsvRows(string $csv): array
{
	$fp = fopen('php://temp', 'r+');
	if ($fp === false) {
		return [];
	}

	fwrite($fp, $csv);
	rewind($fp);

	$rows = [];
	while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
		if ($row === [null]) {
			continue;
		}
		$rows[] = array_map(static fn(?string $cell): string => (string) $cell, $row);
	}

	fclose($fp);

	return $rows;
}

/**
 * @return list<string>
 */
function extractHtmxPlaceholders(string $text): array
{
	preg_match_all('/%(?:\d+\$)?[sd]|{[a-zA-Z0-9_|]+}/', $text, $matches);
	$tokens = $matches[0];
	sort($tokens);

	return $tokens;
}

test('Htmx 6-locale CSV packs match LanguagePorter exportCsv row-for-row with intact placeholders and zero test leaks', function (): void {
	/** @var Languages|null $languages */
	$languages = wire('languages');
	expect($languages)->toBeInstanceOf(Languages::class);

	/** @var Language $defaultLang */
	$defaultLang = $languages->getDefault();
	$exportedCsv = (string) $defaultLang->porter->exportCsv([
		'source' => 'site/modules/Htmx/',
		'scope' => 'all',
		'exportTo' => 'string',
	]);

	$exportedRows = parseHtmxCsvRows($exportedCsv);
	expect(count($exportedRows))->toBeGreaterThan(1);

	$expectedDataRows = array_slice($exportedRows, 1);
	expect($expectedDataRows)->not->toBeEmpty();

	foreach ($expectedDataRows as $rowIdx => $expectedRow) {
		expect($expectedRow[3] ?? '')->not->toContain('/tests/', "Row {$rowIdx} unexpectedly references a test file.");
	}

	$languagesDir = dirname(__DIR__, 2) . '/languages';
	$locales = ['en', 'tr', 'de', 'fr', 'es', 'ru'];

	foreach ($locales as $locale) {
		$csvPath = $languagesDir . '/' . $locale . '.csv';
		expect(is_file($csvPath))->toBeTrue("Missing language pack: {$csvPath}");

		$packRows = parseHtmxCsvRows((string) file_get_contents($csvPath));
		expect(count($packRows))->toBe(count($exportedRows), "Row count mismatch in {$locale}.csv");

		$expectedHeader = $locale === 'en'
			? ['default', 'en', 'description', 'file', 'hash']
			: ['en', $locale, 'description', 'file', 'hash'];
		expect($packRows[0])->toBe($expectedHeader, "Header mismatch in {$locale}.csv");

		$packDataRows = array_slice($packRows, 1);
		foreach ($expectedDataRows as $idx => $expectedRow) {
			$actualRow = $packDataRows[$idx];
			$origPhrase = $expectedRow[0];
			$translatedPhrase = $actualRow[1];

			expect($actualRow[0])->toBe($origPhrase, "Source phrase mismatch at row {$idx} in {$locale}.csv")
				->and(trim($translatedPhrase))->not->toBe('', "Empty translation at row {$idx} in {$locale}.csv")
				->and($actualRow[2])->toBe($expectedRow[2], "Description mismatch at row {$idx} in {$locale}.csv")
				->and($actualRow[3])->toBe($expectedRow[3], "File path mismatch at row {$idx} in {$locale}.csv")
				->and($actualRow[4])->toBe($expectedRow[4], "Hash mismatch at row {$idx} in {$locale}.csv")
				->and(extractHtmxPlaceholders($translatedPhrase))->toBe(
					extractHtmxPlaceholders($origPhrase),
					"Placeholder mismatch for '{$origPhrase}' in {$locale}.csv"
				);

			if ($locale === 'en') {
				expect($translatedPhrase)->toBe($origPhrase);
			}
		}
	}
});
