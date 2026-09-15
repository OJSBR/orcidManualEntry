<?php

/**
 * @file plugins/generic/orcidManualEntry/tests/LocaleFilesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFilesTest
 *
 * @brief Translations. OJS 3.5 has no locale fallback: a key missing from a
 *        locale is rendered as ##key##, so an incomplete file is worse than none.
 */

namespace APP\plugins\generic\orcidManualEntry\tests;

class LocaleFilesTest extends TestCase
{
    /** Locale codes shipped by the plugin, using the OJS 3.5 (Weblate) codes. */
    public const LOCALES = [
        'ar', 'az', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'eu', 'fa', 'fi', 'fr', 'fr_CA',
        'gl', 'hu', 'hy', 'id', 'it', 'ja', 'ka', 'mk', 'ms', 'nb_NO', 'nl', 'pl', 'pt', 'pt_BR',
        'ro', 'ru', 'sl', 'sr_Latn', 'sv', 'tr', 'uk', 'vi', 'zh_Hans',
    ];

    /** Locales reviewed by a fluent speaker; every other one is marked fuzzy. */
    public const REVIEWED = ['en', 'pt', 'pt_BR', 'es', 'ca', 'gl', 'fr', 'fr_CA', 'it', 'de', 'nl'];

    /** Placeholders each key must keep, exactly once. */
    public const PLACEHOLDERS = [
        'error.duplicateOrcid' => ['{$name}'],
    ];

    public const PREFIX = 'plugins.generic.orcidManualEntry.';

    protected function localeDir(): string
    {
        return dirname(__DIR__) . '/locale';
    }

    /** @return array<string, PoFile> */
    protected function files(): array
    {
        $files = [];
        foreach (self::LOCALES as $locale) {
            $path = $this->localeDir() . "/{$locale}/locale.po";
            if (is_file($path)) {
                $files[$locale] = new PoFile($path);
            }
        }
        return $files;
    }

    public function testShipsExactlyTheSupportedLocaleCodes(): void
    {
        $dirs = array_map('basename', glob($this->localeDir() . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);
        $expected = self::LOCALES;
        sort($expected);

        // Legacy codes such as fr_FR or pt_PT do not exist in OJS 3.5 and would
        // silently never load.
        $this->assertSame($expected, $dirs);
        $this->assertCount(count(self::LOCALES), $this->files());
    }

    public function testEveryLocaleHasExactlyTheKeysOfTheEnglishMaster(): void
    {
        $files = $this->files();
        $master = array_keys($files['en']->entries);
        $this->assertCount(4, $master);

        foreach ($files as $locale => $file) {
            $this->assertSame($master, array_keys($file->entries), "Keys of {$locale} differ from en.");
        }
    }

    public function testEveryKeyTheCodeUsesExists(): void
    {
        $sources = '';
        foreach (array_merge(glob(dirname(__DIR__) . '/*.php'), glob(dirname(__DIR__) . '/js/*.js')) as $file) {
            $sources .= file_get_contents($file);
        }
        preg_match_all('/plugins\.generic\.orcidManualEntry\.[a-zA-Z.]+[a-zA-Z]/', $sources, $m);
        $keys = array_keys($this->files()['en']->entries);

        foreach (array_unique($m[0]) as $key) {
            $this->assertTrue(in_array($key, $keys, true), "{$key} is used but not translated.");
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertNotEmpty(trim($value), "Empty translation for {$key} in {$locale}.");
            }
        }
    }

    public function testHeaderDeclaresTheLocaleAndTheTeam(): void
    {
        foreach ($this->files() as $locale => $file) {
            $this->assertStringContainsString("Language: {$locale}\n", $file->header, "Wrong Language header in {$locale}.");
            $this->assertStringContainsString("Last-Translator: OJSBR\n", $file->header, "Missing Last-Translator in {$locale}.");
            $this->assertStringContainsString("Language-Team: OJSBR\n", $file->header, "Missing Language-Team in {$locale}.");
        }
    }

    public function testUnreviewedLocalesAreFuzzyAndReviewedOnesAreNot(): void
    {
        foreach (self::LOCALES as $locale) {
            $source = (string) file_get_contents($this->localeDir() . "/{$locale}/locale.po");
            $fuzzy = substr_count($source, "#, fuzzy\n");
            $expected = in_array($locale, self::REVIEWED, true) ? 0 : 4;
            $this->assertSame($expected, $fuzzy, "Unexpected number of fuzzy entries in {$locale}.");
        }
    }

    public function testPlaceholdersAreKept(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach (self::PLACEHOLDERS as $key => $placeholders) {
                $value = $file->entries[self::PREFIX . $key];
                foreach ($placeholders as $placeholder) {
                    $this->assertSame(1, substr_count($value, $placeholder), "{$placeholder} must appear once in {$key} ({$locale}).");
                }
                // "count" is reserved by __() and {translate} in PKP 3.5 (ICU plurals).
                $this->assertStringNotContainsString('{$count}', $value);
            }
        }
    }

    public function testTranslationsCarryNoMarkup(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertSame(strip_tags($value), $value, "Markup in {$key} ({$locale}).");
            }
        }
    }

    public function testTheDescriptionCarriesNoCredit(): void
    {
        // Credit belongs to the README and version.xml, not to the settings list.
        foreach ($this->files() as $locale => $file) {
            $this->assertStringNotContainsString('OJSBR', $file->entries[self::PREFIX . 'description'], "Credit in the description of {$locale}.");
        }
    }
}
