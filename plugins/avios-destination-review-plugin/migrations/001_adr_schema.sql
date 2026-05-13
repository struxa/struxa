-- Avios Destination Review plugin schema. Self-contained: only touches adr_* tables.

CREATE TABLE IF NOT EXISTS adr_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    openai_api_key VARCHAR(255) DEFAULT NULL,
    openai_model VARCHAR(80) NOT NULL DEFAULT 'gpt-4o-mini',
    prompt_template MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    iata CHAR(3) NOT NULL,
    destination VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    meta_title VARCHAR(160) DEFAULT NULL,
    meta_description VARCHAR(255) DEFAULT NULL,
    content_html MEDIUMTEXT NOT NULL,
    model_used VARCHAR(80) DEFAULT NULL,
    prompt_used MEDIUMTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_iata (iata),
    UNIQUE KEY uq_slug (slug),
    KEY idx_destination (destination)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Seed the settings singleton with a strong default SEO-aware prompt.
-- Idempotent: re-running won't fail on the unique PK.
INSERT IGNORE INTO adr_settings (id, openai_model, prompt_template) VALUES (
    1,
    'gpt-4o-mini',
    'You are a senior travel-rewards journalist writing for a UK Avios audience. Write a 600-800 word destination review for travellers redeeming British Airways Avios from London Heathrow (LHR) to {{destination}} ({{iata}}).\n\nThe review must be optimised for search intent around the keyword "Avios flights to {{destination}}" while reading naturally for humans. Avoid AI tells and clichés like "nestled in" or "hidden gem". Use UK English and a confident, practical tone.\n\nReturn a single JSON object with these keys:\n- meta_title:        max 60 characters, includes both "{{destination}}" and "Avios"\n- meta_description:  max 155 characters, action-oriented, includes "{{destination}}"\n- content_html:      the article body as semantic HTML using <h2>, <h3>, <p>, <ul>, <li>. Do NOT include <html>, <head>, <body>, or an outer <h1>.\n\nThe content_html must include, in order:\n1. A short 2-sentence intro answering "is {{destination}} worth visiting on Avios?".\n2. <h2>Why redeem Avios to {{destination}}</h2> - value angle, peak vs off-peak considerations, cabin classes typically available on British Airways from Heathrow.\n3. <h2>Best time to visit {{destination}}</h2> - months, weather, notable events.\n4. <h2>How to get there with Avios</h2> - practical British Airways routing notes from LHR, typical Avios cost ranges by cabin (use plausible ranges, never invent exact figures), and tips on finding award availability.\n5. <h2>What to do in {{destination}}</h2> - 4 to 6 concrete, well-known things to do or see.\n6. <h2>Where to stay</h2> - 2 to 3 neighbourhood suggestions for points-rich hotel programmes (IHG One Rewards, Marriott Bonvoy, Hilton Honors) without inventing specific property names.\n7. <h2>Final word</h2> - a 2-3 sentence verdict for an Avios collector deciding whether to book.\n\nHard rules:\n- Do NOT invent specific Avios prices, exact hotel names, airline route numbers or tour operators.\n- Use lists where natural; no more than two <ul> lists overall.\n- When referring to "Avios calculator", "Nectar to Avios" or "credit cards", write the plain phrase (no <a> tags) so the CMS can link them later.\n- No first-person ("I", "we") - write as the publication.\n- No emojis. No markdown. Plain HTML only.'
);
