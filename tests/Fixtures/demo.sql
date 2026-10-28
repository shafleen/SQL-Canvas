-- Fictional, disposable fixture for SQL Canvas development only.
-- Compatible with MySQL 8.4 and MariaDB 11.4.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS active_projects;
DROP TABLE IF EXISTS project_assignments;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS contributors;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE contributors (
    contributor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(120) NOT NULL,
    specialty ENUM('data','backend','design','operations') NOT NULL,
    joined_on DATE NOT NULL,
    profile JSON NULL,
    PRIMARY KEY (contributor_id),
    UNIQUE KEY uq_contributors_display_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
    project_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code CHAR(6) NOT NULL,
    project_name VARCHAR(160) NOT NULL,
    status ENUM('planned','active','paused','complete') NOT NULL DEFAULT 'planned',
    budget DECIMAL(12,2) NULL,
    launched_at DATETIME NULL,
    PRIMARY KEY (project_id),
    UNIQUE KEY uq_projects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_assignments (
    project_id BIGINT UNSIGNED NOT NULL,
    contributor_id BIGINT UNSIGNED NOT NULL,
    role_name VARCHAR(80) NOT NULL,
    allocation_percent TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id, contributor_id),
    CONSTRAINT fk_assignment_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_contributor FOREIGN KEY (contributor_id) REFERENCES contributors (contributor_id) ON DELETE RESTRICT,
    CONSTRAINT chk_allocation_percent CHECK (allocation_percent BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO contributors (display_name, specialty, joined_on, profile) VALUES
    ('Mira Sol', 'data', '2025-02-14', JSON_OBJECT('timezone', 'UTC+01:00', 'mentor', true)),
    ('Ilyas North', 'backend', '2025-05-08', JSON_OBJECT('timezone', 'UTC+05:00', 'mentor', false)),
    ('Juniper Vale', 'design', '2026-01-19', NULL);

INSERT INTO projects (code, project_name, status, budget, launched_at) VALUES
    ('AURORA', 'Aurora Archive', 'active', 84000.50, '2026-04-02 09:30:00'),
    ('TIDELN', 'Tideline Observatory', 'planned', NULL, NULL);

INSERT INTO project_assignments (project_id, contributor_id, role_name, allocation_percent) VALUES
    (1, 1, 'Data model lead', 70),
    (1, 2, 'API engineer', 50),
    (2, 3, 'Interaction designer', 40);

CREATE VIEW active_projects AS
SELECT project_id, code, project_name, budget, launched_at
FROM projects
WHERE status = 'active';

