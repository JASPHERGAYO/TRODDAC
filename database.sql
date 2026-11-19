CREATE DATABASE IF NOT EXISTS practice_login CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE practice_login;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','operator','driver','passenger') NOT NULL DEFAULT 'passenger',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ALREADY IMPORTED THE OLD VERSION? Run this line instead of re-importing:
-- ALTER TABLE users ADD COLUMN role ENUM('admin','operator','driver','passenger') NOT NULL DEFAULT 'passenger' AFTER password;
