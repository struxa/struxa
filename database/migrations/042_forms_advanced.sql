-- Forms: multi-page, conditional logic, file uploads, quizzes.

ALTER TABLE cms_forms
    ADD COLUMN form_type ENUM('standard', 'quiz') NOT NULL DEFAULT 'standard' AFTER status,
    ADD COLUMN next_label VARCHAR(80) NOT NULL DEFAULT 'Next' AFTER submit_label,
    ADD COLUMN prev_label VARCHAR(80) NOT NULL DEFAULT 'Previous' AFTER next_label,
    ADD COLUMN settings_json TEXT NULL AFTER notify_subject;

ALTER TABLE cms_form_fields
    ADD COLUMN page_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER sort_order,
    ADD COLUMN conditional_json TEXT NULL AFTER settings_json;

ALTER TABLE cms_form_entries
    ADD COLUMN quiz_score INT UNSIGNED NULL AFTER referrer,
    ADD COLUMN quiz_max_score INT UNSIGNED NULL AFTER quiz_score,
    ADD COLUMN quiz_passed TINYINT(1) NULL AFTER quiz_max_score;

ALTER TABLE cms_form_entry_values
    ADD COLUMN value_file_path VARCHAR(500) NULL AFTER value_text;
