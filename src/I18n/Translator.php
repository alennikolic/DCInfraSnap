<?php
// src/I18n/Translator.php
//
// Interface strings, by language.
//
// How it works: every language is one file, lang/<code>.php, returning a flat
// array of key => text. Keys are written in English and read like a path:
// nav.overview, login.password, common.save.
//
// English is the source language and the fallback. A key missing from the
// chosen language is served from English rather than from a blank space, so
// a half-translated language is still a usable interface.
//
// Deliberately left out: plural forms. Russian needs three, Serbian three,
// German two, and a general solution is a small library on its own. The
// interface is instead written so no string depends on a count — "Virtual
// machines: 5", not "5 virtual machines". If that ever stops being enough,
// this is the file where a CLDR plural rule goes.

declare(strict_types=1);

namespace DcInfraSnap\I18n;

use RuntimeException;

final class Translator
{
    /** Languages the interface is planned for. Only those with a file are offered. */
    public const PLANNED = ['en', 'de', 'sr', 'ru'];

    /** @var array<string, string> */
    private array $messages;

    /** @var array<string, string> */
    private array $fallbackMessages;

    /** @var list<string> keys asked for but missing everywhere; used by lang:check */
    private array $missing = [];

    public function __construct(
        private readonly string $dir,
        private readonly string $locale,
        private readonly string $fallbackLocale = 'en',
    ) {
        $this->messages = self::load($dir, $locale);

        $this->fallbackMessages = $locale === $fallbackLocale
            ? $this->messages
            : self::load($dir, $fallbackLocale);

        if ($this->fallbackMessages === []) {
            throw new RuntimeException(
                "The fallback language file is missing or empty: {$dir}/{$fallbackLocale}.php"
            );
        }
    }

    // -----------------------------------------------------------------
    // Translating
    // -----------------------------------------------------------------

    /**
     * Translate a key.
     *
     * Placeholders are written as :name and replaced from $params:
     *   $t->t('runs.found', ['count' => 12])
     *
     * An unknown key comes back as the key itself. That is louder than an
     * empty string in the layout and tells you exactly what to add.
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key]
            ?? $this->fallbackMessages[$key]
            ?? null;

        if ($text === null) {
            $this->missing[$key] = $key;

            return $key;
        }

        if ($params === []) {
            return $text;
        }

        $replacements = [];

        foreach ($params as $name => $value) {
            $replacements[':' . $name] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Keys that were asked for and found nowhere, in this request.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values($this->missing);
    }

    // -----------------------------------------------------------------
    // Available languages
    // -----------------------------------------------------------------

    /**
     * Languages that actually have a file, as code => native name.
     *
     * The native name comes from the key language.name inside each file, so
     * German is offered as "Deutsch" and not as "German".
     *
     * @return array<string, string>
     */
    public static function available(string $dir): array
    {
        $found = [];

        foreach (self::PLANNED as $code) {
            $messages = self::load($dir, $code);

            if ($messages === []) {
                continue;
            }

            $found[$code] = $messages['language.name'] ?? $code;
        }

        return $found;
    }

    /**
     * Decide which language to use.
     *
     * In order: what the visitor asked for, then what the configuration says,
     * then the fallback. A code that has no file is ignored rather than
     * producing an error — a bad ?lang= in a URL should not break the page.
     */
    public static function resolve(
        string $dir,
        ?string $requested,
        string $configured,
        string $fallback = 'en',
    ): string {
        $available = self::available($dir);

        foreach ([$requested, $configured, $fallback] as $candidate) {
            if ($candidate !== null && isset($available[$candidate])) {
                return $candidate;
            }
        }

        return $fallback;
    }

    // -----------------------------------------------------------------
    // Loading
    // -----------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private static function load(string $dir, string $locale): array
    {
        // Guard the path: the locale can come from a query string.
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
            return [];
        }

        $file = $dir . '/' . $locale . '.php';

        if (!is_file($file)) {
            return [];
        }

        $messages = require $file;

        return is_array($messages) ? $messages : [];
    }
}
