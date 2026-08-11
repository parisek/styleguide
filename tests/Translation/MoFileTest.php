<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Translation;

use Parisek\Styleguide\Translation\MoFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoFileTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/translations';

    #[Test]
    public function reads_a_plain_msgid_lookup(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');
        $entry = $mo->find('Full name');
        self::assertNotNull($entry);
        self::assertSame('Jméno a příjmení', $entry['msgstr']);
        self::assertNull($entry['context']);
    }

    #[Test]
    public function reads_a_msgctxt_qualified_entry_and_a_context_blind_lookup_misses_it(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');

        $entry = $mo->find('Submit', 'sloneek');
        self::assertNotNull($entry);
        self::assertSame('Odeslat', $entry['msgstr']);
        self::assertSame('sloneek', $entry['context']);

        // Same msgid, no context supplied — must NOT match the
        // context-qualified entry (msgctxt is part of the lookup key).
        self::assertNull($mo->find('Submit'));
    }

    #[Test]
    public function reads_plural_variants(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');
        $entry = $mo->find('%d item');
        self::assertNotNull($entry);
        self::assertSame(['%d položka', '%d položky', '%d položek'], $entry['plurals']);
    }

    #[Test]
    public function reads_the_plural_forms_header(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');
        self::assertSame(
            'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
            $mo->pluralForms(),
        );
    }

    #[Test]
    public function distinguishes_a_missing_entry_from_an_empty_msgstr(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');

        $empty = $mo->find('Empty on purpose');
        self::assertNotNull($empty);
        self::assertSame('', $empty['msgstr']);

        self::assertNull($mo->find('Never in the catalogue'));
    }

    #[Test]
    public function missing_translation_is_not_a_reader_concern__lookup_returning_msgid_lives_in_the_catalog(): void
    {
        // MoFile itself has no fallback concept — find() returns null, and
        // it's TranslationCatalog::lookup() that degrades null to the msgid.
        // See TranslationCatalogTest for that behaviour.
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');
        self::assertNull($mo->find('Not in the catalogue at all'));
    }

    #[Test]
    public function parses_a_byte_swapped_big_endian_file(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/be_TEST.mo');
        $entry = $mo->find('Hello');
        self::assertNotNull($entry);
        self::assertSame('Ahoj', $entry['msgstr']);
    }

    #[Test]
    public function throws_on_an_unrecognised_magic_number(): void
    {
        $this->expectException(\RuntimeException::class);
        MoFile::fromString('not a mo file at all, way too short');
    }

    #[Test]
    public function entries_returns_every_record(): void
    {
        $mo = MoFile::fromFile(self::FIXTURES . '/cs_CZ.mo');
        $msgids = array_map(static fn(array $e) => $e['msgid'], $mo->entries());
        sort($msgids);
        self::assertSame(['%d item', 'Empty on purpose', 'Full name', 'Submit'], $msgids);
    }
}
