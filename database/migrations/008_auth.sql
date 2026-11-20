-- 008: authentication support (last login, login throttling) and last-officer protection.
--   TRD-E305 the last active Association Officer cannot be demoted, disabled or deleted

ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active;

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(100) NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_user (username, attempted_at),
    KEY idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_users_bu$$
CREATE TRIGGER trg_users_bu BEFORE UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.role = 'driver' AND NEW.role <> 'driver'
       AND EXISTS (SELECT 1 FROM drivers WHERE user_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E304: Remove the driver profile before changing this user''s role';
    END IF;

    IF OLD.role = 'officer' AND OLD.is_active = 1 AND (NEW.role <> 'officer' OR NEW.is_active = 0)
       AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'officer' AND is_active = 1 AND id <> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E305: The last active Association Officer cannot be demoted or disabled';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_users_bd$$
CREATE TRIGGER trg_users_bd BEFORE DELETE ON users FOR EACH ROW
BEGIN
    IF OLD.role = 'officer' AND OLD.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'officer' AND is_active = 1 AND id <> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E305: The last active Association Officer cannot be deleted';
    END IF;
END$$

DELIMITER ;
