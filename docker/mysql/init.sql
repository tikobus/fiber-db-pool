-- AmpMySQL RPC 演示库初始化（docker-compose mysql 首次启动执行）
-- 表结构必须与 webman-gateway/app/rpc/SqlTemplates.php 中的模板列对齐

CREATE TABLE IF NOT EXISTS rpc_test_user (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(64)     NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO rpc_test_user (name)
VALUES ('alice'), ('bob'), ('carol');
