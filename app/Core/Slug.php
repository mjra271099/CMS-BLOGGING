<?php
declare(strict_types=1);

namespace App\Core;

final class Slug
{
    public static function make(string $text, string $fallback = 'item'): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = substr($text, 0, 200);
        return $text !== '' ? $text : $fallback;
    }

    /**
     * Guarantee uniqueness within a table+scope.
     */
    public static function unique(string $table, string $slug, string $scopeColumn = 'blog_id', ?int $scopeId = null, int $excludeId = 0): string
    {
        $base = $slug !== '' ? $slug : 'item';
        $candidate = $base;
        $suffix = 2;
        $where = $scopeColumn !== '' ? " AND {$scopeColumn} = ?" : '';
        while (true) {
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE slug = ? AND id <> ?" . $where;
            $params = $scopeColumn !== '' ? [$candidate, $excludeId, $scopeId] : [$candidate, $excludeId];
            if ((int)Database::value($sql, $params) === 0) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix++;
        }
    }
}
