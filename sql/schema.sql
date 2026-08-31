-- Serverovervågning — database schema (MariaDB)
-- Run: mysql -u <user> -p <database> < sql/schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    initials        VARCHAR(3)   NOT NULL,
    role            ENUM('admin', 'operator') NOT NULL DEFAULT 'operator',
    failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS servers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hostname        VARCHAR(100) NOT NULL UNIQUE,
    ip_address      VARCHAR(45)  NOT NULL,
    cpu_model       VARCHAR(150) NOT NULL DEFAULT '',
    cpu_cores       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    os_name         VARCHAR(100) NOT NULL DEFAULT '',
    os_family       ENUM('linux', 'windows', 'other') NOT NULL DEFAULT 'linux',
    total_disk_gb   INT UNSIGNED NOT NULL DEFAULT 0,
    total_ram_gb    INT UNSIGNED NOT NULL DEFAULT 0,
    api_key_hash    VARCHAR(255) NOT NULL,
    tags            VARCHAR(255) NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per telemetry sample received from a host's agent.
CREATE TABLE IF NOT EXISTS server_metrics (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id       INT UNSIGNED NOT NULL,
    recorded_at     DATETIME NOT NULL,
    cpu_pct         DECIMAL(5,2) NOT NULL,
    cpu_temp_c      DECIMAL(5,2) NOT NULL,
    mem_pct         DECIMAL(5,2) NOT NULL,
    mem_used_gb     DECIMAL(8,2) NOT NULL,
    disk_used_pct   DECIMAL(5,2) NOT NULL,
    net_in_mbs      DECIMAL(8,2) NOT NULL DEFAULT 0,
    net_out_mbs     DECIMAL(8,2) NOT NULL DEFAULT 0,
    disk_io_mbs     DECIMAL(8,2) NOT NULL DEFAULT 0,
    fan_rpm         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    uptime_seconds  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- volumes: [{mount, size_gb, used_pct}], processes: [{name, user, pid, cpu_pct, mem_gb, disk_mbs, state}]
    volumes_json    JSON NOT NULL,
    processes_json  JSON NOT NULL,
    total_processes INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_server_recorded (server_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Threshold overrides. server_id NULL = global default row (id fixed at 1).
CREATE TABLE IF NOT EXISTS thresholds (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id       INT UNSIGNED NULL UNIQUE,
    temp_warn_c     DECIMAL(5,2) NOT NULL DEFAULT 70,
    temp_crit_c     DECIMAL(5,2) NOT NULL DEFAULT 82,
    disk_warn_pct   DECIMAL(5,2) NOT NULL DEFAULT 85,
    CONSTRAINT fk_thresholds_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO thresholds (id, server_id, temp_warn_c, temp_crit_c, disk_warn_pct)
VALUES (1, NULL, 70, 82, 85)
ON DUPLICATE KEY UPDATE id = id;

-- A host counts as offline when it has no sample newer than this many seconds.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key     VARCHAR(64) PRIMARY KEY,
    setting_value   VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('offline_after_seconds', '120'),
    ('metrics_retention_hours', '72')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
