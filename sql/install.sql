-- uniple checkout for EC-CUBE 2.x
-- 4 系 plugin の Doctrine migration (Version20260501100000) を MariaDB 互換 SQL で書き直し。
-- table prefix `plg_uniple_jpyc_` で 4 系と同じ命名 (= 4 系から知識流用しやすい)。

CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_config (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key         VARCHAR(255) NOT NULL DEFAULT '',
    webhook_secret  VARCHAR(255) NOT NULL DEFAULT '',
    merchant_label  VARCHAR(100) NOT NULL DEFAULT '',
    api_base_url    VARCHAR(255) NOT NULL DEFAULT 'https://uniple.io',
    mode            VARCHAR(16)  NOT NULL DEFAULT 'live',
    create_date     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_date     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_webhook_log (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key   VARCHAR(255) NOT NULL,
    event_type        VARCHAR(80)  NOT NULL DEFAULT '',
    session_id        VARCHAR(255) NULL DEFAULT NULL,
    signature_prefix  VARCHAR(32)  NULL DEFAULT NULL,
    received_at       DATETIME     NOT NULL,
    http_status       INT          NULL DEFAULT NULL,
    processed_at      DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_key (idempotency_key),
    INDEX ix_received_at (received_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_intent_mapping (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      BIGINT UNSIGNED NOT NULL,
    session_id    VARCHAR(255) NOT NULL,
    amount_jpyc   VARCHAR(32)  NOT NULL DEFAULT '0',
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    created_at    DATETIME     NOT NULL,
    completed_at  DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_id (session_id),
    INDEX ix_order_id (order_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_x402_quote (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id                VARCHAR(80)  NOT NULL,
    product_sku             VARCHAR(120) NOT NULL,
    product_id              INT UNSIGNED NOT NULL,
    product_class_id        INT UNSIGNED NOT NULL,
    quantity                INT UNSIGNED NOT NULL,
    product_subtotal_jpyc   VARCHAR(32)  NOT NULL,
    shipping_fee_jpyc       VARCHAR(32)  NOT NULL,
    discount_jpyc           VARCHAR(32)  NOT NULL DEFAULT '0',
    total_jpyc              VARCHAR(32)  NOT NULL,
    shipping_json           LONGTEXT     NOT NULL,
    deliv_id                INT UNSIGNED NULL DEFAULT NULL,
    deliv_name              VARCHAR(255) NOT NULL DEFAULT '',
    created_at              DATETIME     NOT NULL,
    expires_at              DATETIME     NOT NULL,
    used_at                 DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_x402_quote_id (quote_id),
    INDEX ix_x402_quote_product_sku (product_sku),
    INDEX ix_x402_quote_expires_at (expires_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_x402_product_setting (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    external_id   VARCHAR(120) NOT NULL,
    ai_enabled    SMALLINT NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_x402_product_setting_external_id (external_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB;

-- Singleton config row 初期化
INSERT IGNORE INTO plg_uniple_jpyc_config (id, api_key, webhook_secret, merchant_label, api_base_url, mode)
VALUES (1, '', '', '', 'https://uniple.io', 'live');
