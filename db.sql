CREATE DATABASE IF NOT EXISTS metre_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE metre_db;

CREATE TABLE IF NOT EXISTS fare_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_type VARCHAR(80) NOT NULL UNIQUE,
    rate_per_meter DECIMAL(10,4) NOT NULL DEFAULT 0.1500,
    minimum_fare DECIMAL(10,2) NOT NULL DEFAULT 40.00,
    night_surcharge_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    waiting_rate_per_minute DECIMAL(10,2) NOT NULL DEFAULT 2.00,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'driver') NOT NULL DEFAULT 'driver',
    vehicle_type VARCHAR(80) DEFAULT 'Standard Taxi',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_token VARCHAR(80) NOT NULL UNIQUE,
    public_tracking_token VARCHAR(80) NULL,
    driver_id INT UNSIGNED NOT NULL,
    vehicle_type VARCHAR(80) NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    total_meters DECIMAL(12,2) NOT NULL DEFAULT 0,
    waiting_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    final_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
    fare_breakdown_json LONGTEXT NULL,
    route_points_json LONGTEXT NULL,
    start_lat DECIMAL(10,7) NULL,
    start_lng DECIMAL(10,7) NULL,
    end_lat DECIMAL(10,7) NULL,
    end_lng DECIMAL(10,7) NULL,
    trip_status VARCHAR(30) NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_trips_started_at (started_at),
    INDEX idx_trips_driver_id (driver_id),
    INDEX idx_trips_public_tracking_token (public_tracking_token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trip_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_token VARCHAR(80) NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    point_type ENUM('start', 'checkpoint', 'end') NOT NULL DEFAULT 'checkpoint',
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    meter_mark DECIMAL(12,2) NOT NULL DEFAULT 0,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_points_driver FOREIGN KEY (driver_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_trip_points_token (trip_token),
    INDEX idx_trip_points_driver_id (driver_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS live_trip_tracking (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_token VARCHAR(80) NOT NULL UNIQUE,
    public_tracking_token VARCHAR(80) NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    vehicle_type VARCHAR(80) NOT NULL,
    status ENUM('waiting', 'in_trip', 'completed') NOT NULL DEFAULT 'waiting',
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    last_lat DECIMAL(10,7) NULL,
    last_lng DECIMAL(10,7) NULL,
    meters DECIMAL(12,2) NOT NULL DEFAULT 0,
    waiting_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    current_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
    route_points_json LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_live_trip_tracking_driver FOREIGN KEY (driver_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_live_trip_tracking_driver_id (driver_id),
    INDEX idx_live_trip_tracking_status (status),
    INDEX idx_live_trip_tracking_token (public_tracking_token)
) ENGINE=InnoDB;

INSERT INTO fare_settings (vehicle_type, rate_per_meter, minimum_fare, night_surcharge_percent, waiting_rate_per_minute)
VALUES
    ('Standard Taxi', 0.1500, 40.00, 20.00, 2.00),
    ('Premium Sedan', 0.1800, 60.00, 20.00, 2.50),
    ('SUV', 0.2200, 75.00, 20.00, 3.00),
    ('Van', 0.2500, 85.00, 20.00, 3.50)
ON DUPLICATE KEY UPDATE
    rate_per_meter = VALUES(rate_per_meter),
    minimum_fare = VALUES(minimum_fare),
    night_surcharge_percent = VALUES(night_surcharge_percent),
    waiting_rate_per_minute = VALUES(waiting_rate_per_minute);

INSERT INTO users (full_name, username, password_hash, user_type, vehicle_type, is_active)
VALUES
    ('System Administrator', 'admin', '$2y$10$H0q/VXMippvhysojslvbSeTUOaYlYHFbQqSb3Zp1I2O36y0D/xpp6', 'admin', 'Standard Taxi', 1),
    ('Driver One', 'driver1', '$2y$10$0jQXcaJWkahaJJ7oeOv3DeSCTXtrU4mQ3WN/e9v2gATyz0lyClypq', 'driver', 'Standard Taxi', 1)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    password_hash = VALUES(password_hash),
    user_type = VALUES(user_type),
    vehicle_type = VALUES(vehicle_type),
    is_active = VALUES(is_active);
