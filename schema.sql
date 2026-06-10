CREATE TABLE IF NOT EXISTS azuracast_stations (
    station_id INT UNSIGNED NOT NULL,
    shortcode VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    listen_url TEXT NULL,
    public_player_url TEXT NULL,
    frontend VARCHAR(50) NULL,
    backend VARCHAR(50) NULL,
    timezone VARCHAR(100) DEFAULT 'Pacific/Fiji',
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id),
    UNIQUE KEY uq_shortcode (shortcode),
    KEY idx_station_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listener_snapshots (
    snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id INT UNSIGNED NOT NULL,
    captured_at_utc DATETIME NOT NULL,
    captured_date DATE GENERATED ALWAYS AS (DATE(captured_at_utc)) STORED,
    listeners_current INT UNSIGNED NOT NULL DEFAULT 0,
    listeners_unique INT UNSIGNED NOT NULL DEFAULT 0,
    listeners_total INT UNSIGNED NOT NULL DEFAULT 0,
    is_online TINYINT(1) NOT NULL DEFAULT 1,
    now_playing_title VARCHAR(255) NULL,
    now_playing_artist VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_id),
    UNIQUE KEY uq_station_snapshot_time (station_id, captured_at_utc),
    KEY idx_captured_at (captured_at_utc),
    KEY idx_station_time (station_id, captured_at_utc),
    KEY idx_date_station (captured_date, station_id),
    CONSTRAINT fk_snapshots_station
        FOREIGN KEY (station_id)
        REFERENCES azuracast_stations(station_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listener_monthly_summary (
    station_id INT UNSIGNED NOT NULL,
    month_key CHAR(7) NOT NULL,
    snapshots_count INT UNSIGNED NOT NULL DEFAULT 0,
    unique_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    avg_listeners DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    peak_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    min_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    listener_minutes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    listener_hours DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id, month_key),
    KEY idx_month_key (month_key),
    CONSTRAINT fk_monthly_station
        FOREIGN KEY (station_id)
        REFERENCES azuracast_stations(station_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
