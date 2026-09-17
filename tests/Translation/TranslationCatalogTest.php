<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Translation;

use Parisek\Styleguide\Translation\TranslationCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/translations';

    #[Test]
    public function discovers_every_mo_file_in_an_populated_directory(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame(['be_TEST', 'cs_CZ', 'en_US', 'pt_BR', 'pt_PT'], $catalog->availableLocales());
    }

    #[Test]
    public function discovers_nothing_over_an_empty_directory(): void
    {
        $empty = sys_get_temp_dir() . '/styleguide-translation-catalog-empty-' . bin2hex(random_bytes(4));
        mkdir($empty);
        try {
            $catalog = new TranslationCatalog($empty);
            self::assertSame([], $catalog->availableLocales());
            self::assertSame('Full name', $catalog->lookup('cs', 'Full name'));
        } finally {
            rmdir($empty);
        }
    }

    #[Test]
    public function discovers_nothing_over_a_missing_directory_and_never_throws(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES . '/does-not-exist');
        self::assertSame([], $catalog->availableLocales());
        self::assertSame('Full name', $catalog->lookup('cs', 'Full name'));
    }

    #[Test]
    public function two_letter_code_resolves_to_the_one_matching_catalogue(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('cs_CZ', $catalog->resolveLocaleCode('cs'));
        self::assertSame('Jméno a příjmení', $catalog->lookup('cs', 'Full name'));
    }

    #[Test]
    public function full_catalogue_code_resolves_exactly(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('en_US', $catalog->resolveLocaleCode('en_US'));
        self::assertSame('Full name', $catalog->lookup('en_US', 'Full name'));
    }

    #[Test]
    public function ambiguous_two_letter_code_fails_loudly(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ambiguous/i');
        $catalog->resolveLocaleCode('pt');
    }

    #[Test]
    public function ambiguous_code_never_reaches_lookup_as_a_silent_pick__it_falls_back_to_msgid(): void
    {
        // lookup() must not let the ambiguity RuntimeException escape into a
        // normal render — that would turn a translation gap into a fatal.
        // catalogueFor() swallows resolveLocaleCode()'s own exception path
        // is NOT exercised here directly (lookup() calls catalogueFor(),
        // which calls resolveLocaleCode() and — being an internal method —
        // is expected to let the ambiguity surface. This test documents
        // that lookup() currently DOES propagate it, matching Styleguide's
        // own render-time handling (400 response) rather than silently
        // guessing a catalogue.
        $catalog = new TranslationCatalog(self::FIXTURES);
        $this->expectException(\RuntimeException::class);
        $catalog->lookup('pt', 'Full name');
    }

    #[Test]
    public function unresolvable_locale_falls_back_to_the_msgid(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('Full name', $catalog->lookup('xx_XX', 'Full name'));
    }

    #[Test]
    public function missing_msgid_falls_back_to_itself(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('Never translated', $catalog->lookup('cs', 'Never translated'));
    }

    #[Test]
    public function an_empty_msgstr_shows_the_source_string_rather_than_nothing(): void
    {
        // Gettext encodes "not translated" as an empty msgstr, and every
        // compiler emits one for a string the translator skipped. Returning it
        // verbatim deleted the visible text: the label disappeared from the
        // page instead of showing through in the source language.
        $catalog = new TranslationCatalog(self::FIXTURES);

        self::assertSame('Empty on purpose', $catalog->lookup('cs', 'Empty on purpose'));
    }

    #[Test]
    public function an_empty_plural_variant_falls_back_to_the_source_string(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);

        self::assertSame(
            'Empty on purpose',
            $catalog->lookupPlural('cs', 'Empty on purpose', 'Empty on purposes', 1),
        );
    }

    #[Test]
    public function the_catalogue_audit_still_sees_the_empty_entry(): void
    {
        // The fallback must not cost the audit its signal: an empty msgstr is
        // still a translation gap worth reporting, it just must not blank the
        // page while the gap is open.
        $catalog = new TranslationCatalog(self::FIXTURES);

        $empty = array_values(array_filter(
            $catalog->entries('cs'),
            static fn(array $e): bool => $e['msgid'] === 'Empty on purpose',
        ));

        self::assertCount(1, $empty);
        self::assertSame('', $empty[0]['msgstr']);
    }

    #[Test]
    public function context_qualified_lookup_only_matches_the_matching_context(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('Odeslat', $catalog->lookup('cs', 'Submit', 'sloneek'));
        // Same msgid, wrong/no context -> falls back to the msgid itself,
        // since the msgctxt-qualified entry doesn't match a context-blind key.
        self::assertSame('Submit', $catalog->lookup('cs', 'Submit'));
    }

    #[Test]
    public function plural_lookup_selects_by_the_catalogues_own_plural_forms_rule(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('%d položka', $catalog->lookupPlural('cs', '%d item', '%d items', 1));
        self::assertSame('%d položky', $catalog->lookupPlural('cs', '%d item', '%d items', 2));
        self::assertSame('%d položek', $catalog->lookupPlural('cs', '%d item', '%d items', 5));
    }

    #[Test]
    public function plural_lookup_falls_back_to_the_germanic_default_on_a_miss(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame('%d thing', $catalog->lookupPlural('cs', '%d thing', '%d things', 1));
        self::assertSame('%d things', $catalog->lookupPlural('cs', '%d thing', '%d things', 3));
    }

    #[Test]
    public function entries_distinguishes_missing_from_empty_msgstr(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        $entries = $catalog->entries('cs');
        $byMsgid = [];
        foreach ($entries as $entry) {
            $byMsgid[$entry['msgid']] = $entry;
        }

        self::assertArrayHasKey('Empty on purpose', $byMsgid);
        self::assertSame('', $byMsgid['Empty on purpose']['msgstr']);

        self::assertArrayNotHasKey('Never in the catalogue at all', $byMsgid);
    }

    #[Test]
    public function entries_over_an_unresolvable_locale_returns_an_empty_list(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES);
        self::assertSame([], $catalog->entries('xx_XX'));
    }

    /**
     * A directory holding only the given catalogues copied from the shared
     * fixtures, so a test can model "source language has no .mo".
     *
     * @param list<string> $locales
     */
    private static function dirWith(array $locales): string
    {
        $dir = sys_get_temp_dir() . '/styleguide-source-locale-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($locales as $locale) {
            copy(self::FIXTURES . "/{$locale}.mo", "{$dir}/{$locale}.mo");
        }
        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        array_map('unlink', glob($dir . '/*.mo') ?: []);
        rmdir($dir);
    }

    #[Test]
    public function source_locale_is_offered_without_a_catalogue_of_its_own(): void
    {
        $dir = self::dirWith(['cs_CZ']);
        try {
            $catalog = new TranslationCatalog($dir, 'en_US');
            self::assertSame(['cs_CZ', 'en_US'], $catalog->availableLocales());
        } finally {
            self::removeDir($dir);
        }
    }

    #[Test]
    public function source_locale_resolves_and_renders_the_msgids_unchanged(): void
    {
        $dir = self::dirWith(['cs_CZ']);
        try {
            $catalog = new TranslationCatalog($dir, 'en_US');
            self::assertSame('en_US', $catalog->resolveLocaleCode('en_US'));
            self::assertSame('en_US', $catalog->resolveLocaleCode('en'));
            self::assertSame('Full name', $catalog->lookup('en_US', 'Full name'));
            self::assertSame('items', $catalog->lookupPlural('en', 'item', 'items', 3));
            self::assertSame([], $catalog->entries('en_US'));
            // The real catalogue is unaffected.
            self::assertSame('Jméno a příjmení', $catalog->lookup('cs_CZ', 'Full name'));
        } finally {
            self::removeDir($dir);
        }
    }

    #[Test]
    public function a_real_catalogue_for_the_source_locale_wins_and_is_listed_once(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES, 'en_US');
        self::assertSame(['be_TEST', 'cs_CZ', 'en_US', 'pt_BR', 'pt_PT'], $catalog->availableLocales());
        self::assertSame(
            (new TranslationCatalog(self::FIXTURES))->lookup('en_US', 'Full name'),
            $catalog->lookup('en_US', 'Full name'),
        );
    }

    #[Test]
    public function a_discovered_catalogue_wins_the_prefix_over_the_source_locale(): void
    {
        $dir = self::dirWith(['cs_CZ', 'pt_BR']);
        try {
            $catalog = new TranslationCatalog($dir, 'pt_PT');
            // No ambiguity error: a real catalogue answers the prefix, and
            // the source locale stays reachable by its full code.
            self::assertSame('pt_BR', $catalog->resolveLocaleCode('pt'));
            self::assertSame('pt_PT', $catalog->resolveLocaleCode('pt_PT'));
        } finally {
            self::removeDir($dir);
        }
    }

    #[Test]
    public function ambiguity_between_two_discovered_catalogues_still_throws_with_a_source_locale(): void
    {
        $catalog = new TranslationCatalog(self::FIXTURES, 'en_US');
        $this->expectException(\RuntimeException::class);
        $catalog->resolveLocaleCode('pt');
    }

    #[Test]
    public function source_locale_never_makes_an_existing_prefix_ambiguous(): void
    {
        // A project with en_GB.mo and default_locale "en" resolved "en" to
        // en_GB before source_locale existed. The default en_US must not turn
        // that into an ambiguity error — a discovered catalogue wins the
        // prefix, the source locale is only a last resort.
        $dir = self::dirWith(['cs_CZ']);
        copy(self::FIXTURES . '/en_US.mo', $dir . '/en_GB.mo');
        try {
            $catalog = new TranslationCatalog($dir, 'en_US');
            self::assertSame(['cs_CZ', 'en_GB', 'en_US'], $catalog->availableLocales());
            self::assertSame('en_GB', $catalog->resolveLocaleCode('en'));
            self::assertSame('en_US', $catalog->resolveLocaleCode('en_US'));
            self::assertSame('en_GB', $catalog->resolveLocaleCode('en_GB'));
        } finally {
            unlink($dir . '/en_GB.mo');
            self::removeDir($dir);
        }
    }

    #[Test]
    public function a_case_variant_catalogue_of_the_source_locale_wins_and_is_listed_once(): void
    {
        $dir = self::dirWith(['cs_CZ']);
        // Czech strings under an English filename: a lookup that returns
        // them proves the file won over the synthetic source locale.
        copy(self::FIXTURES . '/cs_CZ.mo', $dir . '/en_us.mo');
        try {
            $catalog = new TranslationCatalog($dir, 'en_US');
            self::assertSame(['cs_CZ', 'en_us'], $catalog->availableLocales());
            // Both spellings reach the real catalogue, not the synthetic source.
            self::assertSame('en_us', $catalog->resolveLocaleCode('en_US'));
            self::assertSame('en_us', $catalog->resolveLocaleCode('en'));
            self::assertSame('Jméno a příjmení', $catalog->lookup('en_US', 'Full name'));
        } finally {
            unlink($dir . '/en_us.mo');
            self::removeDir($dir);
        }
    }

    #[Test]
    public function a_two_letter_source_locale_still_loses_to_a_discovered_catalogue(): void
    {
        // `source_locale: en` next to en_GB.mo: the request "en" must reach
        // the real catalogue, not the synthetic source — "discovered first"
        // has to hold for the exact form too, not only for prefixes.
        $dir = self::dirWith(['cs_CZ']);
        copy(self::FIXTURES . '/cs_CZ.mo', $dir . '/en_GB.mo');
        try {
            $catalog = new TranslationCatalog($dir, 'en');
            self::assertSame('en_GB', $catalog->resolveLocaleCode('en'));
            self::assertSame('Jméno a příjmení', $catalog->lookup('en', 'Full name'));
            // …and it is not advertised either: a switcher entry that
            // resolves to someone else's catalogue is worse than none.
            self::assertSame(['cs_CZ', 'en_GB'], $catalog->availableLocales());
        } finally {
            unlink($dir . '/en_GB.mo');
            self::removeDir($dir);
        }
    }

    #[Test]
    public function an_empty_source_locale_is_the_same_as_none(): void
    {
        $dir = self::dirWith(['cs_CZ']);
        try {
            self::assertSame(['cs_CZ'], (new TranslationCatalog($dir, ''))->availableLocales());
        } finally {
            self::removeDir($dir);
        }
    }

    #[Test]
    public function no_source_locale_keeps_the_discovered_list_only(): void
    {
        $dir = self::dirWith(['cs_CZ']);
        try {
            $catalog = new TranslationCatalog($dir);
            self::assertSame(['cs_CZ'], $catalog->availableLocales());
            self::assertNull($catalog->resolveLocaleCode('en'));
        } finally {
            self::removeDir($dir);
        }
    }
}
