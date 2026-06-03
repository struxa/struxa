-- Capture account username, IP, and user agent on catalog submissions (login-gated form).

ALTER TABLE cms_struxa_catalog_submissions
    ADD COLUMN submitter_username VARCHAR(191) NOT NULL DEFAULT '' AFTER submitter_user_id,
    ADD COLUMN submitter_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER submitter_username,
    ADD COLUMN submitter_user_agent VARCHAR(500) NULL AFTER submitter_ip;
