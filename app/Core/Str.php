<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function excerpt(string $html, int $length = 180, string $suffix = '…'): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length) . $suffix : $plain;
        }
        return strlen($plain) > $length ? substr($plain, 0, $length) . '...' : $plain;
    }

    public static function limit(?string $value, int $max): string
    {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function readingTime(string $html): int
    {
        $words = str_word_count(strip_tags($html)) ?: 0;
        return max(1, (int)ceil($words / 200));
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }
}
