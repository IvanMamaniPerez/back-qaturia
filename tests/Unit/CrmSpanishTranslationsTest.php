<?php

use PHPUnit\Framework\Assert;

test('CRM Spanish translations preserve the English file and array structure', function (): void {
    $directory = dirname(__DIR__, 2).'/modules/crm/resources/lang';

    $englishFiles = array_map('basename', glob($directory.'/en/*.php') ?: []);
    $spanishFiles = array_map('basename', glob($directory.'/es/*.php') ?: []);
    sort($englishFiles);
    sort($spanishFiles);

    Assert::assertNotEmpty($englishFiles, 'English CRM locale files are missing.');
    Assert::assertSame($englishFiles, $spanishFiles, 'Spanish locale files must match English locale files.');

    // These words, brand names, units and literal commands are valid in both locales.
    // Any newly introduced identical text requires an explicit translation review.
    $allowedUnchanged = [
        'lang.php.system_check_db_update_command',
        'lang.php.no',
        'lang.php.roles',
        'lang.php.color',
        'lang.php.total',
        'lang.php.chat',
        'lang.php.chats',
        'lang.php.linkedin',
        'lang.php.sku',
        'lang.php.general',
        'lang.php.sms',
        'lang.php.social',
        'lang.php.clicksend',
        'lang.php.admin',
        'lang.php.monitor',
        'lang.php.url',
        'lang.php.ms',
        'lang.php.error',
        'lang.php.ok',
    ];

    $assertTranslations = function (array $english, array $spanish, string $path) use (&$assertTranslations, $allowedUnchanged): void {
        Assert::assertCount(count($english), $spanish, 'Different number of elements at '.$path);
        Assert::assertSame(array_keys($english), array_keys($spanish), 'Different keys or order at '.$path);

        foreach ($english as $key => $value) {
            $currentPath = $path.'.'.$key;
            $translation = $spanish[$key];

            if (is_array($value)) {
                Assert::assertIsArray($translation, 'Expected a nested array at '.$currentPath);
                $assertTranslations($value, $translation, $currentPath);

                continue;
            }

            Assert::assertIsString($value, 'Expected an English string at '.$currentPath);
            Assert::assertIsString($translation, 'Expected a Spanish string at '.$currentPath);

            if ($value !== '') {
                Assert::assertNotSame('', trim($translation), 'Empty translation at '.$currentPath);
            }

            if (strcasecmp($value, $translation) === 0) {
                Assert::assertContains($currentPath, $allowedUnchanged, 'Unreviewed untranslated text at '.$currentPath);
            }

            // Compare replacement tokens exactly, including repeated tokens.
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $value, $englishMarkers);
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $translation, $spanishMarkers);
            $englishMarkers = $englishMarkers[0];
            $spanishMarkers = $spanishMarkers[0];
            sort($englishMarkers);
            sort($spanishMarkers);

            Assert::assertSame($englishMarkers, $spanishMarkers, 'Changed Laravel placeholders at '.$currentPath);

            // HTML tags and entities are used by the view and must not be translated.
            preg_match_all('/<\/?[A-Za-z][^>]*>/', $value, $englishTags);
            preg_match_all('/<\/?[A-Za-z][^>]*>/', $translation, $spanishTags);
            Assert::assertSame($englishTags[0], $spanishTags[0], 'Changed HTML tags at '.$currentPath);

            preg_match_all('/&(?:#[0-9]+|[A-Za-z]+);/', $value, $englishEntities);
            preg_match_all('/&(?:#[0-9]+|[A-Za-z]+);/', $translation, $spanishEntities);
            Assert::assertSame($englishEntities[0], $spanishEntities[0], 'Changed HTML entities at '.$currentPath);

            if ($key === 'system_check_db_update_command') {
                Assert::assertSame($value, $translation, 'Executable Artisan commands must remain unchanged.');
            }
        }
    };

    foreach ($englishFiles as $filename) {
        $englishPath = $directory.'/en/'.$filename;
        $spanishPath = $directory.'/es/'.$filename;

        $english = require $englishPath;
        $spanish = require $spanishPath;

        Assert::assertIsArray($english);
        Assert::assertIsArray($spanish);

        // PHP overwrites repeated keys when loading arrays; checking token counts
        // also detects missing source entries that would otherwise be hidden.
        $arrowCount = static function (string $path): int {
            $tokens = token_get_all(file_get_contents($path));

            return count(array_filter(
                $tokens,
                static fn ($token): bool => is_array($token) && $token[0] === T_DOUBLE_ARROW,
            ));
        };

        Assert::assertSame(
            $arrowCount($englishPath),
            $arrowCount($spanishPath),
            'Different number of raw array entries in '.$filename,
        );

        $assertTranslations($english, $spanish, $filename);
    }
});
