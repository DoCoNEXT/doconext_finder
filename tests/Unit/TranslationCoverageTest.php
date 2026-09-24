<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every string the app shows has a Dutch translation, and the two catalogue
 * files say the same thing.
 *
 * Ported from Core, which is where the reasoning below was learned. Finder
 * shipped with no l10n/ at all — 237 strings, every one of them English in a
 * Dutch instance, and nothing anywhere said so.
 *
 * A missing key is not an error anywhere: `t()` falls back to the English
 * source, so an untranslated string looks exactly like a translated one to
 * everything except a Dutch reader. Nothing in the build notices, which is how
 * three hundred of them accumulated. This test is the thing that notices.
 *
 * The ceiling is a ratchet, like eslint's `--max-warnings`. It is zero now;
 * should a string ever have to ship untranslated, raise it deliberately and
 * write down why, rather than letting the number drift.
 *
 * `l10n/<lang>.json` is read by PHP and `l10n/<lang>.js` by the browser. They
 * are separate files holding the same catalogue, so they are compared here:
 * a key translated in one and not the other is translated for half the app.
 */
final class TranslationCoverageTest extends TestCase
{
    /**
     * How many source strings may be missing from the Dutch catalogue.
     * Lower it when it drops; raising it needs a reason in the commit message.
     */
    private const MAX_UNTRANSLATED = 0;

    private const LANGUAGES = ['nl'];

    public function testEverySourceStringIsInTheDutchCatalogue(): void
    {
        $catalogue = array_keys($this->catalogue('nl'));
        $missing   = array_values(array_diff($this->sourceStrings(), $catalogue));
        sort($missing);

        $this->assertLessThanOrEqual(
            self::MAX_UNTRANSLATED,
            count($missing),
            sprintf(
                "%d source strings have no Dutch translation, the ceiling is %d.\n"
                . "A Dutch user sees these in English. Add them to l10n/nl.json and\n"
                . "regenerate l10n/nl.js with `make l10n-js`.\n\nFirst few:\n  - %s",
                count($missing),
                self::MAX_UNTRANSLATED,
                implode("\n  - ", array_slice($missing, 0, 15)),
            ),
        );
    }

    /**
     * The browser catalogue is generated from the PHP one, so a difference means
     * somebody edited one by hand and the other is now stale.
     */
    public function testTheTwoCatalogueFilesHoldTheSameThing(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $json = $this->catalogue($lang);
            $js   = $this->jsCatalogue($lang);

            $this->assertSame(
                $json,
                $js,
                "l10n/{$lang}.json and l10n/{$lang}.js differ. Regenerate the .js "
                . 'from the .json with `make l10n-js`.',
            );
        }
    }

    /**
     * A plural is looked up under `_singular_::_plural_`; that is the identifier
     * `translatePlural()` builds and the only one it tries. An array parked under
     * the bare singular is never found, and the string silently stays English.
     */
    public function testEveryPluralEntryUsesTheKeyThatIsLookedUp(): void
    {
        foreach (self::LANGUAGES as $lang) {
            foreach ($this->catalogue($lang) as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $this->assertMatchesRegularExpression(
                    '/^_.*_::_.*_$/s',
                    $key,
                    "l10n/{$lang}.json: \"{$key}\" holds a plural but is not keyed "
                    . '"_singular_::_plural_", so it is never looked up.',
                );
            }
        }
    }

    // ── Reading the catalogue ───────────────────────────────────────────────

    /** @return array<string,string|string[]> */
    private function catalogue(string $lang): array
    {
        $decoded = json_decode((string) file_get_contents($this->root() . "/l10n/{$lang}.json"), true);
        self::assertIsArray($decoded, "l10n/{$lang}.json is not valid JSON");

        return $decoded['translations'];
    }

    /**
     * Reads the object literal out of `OC.L10N.register(app, {...}, plural)`
     * without evaluating it: the braces are balanced, so the catalogue runs from
     * the first `{` to the matching `}`.
     *
     * @return array<string,string|string[]>
     */
    private function jsCatalogue(string $lang): array
    {
        $source = (string) file_get_contents($this->root() . "/l10n/{$lang}.js");
        $start  = strpos($source, '{');
        self::assertNotFalse($start, "l10n/{$lang}.js has no catalogue object");

        $depth    = 0;
        $inString = false;
        $escaped  = false;
        for ($i = $start, $n = strlen($source); $i < $n; $i++) {
            $c = $source[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($c === '\\') {
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                $decoded = json_decode(substr($source, $start, $i - $start + 1), true);
                self::assertIsArray($decoded, "l10n/{$lang}.js catalogue is not valid JSON");

                return $decoded;
            }
        }

        self::fail("l10n/{$lang}.js catalogue object is not closed");
    }

    // ── Reading the source ──────────────────────────────────────────────────

    /**
     * The keys the app asks for: `t('…')` and `n('…', '…')` in src/, and
     * `->t('…')` / `->n('…', '…')` in lib/. A plural contributes its combined
     * identifier and neither half on its own.
     *
     * Strings built at runtime — a template literal, a variable — cannot be
     * extracted and are not counted; they cannot be translated either, which is
     * its own reason not to write them.
     *
     * @return string[]
     */
    private function sourceStrings(): array
    {
        // One argument: a quoted literal, or several joined with PHP's `.` —
        // a sentence long enough to wrap is written that way, and the string the
        // app asks for is the joined result.
        $q = "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
        $a = "({$q}(?:\\s*\\.\\s*{$q})*)";

        $found   = [];
        $plurals = [];
        foreach ([
            // Finder's strings live entirely in src/: nothing in lib/ calls
            // IL10N. If that changes, add the lib/php row Core carries.
            ['src', ['vue', 'ts'],  "/(?<![\\w.])t\\(\\s*{$a}\\s*[,)]/s",  "/(?<![\\w.])n\\(\\s*{$a}\\s*,\\s*{$a}/s"],
        ] as [$dir, $extensions, $singlePattern, $pluralPattern]) {
            foreach ($this->files($dir, $extensions) as $file) {
                $code = (string) file_get_contents($file);

                preg_match_all($pluralPattern, $code, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $singular = $this->literal($m[1]);
                    $plural   = $this->literal($m[2]);
                    $plurals["_{$singular}_::_{$plural}_"] = [$singular, $plural];
                }

                preg_match_all($singlePattern, $code, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $found[$this->literal($m[1])] = true;
                }
            }
        }

        foreach ($plurals as $identifier => $halves) {
            foreach ($halves as $half) {
                unset($found[$half]);
            }
            $found[$identifier] = true;
        }

        return array_keys($found);
    }

    /**
     * The string an argument evaluates to: one quoted literal, or several
     * concatenated, with the quoting undone.
     */
    private function literal(string $argument): string
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $argument, $parts, PREG_SET_ORDER);

        $value = '';
        foreach ($parts as $part) {
            $value .= ($part[1] ?? '') !== '' || ($part[2] ?? '') === ''
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $part[1] ?? '')
                : str_replace(['\\"', '\\\\'], ['"', '\\'], $part[2] ?? '');
        }

        return $value;
    }

    /** @return string[] */
    private function files(string $directory, array $extensions): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
