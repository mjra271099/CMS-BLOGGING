-- =============================================================================
--  CMS DATABASE SCHEMA (v2) — multi-blog, multi-user, theme + widget engine
--  Fresh installations. Existing installations are upgraded by
--  database/migrations/001_modern_core.php
-- =============================================================================

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    role ENUM('admin','editor','author','contributor') NOT NULL DEFAULT 'author',
    avatar VARCHAR(500) NULL,
    bio TEXT NULL,
    website VARCHAR(255) NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    reset_token_hash CHAR(64) NULL,
    reset_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX users_role_idx (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blogs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_users (
    blog_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','admin','editor','author','contributor') NOT NULL DEFAULT 'author',
    is_owner TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blog_id, user_id),
    CONSTRAINT blog_users_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    CONSTRAINT blog_users_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX blog_users_user_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_domains (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    excerpt TEXT NULL,
    content_json LONGTEXT NULL,
    thumbnail VARCHAR(500) NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(320) NULL,
    canonical_url VARCHAR(500) NULL,
    og_image VARCHAR(500) NULL,
    status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft',
    format VARCHAR(40) NOT NULL DEFAULT 'standard',
    visibility ENUM('public','private','password') NOT NULL DEFAULT 'public',
    access_password VARCHAR(255) NULL,
    comment_status ENUM('open','closed') NOT NULL DEFAULT 'open',
    robots VARCHAR(80) NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    sticky TINYINT(1) NOT NULL DEFAULT 0,
    reading_time INT UNSIGNED NOT NULL DEFAULT 0,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY posts_blog_slug_unique (blog_id, slug),
    CONSTRAINT posts_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    CONSTRAINT posts_author_fk FOREIGN KEY (author_id) REFERENCES users(id),
    INDEX posts_status_date_idx (blog_id, status, published_at),
    INDEX posts_popular_idx (blog_id, status, views),
    INDEX posts_featured_idx (blog_id, featured, published_at),
    INDEX posts_sticky_idx (blog_id, sticky, published_at),
    INDEX posts_author_idx (blog_id, author_id),
    FULLTEXT INDEX posts_search_idx (title, excerpt, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    excerpt TEXT NULL,
    content_json LONGTEXT NULL,
    thumbnail VARCHAR(500) NULL,
    template VARCHAR(120) NOT NULL DEFAULT 'default',
    visibility ENUM('public','private','password') NOT NULL DEFAULT 'public',
    access_password VARCHAR(255) NULL,
    status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(320) NULL,
    canonical_url VARCHAR(500) NULL,
    og_image VARCHAR(500) NULL,
    robots VARCHAR(80) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    custom_css MEDIUMTEXT NULL,
    custom_script MEDIUMTEXT NULL,
    published_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY pages_blog_slug_unique (blog_id, slug),
    CONSTRAINT pages_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    CONSTRAINT pages_author_fk FOREIGN KEY (author_id) REFERENCES users(id),
    INDEX pages_status_date_idx (blog_id, status, published_at),
    INDEX pages_sort_idx (blog_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    image VARCHAR(500) NULL,
    color VARCHAR(20) NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(320) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY categories_blog_slug_unique (blog_id, slug),
    CONSTRAINT categories_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    INDEX categories_parent_idx (blog_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY tags_blog_slug_unique (blog_id, slug),
    CONSTRAINT tags_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_categories (
    post_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, category_id),
    CONSTRAINT post_categories_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT post_categories_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_tags (
    post_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    CONSTRAINT post_tags_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT post_tags_tag_fk FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revisions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    author VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    url VARCHAR(255) NULL,
    body TEXT NOT NULL,
    status ENUM('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT comments_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    CONSTRAINT comments_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT comments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX comments_moderation_idx (blog_id, status, created_at),
    INDEX comments_post_status_idx (post_id, status),
    INDEX comments_parent_idx (post_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    thumb_path VARCHAR(500) NULL,
    webp_path VARCHAR(500) NULL,
    mime_type VARCHAR(100) NOT NULL,
    extension VARCHAR(20) NOT NULL DEFAULT '',
    file_size BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    alt VARCHAR(255) NULL,
    caption TEXT NULL,
    folder VARCHAR(120) NOT NULL DEFAULT '',
    checksum CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT media_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    CONSTRAINT media_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX media_created_idx (blog_id, created_at),
    INDEX media_type_idx (blog_id, mime_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    widget_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    type VARCHAR(60) NOT NULL,
    section_id VARCHAR(80) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    config_json LONGTEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    visibility VARCHAR(40) NOT NULL DEFAULT 'all',
    pages VARCHAR(255) NOT NULL DEFAULT '',
    devices VARCHAR(60) NOT NULL DEFAULT 'all',
    roles VARCHAR(120) NOT NULL DEFAULT 'all',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY widgets_blog_key_unique (blog_id, widget_key),
    CONSTRAINT widgets_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    INDEX widgets_section_order_idx (blog_id, section_id, enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    location VARCHAR(60) NOT NULL DEFAULT 'primary',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY menus_blog_slug_unique (blog_id, slug),
    CONSTRAINT menus_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS themes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theme_settings (
    blog_id BIGINT UNSIGNED NOT NULL,
    theme VARCHAR(120) NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value MEDIUMTEXT NOT NULL,
    PRIMARY KEY (blog_id, theme, setting_key),
    CONSTRAINT theme_settings_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theme_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    theme VARCHAR(120) NOT NULL DEFAULT '',
    css MEDIUMTEXT NOT NULL,
    template_html MEDIUMTEXT NOT NULL,
    header_code MEDIUMTEXT NOT NULL,
    footer_code MEDIUMTEXT NOT NULL,
    settings_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT theme_revisions_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
    INDEX theme_revisions_blog_idx (blog_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    blog_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value MEDIUMTEXT NOT NULL,
    PRIMARY KEY (blog_id, setting_key),
    CONSTRAINT settings_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redirects (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL DEFAULT '',
    status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'confirmed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY newsletter_blog_email_unique (blog_id, email),
    CONSTRAINT newsletter_blog_fk FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(190) NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT activity_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX activity_created_idx (created_at),
    INDEX activity_blog_idx (blog_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip_hash CHAR(64) NOT NULL PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
