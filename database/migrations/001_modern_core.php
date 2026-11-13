<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Migrator;
use App\Core\Settings;
use App\Core\ThemeEngine;

/**
 * Migration 001 — modern multi-blog core.
 *
 * Upgrades the legacy single-blog schema in place without data loss:
 *  - creates blogs / blog_users / blog_domains / menus / redirects / revisions
 *  - adds blog scoping columns and soft-delete metadata to legacy tables
 *  - converts single-column unique keys into per-blog composite keys
 *  - moves global settings/widgets to the primary blog.
 */

return static function (): void {
    $pdo = Database::pdo();

    $has = static fn(string $table): bool => Database::tableExists($table);
    $column = static fn(string $table, string $col): bool => Database::columnExists($table, $col);

    // ---------------------------------------------------------------------
    // 1. users: profile + role columns
    // ---------------------------------------------------------------------
    if ($has('users')) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','editor','author','contributor') NOT NULL DEFAULT 'author'");
        foreach ([
            'display_name' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'avatar' => 'VARCHAR(500) NULL',
            'bio' => 'TEXT NULL',
            'website' => 'VARCHAR(255) NULL',
            'status' => "ENUM('active','suspended') NOT NULL DEFAULT 'active'",
            'last_login_at' => 'DATETIME NULL',
            'reset_token_hash' => 'CHAR(64) NULL',
            'reset_expires_at' => 'DATETIME NULL',
            'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ] as $name => $definition) {
            Migrator::addColumn('users', $name, $definition);
        }
    }

    // ---------------------------------------------------------------------
    // 2. primary blog (created from legacy settings when available)
    // ---------------------------------------------------------------------
    if (!$has('blogs')) {
        $pdo->exec(
            "CREATE TABLE blogs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_id BIGINT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                description TEXT NULL,
                domain VARCHAR(190) NOT NULL DEFAULT '',
                custom_domain VARCHAR(190) NULL UNIQUE,
                domain_verified_at DATETIME NULL,
                theme VARCHAR(120) NOT NULL DEFAULT 'aurora',
                logo VARCHAR(500) NULL,
                favicon VARCHAR(500) NULL,
                language VARCHAR(20) NOT NULL DEFAULT 'id',
                timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Jakarta',
                status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                settings LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX blogs_status_idx (status, is_primary),
                CONSTRAINT blogs_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if ((int)Database::value('SELECT COUNT(*) FROM blogs') === 0) {
        $legacy = $has('settings') ? Database::run('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR) : [];
        $ownerId = (int)(Database::value('SELECT id FROM users ORDER BY id LIMIT 1') ?: 0);
        $siteUrl = (string)($legacy['site_url'] ?? '');
        $host = $siteUrl !== '' ? (string)parse_url($siteUrl, PHP_URL_HOST) : '';
        Database::insert('blogs', [
            'owner_id' => $ownerId ?: null,
            'name' => (string)($legacy['site_name'] ?? 'My Blog'),
            'slug' => 'primary',
            'description' => (string)($legacy['site_description'] ?? ''),
            'domain' => $host,
            'theme' => 'aurora',
            'logo' => (string)($legacy['logo_url'] ?? ''),
            'favicon' => (string)($legacy['favicon_url'] ?? ''),
            'is_primary' => 1,
            'settings' => '{}',
        ]);
    }

    // ---------------------------------------------------------------------
    // 3. supporting tables (fresh creation, idempotent)
    // ---------------------------------------------------------------------
    $tables = [
        'blog_users' => "CREATE TABLE IF NOT EXISTS blog_users (
            blog_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('owner','admin','editor','author','contributor') NOT NULL DEFAULT 'author',
            is_owner TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blog_id, user_id),
            CONSTRAINT blog_users_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
            CONSTRAINT blog_users_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX blog_users_user_idx (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'blog_domains' => "CREATE TABLE IF NOT EXISTS blog_domains (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blog_id BIGINT UNSIGNED NOT NULL,
            domain VARCHAR(190) NOT NULL,
            type ENUM('custom','subdomain') NOT NULL DEFAULT 'custom',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            verified_at DATETIME NULL,
            ssl_status ENUM('unknown','pending','active','error') NOT NULL DEFAULT 'unknown',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY blog_domains_unique (domain),
            CONSTRAINT blog_domains_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'menus' => "CREATE TABLE IF NOT EXISTS menus (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blog_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            location VARCHAR(60) NOT NULL DEFAULT 'primary',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY menus_blog_slug_unique (blog_id, slug),
            CONSTRAINT menus_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'menu_items' => "CREATE TABLE IF NOT EXISTS menu_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            menu_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            label VARCHAR(190) NOT NULL,
            url VARCHAR(500) NOT NULL DEFAULT '',
            target VARCHAR(20) NOT NULL DEFAULT '_self',
            icon VARCHAR(60) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            CONSTRAINT menu_items_menu_fk FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
            INDEX menu_items_menu_order_idx (menu_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'themes' => "CREATE TABLE IF NOT EXISTS themes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
            author VARCHAR(190) NOT NULL DEFAULT '',
            description TEXT NULL,
            category VARCHAR(120) NOT NULL DEFAULT 'personal',
            screenshot VARCHAR(500) NULL,
            settings_json LONGTEXT NULL,
            layouts_json LONGTEXT NULL,
            source ENUM('bundled','uploaded') NOT NULL DEFAULT 'bundled',
            status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'theme_settings' => "CREATE TABLE IF NOT EXISTS theme_settings (
            blog_id BIGINT UNSIGNED NOT NULL,
            theme VARCHAR(120) NOT NULL,
            setting_key VARCHAR(120) NOT NULL,
            setting_value MEDIUMTEXT NOT NULL,
            PRIMARY KEY (blog_id, theme, setting_key),
            CONSTRAINT theme_settings_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'redirects' => "CREATE TABLE IF NOT EXISTS redirects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blog_id BIGINT UNSIGNED NOT NULL,
            from_path VARCHAR(500) NOT NULL,
            to_path VARCHAR(500) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY redirects_blog_from_unique (blog_id, from_path),
            CONSTRAINT redirects_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'newsletter_subscribers' => "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blog_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NOT NULL DEFAULT '',
            status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'confirmed',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY newsletter_blog_email_unique (blog_id, email),
            CONSTRAINT newsletter_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'revisions' => "CREATE TABLE IF NOT EXISTS revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blog_id BIGINT UNSIGNED NOT NULL,
            entity_type ENUM('post','page') NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            content_json LONGTEXT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT revisions_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
            CONSTRAINT revisions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX revisions_entity_idx (blog_id, entity_type, entity_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
            ip_hash CHAR(64) NOT NULL PRIMARY KEY,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // ---------------------------------------------------------------------
    // 4. add blog scoping + modern columns to content tables
    // ---------------------------------------------------------------------
    $blogCol = 'BIGINT UNSIGNED NOT NULL DEFAULT 1';
    foreach (['posts', 'pages', 'categories', 'tags', 'comments', 'media', 'widgets'] as $table) {
        if ($has($table) && !$column($table, 'blog_id')) {
            Migrator::addColumn($table, 'blog_id', $blogCol);
        }
    }
    if ($has('activity_log') && !$column('activity_log', 'blog_id')) {
        Migrator::addColumn('activity_log', 'blog_id', $blogCol);
    }

    $postColumns = [
        'content_json' => 'LONGTEXT NULL',
        'canonical_url' => 'VARCHAR(500) NULL',
        'og_image' => 'VARCHAR(500) NULL',
        'format' => "VARCHAR(40) NOT NULL DEFAULT 'standard'",
        'visibility' => "ENUM('public','private','password') NOT NULL DEFAULT 'public'",
        'access_password' => 'VARCHAR(255) NULL',
        'comment_status' => "ENUM('open','closed') NOT NULL DEFAULT 'open'",
        'robots' => 'VARCHAR(80) NULL',
        'sticky' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'reading_time' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
    ];
    foreach ($postColumns as $name => $definition) {
        if ($has('posts')) {
            Migrator::addColumn('posts', $name, $definition);
        }
        if ($has('pages') && in_array($name, ['content_json', 'canonical_url', 'og_image', 'visibility', 'access_password', 'deleted_at'], true)) {
            Migrator::addColumn('pages', $name, $definition);
        }
    }
    if ($has('pages')) {
        foreach ([
            'parent_id' => 'BIGINT UNSIGNED NULL',
            'thumbnail' => 'VARCHAR(500) NULL',
            'template' => "VARCHAR(120) NOT NULL DEFAULT 'default'",
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'custom_css' => 'MEDIUMTEXT NULL',
            'custom_script' => 'MEDIUMTEXT NULL',
            'robots' => 'VARCHAR(80) NULL',
            'excerpt' => 'TEXT NULL',
        ] as $name => $definition) {
            Migrator::addColumn('pages', $name, $definition);
        }
        // legacy status ENUM gained 'trash'
        $pdo->exec("ALTER TABLE pages MODIFY COLUMN status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft'");
    }
    if ($has('posts')) {
        $pdo->exec("ALTER TABLE posts MODIFY COLUMN status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft'");
    }
    if ($has('comments')) {
        $pdo->exec("ALTER TABLE comments MODIFY COLUMN status ENUM('pending','approved','spam','trash') NOT NULL DEFAULT 'pending'");
        foreach ([
            'parent_id' => 'BIGINT UNSIGNED NULL',
            'user_id' => 'BIGINT UNSIGNED NULL',
            'url' => 'VARCHAR(255) NULL',
            'ip_hash' => 'CHAR(64) NULL',
            'user_agent' => 'VARCHAR(255) NULL',
            'approved_at' => 'DATETIME NULL',
        ] as $name => $definition) {
            Migrator::addColumn('comments', $name, $definition);
        }
    }
    if ($has('categories')) {
        foreach ([
            'parent_id' => 'BIGINT UNSIGNED NULL',
            'description' => 'TEXT NULL',
            'image' => 'VARCHAR(500) NULL',
            'color' => 'VARCHAR(20) NULL',
            'meta_title' => 'VARCHAR(255) NULL',
            'meta_description' => 'VARCHAR(320) NULL',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
        ] as $name => $definition) {
            Migrator::addColumn('categories', $name, $definition);
        }
    }
    if ($has('tags')) {
        Migrator::addColumn('tags', 'description', 'TEXT NULL');
    }
    if ($has('media')) {
        foreach ([
            'thumb_path' => 'VARCHAR(500) NULL',
            'webp_path' => 'VARCHAR(500) NULL',
            'extension' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'width' => 'INT UNSIGNED NULL',
            'height' => 'INT UNSIGNED NULL',
            'alt' => 'VARCHAR(255) NULL',
            'caption' => 'TEXT NULL',
            'folder' => "VARCHAR(120) NOT NULL DEFAULT ''",
            'checksum' => 'CHAR(64) NULL',
            'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ] as $name => $definition) {
            Migrator::addColumn('media', $name, $definition);
        }
    }
    if ($has('widgets')) {
        foreach ([
            'visibility' => "VARCHAR(40) NOT NULL DEFAULT 'all'",
            'pages' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'devices' => "VARCHAR(60) NOT NULL DEFAULT 'all'",
            'roles' => "VARCHAR(120) NOT NULL DEFAULT 'all'",
            'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ] as $name => $definition) {
            Migrator::addColumn('widgets', $name, $definition);
        }
    }
    if ($has('theme_revisions')) {
        Migrator::addColumn('theme_revisions', 'blog_id', $blogCol);
        Migrator::addColumn('theme_revisions', 'theme', "VARCHAR(120) NOT NULL DEFAULT ''");
        Migrator::addColumn('theme_revisions', 'settings_json', 'LONGTEXT NULL');
    }

    // ---------------------------------------------------------------------
    // 5. rebuild single-column unique keys as per-blog composite keys
    // ---------------------------------------------------------------------
    $dropUnique = static function (string $table, string $index): void {
        try {
            $pdo = Database::pdo();
            $pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
        } catch (\Throwable) {
            // index already gone
        }
    };
    foreach ([
        ['posts', 'slug', 'posts_blog_slug_unique'],
        ['pages', 'slug', 'pages_blog_slug_unique'],
        ['categories', 'slug', 'categories_blog_slug_unique'],
        ['tags', 'slug', 'tags_blog_slug_unique'],
    ] as [$table, , $newIndex]) {
        if ($has($table) && !Database::indexExists($table, $newIndex)) {
            $dropUnique($table, $table . '_slug_unique');
            $dropUnique($table, 'slug');
            try {
                $pdo->exec(sprintf('ALTER TABLE `%s` ADD UNIQUE KEY `%s` (blog_id, slug)', $table, $newIndex));
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('composite unique failed', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
    }
    if ($has('widgets') && !Database::indexExists('widgets', 'widgets_blog_key_unique')) {
        $dropUnique('widgets', 'widget_key');
        try {
            $pdo->exec('ALTER TABLE widgets ADD UNIQUE KEY widgets_blog_key_unique (blog_id, widget_key)');
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('widget unique failed', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------
    // 6. settings: migrate to (blog_id, setting_key) primary key
    // ---------------------------------------------------------------------
    if ($has('settings') && !$column('settings', 'blog_id')) {
        $legacy = Database::run('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $pdo->exec('RENAME TABLE settings TO settings_legacy_backup');
        $pdo->exec(
            "CREATE TABLE settings (
                blog_id BIGINT UNSIGNED NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value MEDIUMTEXT NOT NULL,
                PRIMARY KEY (blog_id, setting_key),
                CONSTRAINT settings_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $stmt = $pdo->prepare('INSERT INTO settings (blog_id, setting_key, setting_value) VALUES (1, ?, ?)');
        foreach ($legacy as $key => $value) {
            $stmt->execute([(string)$key, (string)$value]);
        }
    }

    // ---------------------------------------------------------------------
    // 7. default memberships, menus and redirects for migrated blogs
    // ---------------------------------------------------------------------
    $owner = Database::fetch('SELECT id FROM users WHERE role = "admin" ORDER BY id LIMIT 1');
    foreach (Database::fetchAll('SELECT id, owner_id FROM blogs') as $blog) {
        $ownerId = (int)($blog['owner_id'] ?: ($owner['id'] ?? 0));
        if ($ownerId > 0) {
            Database::run(
                'INSERT INTO blog_users (blog_id, user_id, role, is_owner) VALUES (?, ?, "owner", 1)
                 ON DUPLICATE KEY UPDATE role = "owner", is_owner = 1',
                [(int)$blog['id'], $ownerId]
            );
            Database::run('UPDATE blogs SET owner_id = ? WHERE id = ? AND owner_id IS NULL', [$ownerId, (int)$blog['id']]);
        }
    }
    if ((int)Database::value('SELECT COUNT(*) FROM menus') === 0) {
        foreach (Database::fetchAll('SELECT id FROM blogs') as $blog) {
            Database::insert('menus', [
                'blog_id' => (int)$blog['id'],
                'name' => 'Menu Utama',
                'slug' => 'primary',
                'location' => 'primary',
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // 8. legacy widget sections are kept, new themes add their own areas
    // ---------------------------------------------------------------------
    Database::run('UPDATE widgets SET blog_id = 1 WHERE blog_id = 0');

    // ---------------------------------------------------------------------
    // 9. register bundled themes and activate the default one
    // ---------------------------------------------------------------------
    ThemeEngine::syncBundledThemes();
    $currentTheme = (string)(Database::value('SELECT setting_value FROM settings WHERE blog_id = 1 AND setting_key = "theme"') ?: '');
    if ($currentTheme === '' || !is_dir(CMS_ROOT . '/themes/' . $currentTheme)) {
        Settings::set('theme', 'aurora');
        Database::run('UPDATE blogs SET theme = "aurora" WHERE id = 1');
    }

    // ---------------------------------------------------------------------
    // 10. store settings defaults that older installs never wrote
    // ---------------------------------------------------------------------
    $defaults = Settings::defaults();
    $existing = Database::run('SELECT setting_key FROM settings WHERE blog_id = 1')->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($defaults as $key => $value) {
        if (!in_array($key, $existing, true)) {
            Database::run('INSERT INTO settings (blog_id, setting_key, setting_value) VALUES (1, ?, ?)', [$key, (string)$value]);
        }
    }
};
