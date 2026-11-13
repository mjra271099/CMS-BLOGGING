<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

final class Menu
{
    public static function forBlog(?int $blogId = null): array
    {
        $blogId ??= Context::blogId();
        return Database::fetchAll('SELECT * FROM menus WHERE blog_id = ? ORDER BY id', [$blogId]);
    }

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM menus WHERE id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function create(string $name, string $location = 'primary'): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new ValidationException('Nama menu wajib diisi.');
        }
        $slug = \App\Core\Slug::unique('menus', \App\Core\Slug::make($name), 'blog_id', Context::blogId());
        return Database::insert('menus', [
            'blog_id' => Context::blogId(),
            'name' => $name,
            'slug' => $slug,
            'location' => $location,
        ]);
    }

    public static function items(int $menuId): array
    {
        return Database::fetchAll('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id', [$menuId]);
    }

    public static function tree(int $menuId): array
    {
        $items = self::items($menuId);
        $build = static function (array $rows, int $parent = 0) use (&$build): array {
            $out = [];
            foreach ($rows as $row) {
                if ((int)($row['parent_id'] ?? 0) === $parent) {
                    $row['children'] = $build($rows, (int)$row['id']);
                    $out[] = $row;
                }
            }
            return $out;
        };
        return $build($items);
    }

    public static function addItem(int $menuId, array $data): int
    {
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            throw new ValidationException('Label menu wajib diisi.');
        }
        $url = (string)($data['url'] ?? '');
        if ($url !== '' && !valid_asset_url($url)) {
            throw new ValidationException('URL menu tidak valid.');
        }
        $next = (int)Database::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM menu_items WHERE menu_id = ?', [$menuId]);
        return Database::insert('menu_items', [
            'menu_id' => $menuId,
            'parent_id' => (int)($data['parent_id'] ?? 0) ?: null,
            'label' => $label,
            'url' => $url,
            'target' => ($data['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'icon' => (string)($data['icon'] ?? ''),
            'sort_order' => (int)($data['sort_order'] ?? $next),
        ]);
    }

    public static function updateItem(int $id, array $data): void
    {
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            throw new ValidationException('Label menu wajib diisi.');
        }
        Database::update('menu_items', [
            'label' => $label,
            'url' => (string)($data['url'] ?? ''),
            'target' => ($data['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'parent_id' => (int)($data['parent_id'] ?? 0) ?: null,
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ], 'id = ?', [$id]);
    }

    public static function deleteItem(int $id): void
    {
        $menuId = (int)Database::value('SELECT menu_id FROM menu_items WHERE id = ?', [$id]);
        Database::delete('menu_items', 'id = ?', [$id]);
        Database::delete('menu_items', 'parent_id = ? AND menu_id = ?', [$id, $menuId]);
    }

    public static function reorder(array $items): void
    {
        foreach ($items as $index => $item) {
            Database::update('menu_items', [
                'sort_order' => (int)($item['order'] ?? $index),
                'parent_id' => (int)($item['parent'] ?? 0) ?: null,
            ], 'id = ?', [(int)($item['id'] ?? 0)]);
        }
    }

    public static function delete(int $id): void
    {
        Database::delete('menus', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }
}
