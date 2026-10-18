CREATE TABLE robotmc_status (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_id BIGINT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    online TINYINT(1) NOT NULL,
    ping_ms INT UNSIGNED NULL,
    players_online INT UNSIGNED NULL,
    players_max INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_robotmc_status_checked_at (checked_at),
    KEY idx_robotmc_status_server_checked_at (server_id, checked_at),
    CONSTRAINT fk_robotmc_status_server FOREIGN KEY (server_id) REFERENCES robotmc_server (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
