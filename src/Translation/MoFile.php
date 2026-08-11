<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Translation;

/**
 * @internal Implementation detail of {@see TranslationCatalog}. Not part of
 *           the public surface — consumers reach translations only through
 *           `TranslationCatalog::lookup()` / `entries()`.
 *
 * Pure-PHP reader for one compiled gettext `.mo` file. No dependency beyond
 * core PHP — see `docs/adr/` for why a `gettext/gettext` dependency was
 * rejected for this.
 *
 * Binary layout (GNU gettext `.mo` format, both byte orders):
 *
 *   [0]  magic            uint32   0x950412de (or byte-swapped 0xde120495)
 *   [4]  revision          uint32
 *   [8]  string_count      uint32
 *   [12] orig_table_offset uint32   -> string_count * (length, offset) pairs
 *   [16] trans_table_offset uint32  -> string_count * (length, offset) pairs
 *   [20] hash_table_size   uint32   (unused here — hash lookup is an
 *                                    optimisation over the linear tables,
 *                                    not a different data source)
 *   [24] hash_table_offset uint32
 */
final class MoFile
{
    /**
     * One entry per catalogue record, keyed by `context . "\x04" . msgid`
     * (or bare `msgid` when the entry carries no `msgctxt`) — the same key
     * shape gettext itself uses for `msgctxt`-qualified lookups.
     *
     * @var array<string, array{context: ?string, msgid: string, msgstr: string, plurals: string[]}>
     */
    private array $entries = [];

    /**
     * Raw `Plural-Forms` expression from the header entry (msgid ""), e.g.
     * `nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;` — null when
     * the catalogue carries no header entry or no such line, in which case
     * {@see PluralForms} falls back to the germanic default (n != 1).
     */
    private ?string $pluralForms = null;

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException(sprintf('MoFile: unable to read "%s"', $path));
        }
        return self::fromString($data, $path);
    }

    public static function fromString(string $data, string $sourceForErrors = '<string>'): self
    {
        if (strlen($data) < 28) {
            throw new \RuntimeException(sprintf('MoFile: "%s" is too small to be a .mo file', $sourceForErrors));
        }

        $magic = substr($data, 0, 4);
        // Little-endian .mo: magic 0x950412de stored least-significant byte
        // first -> on-disk bytes DE 12 04 95. Big-endian .mo: the same
        // magic stored most-significant byte first -> 95 04 12 DE. Every
        // multi-byte integer in the rest of the file follows the same order.
        if ($magic === "\xde\x12\x04\x95") {
            $fmt = 'V'; // unsigned long, little endian
        } elseif ($magic === "\x95\x04\x12\xde") {
            $fmt = 'N'; // unsigned long, big endian
        } else {
            throw new \RuntimeException(sprintf(
                'MoFile: "%s" has an unrecognised magic number — not a .mo file (or corrupt)',
                $sourceForErrors,
            ));
        }

        $readUint32 = static function (string $data, int $offset, string $fmt): int {
            $chunk = substr($data, $offset, 4);
            if (strlen($chunk) !== 4) {
                throw new \RuntimeException('MoFile: truncated file — unexpected end of data');
            }
            /** @var array{1:int} $unpacked */
            $unpacked = unpack($fmt, $chunk);
            return $unpacked[1];
        };

        $stringCount = $readUint32($data, 8, $fmt);
        $origTableOffset = $readUint32($data, 12, $fmt);
        $transTableOffset = $readUint32($data, 16, $fmt);

        $self = new self();

        for ($i = 0; $i < $stringCount; $i++) {
            $origLen = $readUint32($data, $origTableOffset + ($i * 8), $fmt);
            $origOff = $readUint32($data, $origTableOffset + ($i * 8) + 4, $fmt);
            $transLen = $readUint32($data, $transTableOffset + ($i * 8), $fmt);
            $transOff = $readUint32($data, $transTableOffset + ($i * 8) + 4, $fmt);

            $original = substr($data, $origOff, $origLen);
            $translated = substr($data, $transOff, $transLen);

            if ($original === '') {
                // The empty-msgid entry is the catalogue header — colon-
                // separated `Key: value\n` lines packed into the "translated"
                // slot. We only need Plural-Forms out of it; every other
                // header line (Content-Type, Language, …) is metadata this
                // reader doesn't act on.
                $self->pluralForms = self::extractPluralForms($translated);
                continue;
            }

            // msgctxt is packed into the original string as
            // "context\x04msgid" (or "context\x04msgid\0msgid_plural" for a
            // plural entry with a context) — split it off before splitting
            // the plural id.
            $context = null;
            if (str_contains($original, "\x04")) {
                [$context, $original] = explode("\x04", $original, 2);
            }

            // msgid_plural (if any) is \0-separated from msgid in the
            // original string; the translated string then carries N
            // \0-separated plural-form variants instead of one msgstr.
            $msgidParts = explode("\0", $original);
            $msgid = $msgidParts[0];
            $plurals = str_contains($translated, "\0") ? explode("\0", $translated) : [$translated];

            $key = $context !== null ? $context . "\x04" . $msgid : $msgid;
            $self->entries[$key] = [
                'context' => $context,
                'msgid' => $msgid,
                'msgstr' => $plurals[0],
                'plurals' => $plurals,
            ];
        }

        return $self;
    }

    private static function extractPluralForms(string $header): ?string
    {
        foreach (explode("\n", $header) as $line) {
            if (stripos($line, 'Plural-Forms:') === 0) {
                return trim(substr($line, strlen('Plural-Forms:')));
            }
        }
        return null;
    }

    /**
     * @return array{context: ?string, msgid: string, msgstr: string, plurals: string[]}|null
     */
    public function find(string $msgid, string $context = ''): ?array
    {
        $key = $context !== '' ? $context . "\x04" . $msgid : $msgid;
        return $this->entries[$key] ?? null;
    }

    /**
     * @return array<array{context: ?string, msgid: string, msgstr: string, plurals: string[]}>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function pluralForms(): ?string
    {
        return $this->pluralForms;
    }
}
