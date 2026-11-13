<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Front-end translations (Indonesian + English).
 *
 * The active language comes from the "language" setting (falling back to the
 * blog's language and then Indonesian). Templates call __() with a stable key;
 * missing keys fall back to Indonesian and finally to the key itself, so a new
 * string never breaks a page.
 *
 * The global __() function lives in app/Core/helpers.php because that is the
 * file loaded unconditionally by the bootstrap (PHP needs top-level functions
 * to be declared in the global namespace before they can be called).
 */
final class Lang
{
    public const SUPPORTED = ['id' => 'Bahasa Indonesia', 'en' => 'English'];

    private static ?string $locale = null;
    /** @var array<string, array<string, string>>|null */
    private static ?array $catalogs = null;

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }
        // 1) explicit visitor choice (language switcher cookie)
        $cookie = strtolower((string)($_COOKIE['site_language'] ?? ''));
        if (isset(self::SUPPORTED[$cookie])) {
            return self::$locale = $cookie;
        }
        // 2) blog/site setting
        $locale = strtolower(Settings::get('language', 'id'));
        $locale = substr($locale, 0, 2);
        if (!isset(self::SUPPORTED[$locale])) {
            $locale = 'id';
        }
        return self::$locale = $locale;
    }

    /**
     * Persist a visitor language choice. Returns the sanitised locale.
     */
    public static function setLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 2));
        if (!isset(self::SUPPORTED[$locale])) {
            $locale = 'id';
        }
        self::$locale = $locale;
        if (!headers_sent()) {
            setcookie('site_language', $locale, [
                'expires' => time() + 31536000,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
            $_COOKIE['site_language'] = $locale;
        }
        return $locale;
    }

    public static function reset(): void
    {
        self::$locale = null;
        self::$catalogs = null;
    }

    public static function isEnglish(): bool
    {
        return self::locale() === 'en';
    }

    /**
     * Translate a key, with optional :placeholders substitution.
     *
     * @param array<string, string|int> $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $locale = self::locale();
        $catalog = self::catalog($locale);
        $text = $catalog[$key] ?? self::catalog('id')[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string)$value, $text);
        }
        return $text;
    }

    /**
     * Locale used for <html lang> and hreflang.
     */
    public static function htmlLocale(): string
    {
        return self::isEnglish() ? 'en' : 'id';
    }

    public static function ogLocale(): string
    {
        return self::isEnglish() ? 'en_US' : 'id_ID';
    }

    /**
     * @return array<string, string>
     */
    public static function catalog(string $locale): array
    {
        if (self::$catalogs === null) {
            self::$catalogs = [];
        }
        if (!isset(self::$catalogs[$locale])) {
            $file = dirname(__DIR__) . '/Lang/' . preg_replace('/[^a-z]/', '', $locale) . '.php';
            $messages = is_file($file) ? require $file : [];
            self::$catalogs[$locale] = is_array($messages) ? $messages : [];
        }
        return self::$catalogs[$locale];
    }
}
