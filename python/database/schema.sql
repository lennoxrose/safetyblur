-- Safety Blur License Management Database
-- Auto-generated schema file

CREATE DATABASE IF NOT EXISTS licensing;
USE licensing;

CREATE TABLE IF NOT EXISTS licences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    product VARCHAR(255) NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_license_key (license_key),
    INDEX idx_product (product),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
