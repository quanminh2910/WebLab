-- Personal assignment: service catalog and beauty specialist profiles.
-- No tables are dropped. Re-importing leaves existing rows unchanged.

CREATE DATABASE IF NOT EXISTS spa_beauty_center_assignment
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE spa_beauty_center_assignment;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'customer') NOT NULL DEFAULT 'customer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,
    short_description VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10, 0) UNSIGNED NOT NULL COMMENT 'Price in VND',
    duration_min_minutes SMALLINT UNSIGNED NOT NULL,
    duration_max_minutes SMALLINT UNSIGNED NOT NULL,
    image VARCHAR(255) NOT NULL COMMENT 'Filename relative to public/images/',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_slug (slug),
    KEY idx_services_category_id (category_id),
    CONSTRAINT fk_services_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_services_price CHECK (price > 0),
    CONSTRAINT chk_services_duration CHECK (
        duration_min_minutes > 0 AND duration_max_minutes >= duration_min_minutes
    ),
    CONSTRAINT chk_services_featured CHECK (is_featured IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO categories (name, slug, description) VALUES
    ('Massage Therapy', 'massage', 'Full-body and targeted massage treatments.'),
    ('Facial Treatment', 'facial', 'Cleansing, hydration, and facial skincare.'),
    ('Wellness Therapy', 'wellness', 'Relaxing treatments for body and mind.'),
    ('Nail Care', 'nail-care', 'Manicure, pedicure, and regular polish services.')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

INSERT INTO services (
    category_id, name, slug, short_description, description, price,
    duration_min_minutes, duration_max_minutes, image, is_featured
) VALUES
    (
        (SELECT id FROM categories WHERE slug = 'massage'),
        'Swedish Massage', 'swedish-massage',
        'A gentle full-body massage that eases tension and promotes deep relaxation.',
        'Unwind with a gentle full-body massage using long, flowing strokes and light to medium pressure. This treatment helps ease everyday tension, improve circulation, and leave you feeling calm and refreshed.',
        550000, 60, 60, 'swedish.jpg', 0
    ),
    (
        (SELECT id FROM categories WHERE slug = 'massage'),
        'Deep Tissue Massage', 'deep-tissue-massage',
        'Focused pressure helps release persistent muscle tension and improve mobility.',
        'Target persistent muscle tension with slow strokes and focused pressure applied to deeper layers of muscle. This treatment is ideal for tired muscles, stiffness, and areas that need extra attention.',
        690000, 60, 60, 'deep-tissue-massage.jpg', 0
    ),
    (
        (SELECT id FROM categories WHERE slug = 'facial'),
        'Radiance Facial', 'radiance-facial',
        'A refreshing facial treatment that cleanses, hydrates, and brightens your skin.',
        'Restore a fresh, healthy glow with a complete facial that cleanses, gently exfoliates, hydrates, and nourishes the skin. The treatment is suitable for dull or tired-looking complexions.',
        590000, 60, 60, 'radiancefacial.jpg', 0
    ),
    (
        (SELECT id FROM categories WHERE slug = 'wellness'),
        'Hot Stone Therapy', 'hot-stone-therapy',
        'Smooth heated stones provide soothing warmth and encourage total-body relaxation.',
        'Experience deep relaxation as smooth heated stones warm the muscles and complement soothing massage techniques. The gentle heat helps relieve tension and encourages a peaceful sense of well-being.',
        650000, 60, 60, 'hotstones.jpg', 1
    ),
    (
        (SELECT id FROM categories WHERE slug = 'nail-care'),
        'Manicure & Pedicure, Regular Polish', 'manicure-pedicure',
        'Complete nail and cuticle care finished with the polish color of your choice.',
        'Enjoy complete care for your hands, feet, nails, and cuticles. The service includes shaping, grooming, moisturizing, and a polished finish in the color of your choice.',
        400000, 60, 75, 'manicure.jpg', 0
    ),
    (
        (SELECT id FROM categories WHERE slug = 'wellness'),
        'Aromatherapy Session', 'aromatherapy',
        'A calming treatment combining massage techniques with aromatic essential oils.',
        'Relax your body and mind with calming massage techniques enhanced by a carefully selected blend of aromatic essential oils. This restorative session is designed to reduce stress and support relaxation.',
        590000, 60, 60, 'aroma.jpg', 0
    )
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

COMMIT;

-- Specialist profiles are independent of customer and administrator login accounts.
CREATE TABLE IF NOT EXISTS staff (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100) NOT NULL,
    biography TEXT NOT NULL,
    photo VARCHAR(255) NOT NULL DEFAULT 'default-portrait.svg',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_services (
    staff_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    KEY idx_staff_services_service_id (service_id),
    CONSTRAINT fk_staff_services_staff FOREIGN KEY (staff_id)
        REFERENCES staff (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_services_service FOREIGN KEY (service_id)
        REFERENCES services (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- Only newly inserted sample profiles receive sample assignments.
-- Re-importing preserves changes to existing profiles and their chosen services.
SET @seed_staff_massage = NOT EXISTS (SELECT 1 FROM staff WHERE id = 1);
SET @seed_staff_facial = NOT EXISTS (SELECT 1 FROM staff WHERE id = 2);
SET @seed_staff_wellness = NOT EXISTS (SELECT 1 FROM staff WHERE id = 3);
SET @seed_staff_nails = NOT EXISTS (SELECT 1 FROM staff WHERE id = 4);

-- These four profiles are fictional examples for the assignment.
INSERT INTO staff (id, name, specialization, biography, photo) VALUES
    (1, 'Mai Nguyen', 'Massage Specialist',
     'Mai focuses on relaxing full-body massage and targeted work for tired muscles. She adjusts the pressure to help each guest feel comfortable throughout the treatment.',
     'default-portrait.svg'),
    (2, 'Linh Tran', 'Facial Treatment Specialist',
     'Linh enjoys helping guests build simple skincare habits. Her facial treatments focus on gentle cleansing, hydration, and a refreshed complexion.',
     'default-portrait.svg'),
    (3, 'An Pham', 'Wellness Therapist',
     'An offers calming treatments with heated stones and aromatic oils. She creates a quiet, welcoming space for guests to unwind.',
     'default-portrait.svg'),
    (4, 'Vy Le', 'Nail Care Specialist',
     'Vy provides careful nail shaping, cuticle care, and regular polish finishes. She helps guests choose colors that suit their everyday style.',
     'default-portrait.svg')
ON DUPLICATE KEY UPDATE id = VALUES(id);

INSERT INTO staff_services (staff_id, service_id)
SELECT st.id, s.id
FROM staff AS st
INNER JOIN services AS s ON
    (st.id = 1 AND @seed_staff_massage AND s.slug IN ('swedish-massage', 'deep-tissue-massage'))
    OR (st.id = 2 AND @seed_staff_facial AND s.slug = 'radiance-facial')
    OR (st.id = 3 AND @seed_staff_wellness AND s.slug IN ('hot-stone-therapy', 'aromatherapy'))
    OR (st.id = 4 AND @seed_staff_nails AND s.slug = 'manicure-pedicure');

COMMIT;

