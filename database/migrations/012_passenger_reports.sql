-- 012: passenger reports (complaints, safety, lost items...) read by the Association Officer

CREATE TABLE IF NOT EXISTS passenger_reports (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_code   VARCHAR(24)  NOT NULL,
    passenger_id  INT UNSIGNED NOT NULL,
    booking_id    BIGINT UNSIGNED NULL,
    category      ENUM('driver_conduct','fare_dispute','safety','lost_item','vehicle','app_problem','other') NOT NULL DEFAULT 'other',
    subject       VARCHAR(120) NOT NULL,
    message       TEXT NOT NULL,
    status        ENUM('new','in_review','resolved','dismissed') NOT NULL DEFAULT 'new',
    officer_note  VARCHAR(1000) NULL,
    handled_by    INT UNSIGNED NULL,
    content_hash  CHAR(32) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at   DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_code (report_code),
    KEY idx_reports_status (status, created_at),
    KEY idx_reports_passenger (passenger_id, created_at),
    KEY idx_reports_hash (passenger_id, content_hash),
    CONSTRAINT fk_reports_passenger FOREIGN KEY (passenger_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reports_booking   FOREIGN KEY (booking_id)   REFERENCES bookings (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_reports_handler   FOREIGN KEY (handled_by)   REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
