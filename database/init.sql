CREATE TABLE IF NOT EXISTS sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    feed_url VARCHAR(2048) NOT NULL,
    feed_url_hash CHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    etag VARCHAR(255) NULL,
    last_modified VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sources_feed_url_hash (feed_url_hash),
    KEY idx_sources_active (is_active)
);

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_slug (slug)
);

CREATE TABLE IF NOT EXISTS news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(512) NOT NULL,
    title VARCHAR(1024) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    summary MEDIUMTEXT NULL,
    published_at DATETIME NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_news_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_news_source_external (source_id, external_id),
    KEY idx_news_published (published_at, id),
    KEY idx_news_source_published (source_id, published_at)
);

CREATE TABLE IF NOT EXISTS news_categories (
    news_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (news_id, category_id),
    CONSTRAINT fk_news_categories_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    KEY idx_news_categories_category_news (category_id, news_id)
);

CREATE TABLE IF NOT EXISTS import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    status ENUM('running', 'success', 'not_modified', 'failed') NOT NULL,
    items_seen INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    CONSTRAINT fk_import_runs_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE RESTRICT,
    KEY idx_import_runs_source_started (source_id, started_at)
);
