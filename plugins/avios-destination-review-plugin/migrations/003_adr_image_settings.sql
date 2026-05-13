-- Avios Destination Review · image-generation settings.
--
-- Adds OpenAI image fields to the singleton settings row. The script is idempotent so
-- it's safe to re-run: each ALTER guards itself, and the UPDATE seeds the default prompt
-- only when the column is still NULL.

SET @col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'adr_settings' AND column_name = 'image_enabled'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE adr_settings ADD COLUMN image_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'adr_settings' AND column_name = 'image_model'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE adr_settings ADD COLUMN image_model VARCHAR(80) NOT NULL DEFAULT ''gpt-image-1''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'adr_settings' AND column_name = 'image_size'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE adr_settings ADD COLUMN image_size VARCHAR(20) NOT NULL DEFAULT ''1536x1024''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'adr_settings' AND column_name = 'image_prompt_template'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE adr_settings ADD COLUMN image_prompt_template MEDIUMTEXT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed the prompt template when the field is empty/NULL. Editors can override and revert.
UPDATE adr_settings
   SET image_prompt_template = 'Editorial-style travel photograph of {{destination}}, captured during golden hour. Cinematic wide-angle shot, natural lighting, vibrant but realistic colours, sharp focus, no text, no watermarks, no people in the foreground. Showcase the most iconic landmark or skyline of {{destination}} (IATA {{iata}}). 16:9 aspect, photojournalistic feel, suitable as the hero image of a premium travel-rewards article.'
 WHERE id = 1
   AND (image_prompt_template IS NULL OR TRIM(image_prompt_template) = '');
