-- Core forms builder (contact forms, submissions inbox, notifications).

CREATE TABLE IF NOT EXISTS cms_forms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    submit_label VARCHAR(80) NOT NULL DEFAULT 'Submit',
    confirmation_type ENUM('message', 'redirect') NOT NULL DEFAULT 'message',
    confirmation_message TEXT NULL,
    confirmation_redirect_url VARCHAR(500) NULL,
    honeypot_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notify_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notify_emails TEXT NULL,
    notify_subject VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_forms_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_form_fields (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    field_type VARCHAR(40) NOT NULL,
    label VARCHAR(200) NOT NULL,
    placeholder VARCHAR(255) NULL,
    help_text VARCHAR(500) NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    options_json TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    settings_json TEXT NULL,
    UNIQUE KEY uq_cms_form_field (form_id, field_key),
    KEY idx_cms_form_fields_form (form_id),
    CONSTRAINT fk_cms_form_fields_form FOREIGN KEY (form_id) REFERENCES cms_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_form_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    status ENUM('new', 'read', 'spam', 'trash') NOT NULL DEFAULT 'new',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    referrer VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cms_form_entries_form (form_id),
    KEY idx_cms_form_entries_status (status),
    CONSTRAINT fk_cms_form_entries_form FOREIGN KEY (form_id) REFERENCES cms_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_form_entry_values (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NULL,
    field_key VARCHAR(80) NOT NULL,
    value_text LONGTEXT NULL,
    KEY idx_cms_form_entry_values_entry (entry_id),
    CONSTRAINT fk_cms_form_entry_values_entry FOREIGN KEY (entry_id) REFERENCES cms_form_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_permissions (name, slug, description) VALUES
('Manage forms', 'manage_forms', 'Create forms, view submissions, and configure notifications.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'manage_forms' AND r.slug IN ('super_admin', 'admin', 'editor');
