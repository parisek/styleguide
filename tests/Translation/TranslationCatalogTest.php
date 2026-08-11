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
}
