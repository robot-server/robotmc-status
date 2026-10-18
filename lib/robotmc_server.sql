CREATE TABLE robotmc_server (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    host VARCHAR(255) NOT NULL,
    port INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    favicon TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_robotmc_server_host_port (host, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
