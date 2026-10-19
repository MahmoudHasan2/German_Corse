-- German AI Coach — initial schema (MySQL 8 / MariaDB 10.6+), InnoDB, utf8mb4.
-- Statements are separated by a semicolon at the end of a line.

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    role ENUM('learner','admin') NOT NULL DEFAULT 'learner',
    daily_goal_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    target_level ENUM('A1','A2','B1') NOT NULL DEFAULT 'B1',
    start_level ENUM('A1','A2','B1') NULL,
    study_start_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    kind ENUM('lesson','assessment') NOT NULL DEFAULT 'lesson',
    level ENUM('A1','A2','B1') NOT NULL,
    category VARCHAR(50) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    title_de VARCHAR(200) NOT NULL,
    title_ar VARCHAR(200) NOT NULL,
    objective_ar TEXT NOT NULL,
    description_ar TEXT NULL,
    skills JSON NOT NULL,
    dialogue JSON NULL,
    grammar_notes JSON NULL,
    review_targets JSON NULL,
    estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lessons_slug (slug),
    KEY idx_lessons_level_order (level, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_prerequisites (
    lesson_id INT UNSIGNED NOT NULL,
    requires_lesson_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (lesson_id, requires_lesson_id),
    CONSTRAINT fk_prereq_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE,
    CONSTRAINT fk_prereq_required FOREIGN KEY (requires_lesson_id) REFERENCES lessons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exercises (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    type VARCHAR(30) NOT NULL,
    skill_area ENUM('speaking','listening','grammar','vocabulary','reading','writing') NOT NULL,
    grammar_focus VARCHAR(50) NULL,
    instruction_ar VARCHAR(500) NOT NULL,
    prompt_de TEXT NOT NULL,
    context_de TEXT NULL,
    context_mode ENUM('none','read','listen') NOT NULL DEFAULT 'none',
    reference_answers JSON NOT NULL,
    key_points JSON NULL,
    hint_de VARCHAR(500) NULL,
    hint_ar VARCHAR(500) NULL,
    explanation_ar TEXT NULL,
    transfer_tasks JSON NULL,
    requires_transfer TINYINT(1) NOT NULL DEFAULT 1,
    difficulty TINYINT UNSIGNED NOT NULL DEFAULT 1,
    diagnostic_level ENUM('A1','A2','B1') NULL,
    min_words SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exercises_slug (slug),
    KEY idx_exercises_lesson (lesson_id, sort_order),
    KEY idx_exercises_skill (skill_area),
    CONSTRAINT fk_exercises_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vocabulary (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT UNSIGNED NULL,
    term_key VARCHAR(150) NOT NULL,
    word_de VARCHAR(120) NOT NULL,
    article ENUM('der','die','das') NULL,
    plural VARCHAR(120) NULL,
    word_ar VARCHAR(200) NOT NULL,
    example_de VARCHAR(300) NULL,
    example_ar VARCHAR(300) NULL,
    part_of_speech VARCHAR(20) NOT NULL DEFAULT 'noun',
    level ENUM('A1','A2','B1') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vocabulary_term (term_key),
    KEY idx_vocabulary_lesson (lesson_id),
    CONSTRAINT fk_vocabulary_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NULL,
    mode ENUM('lesson','review','assessment') NOT NULL DEFAULT 'lesson',
    state ENUM('awaiting_question','awaiting_answer','processing_answer','needs_retry','awaiting_transfer_task','completed') NOT NULL DEFAULT 'awaiting_question',
    current_exercise_id INT UNSIGNED NULL,
    phase ENUM('main','transfer') NOT NULL DEFAULT 'main',
    transfer_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
    item_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    item_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    exercise_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    strategy_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    hint_used TINYINT(1) NOT NULL DEFAULT 0,
    main_assisted TINYINT(1) NOT NULL DEFAULT 0,
    last_correction VARCHAR(1000) NULL,
    queue JSON NULL,
    processing_token CHAR(32) NULL,
    processing_started_at DATETIME NULL,
    ai_requests INT UNSIGNED NOT NULL DEFAULT 0,
    audio_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_sessions_user_state (user_id, state),
    KEY idx_sessions_user_started (user_id, started_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sessions_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE SET NULL,
    CONSTRAINT fk_sessions_exercise FOREIGN KEY (current_exercise_id) REFERENCES exercises (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE answer_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NOT NULL,
    phase ENUM('main','transfer') NOT NULL,
    transfer_index TINYINT UNSIGNED NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    input_mode ENUM('text','audio') NOT NULL,
    answer_text TEXT NOT NULL,
    transcript_original TEXT NULL,
    transcript_edited TINYINT(1) NOT NULL DEFAULT 0,
    transcription_confidence ENUM('high','medium','low','unknown') NULL,
    client_submission_id VARCHAR(64) NOT NULL,
    hint_used TINYINT(1) NOT NULL DEFAULT 0,
    copied_correction TINYINT(1) NOT NULL DEFAULT 0,
    independent TINYINT(1) NOT NULL DEFAULT 0,
    verdict VARCHAR(40) NOT NULL,
    outcome ENUM('success','failure','uncertain','confirm_transcript','unavailable') NOT NULL,
    evaluator ENUM('ai','rule','offline') NOT NULL,
    evaluation JSON NULL,
    confidence DECIMAL(4,3) NULL,
    response_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempts_submission (session_id, client_submission_id),
    KEY idx_attempts_user_created (user_id, created_at),
    KEY idx_attempts_user_exercise (user_id, exercise_id),
    CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_session FOREIGN KEY (session_id) REFERENCES learning_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_exercise FOREIGN KEY (exercise_id) REFERENCES exercises (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attempt_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    original_text VARCHAR(300) NOT NULL,
    correction VARCHAR(300) NOT NULL,
    explanation_ar VARCHAR(600) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempt_errors_user_cat (user_id, category),
    KEY idx_attempt_errors_attempt (attempt_id),
    CONSTRAINT fk_attempt_errors_attempt FOREIGN KEY (attempt_id) REFERENCES answer_attempts (id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_errors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_turns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NULL,
    attempt_id BIGINT UNSIGNED NULL,
    role ENUM('tutor','learner','system') NOT NULL,
    kind VARCHAR(30) NOT NULL,
    text_de TEXT NULL,
    text_ar TEXT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_turns_session (session_id, id),
    CONSTRAINT fk_turns_session FOREIGN KEY (session_id) REFERENCES learning_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_turns_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_turns_exercise FOREIGN KEY (exercise_id) REFERENCES exercises (id) ON DELETE SET NULL,
    CONSTRAINT fk_turns_attempt FOREIGN KEY (attempt_id) REFERENCES answer_attempts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE progression_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NULL,
    attempt_id BIGINT UNSIGNED NULL,
    decision VARCHAR(40) NOT NULL,
    reason VARCHAR(80) NOT NULL,
    from_state VARCHAR(30) NOT NULL,
    to_state VARCHAR(30) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_decisions_session (session_id, id),
    KEY idx_decisions_user (user_id, created_at),
    CONSTRAINT fk_decisions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_decisions_session FOREIGN KEY (session_id) REFERENCES learning_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_decisions_attempt FOREIGN KEY (attempt_id) REFERENCES answer_attempts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audio_transcripts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NOT NULL,
    phase ENUM('main','transfer') NOT NULL,
    transfer_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
    text TEXT NOT NULL,
    confidence ENUM('high','medium','low','unknown') NOT NULL DEFAULT 'unknown',
    avg_probability DECIMAL(5,4) NULL,
    duration_seconds DECIMAL(7,2) NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_transcripts_user (user_id, created_at),
    CONSTRAINT fk_transcripts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_transcripts_session FOREIGN KEY (session_id) REFERENCES learning_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE error_patterns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 0,
    last_original VARCHAR(300) NULL,
    last_correction VARCHAR(300) NULL,
    last_explanation_ar VARCHAR(600) NULL,
    last_exercise_id INT UNSIGNED NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_error_patterns_user_cat (user_id, category),
    CONSTRAINT fk_error_patterns_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE review_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    item_type ENUM('exercise','vocabulary') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    due_at DATETIME NOT NULL,
    interval_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ease DECIMAL(4,2) NOT NULL DEFAULT 2.50,
    repetitions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lapses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_grade TINYINT UNSIGNED NULL,
    last_reason VARCHAR(160) NULL,
    source VARCHAR(20) NOT NULL,
    last_reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_review_item (user_id, item_type, item_id),
    KEY idx_review_due (user_id, due_at),
    CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE review_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    review_id BIGINT UNSIGNED NOT NULL,
    grade TINYINT UNSIGNED NOT NULL,
    interval_before SMALLINT UNSIGNED NOT NULL,
    interval_after SMALLINT UNSIGNED NOT NULL,
    reason VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_review_log_user (user_id, created_at),
    CONSTRAINT fk_review_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_log_review FOREIGN KEY (review_id) REFERENCES review_schedule (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_progress (
    user_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NOT NULL,
    status ENUM('not_started','in_progress','needs_review','skipped','provisionally_mastered') NOT NULL DEFAULT 'not_started',
    attempts_total INT UNSIGNED NOT NULL DEFAULT 0,
    successes INT UNSIGNED NOT NULL DEFAULT 0,
    independent_successes INT UNSIGNED NOT NULL DEFAULT 0,
    hint_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    transfer_successes INT UNSIGNED NOT NULL DEFAULT 0,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    mastered_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, exercise_id),
    KEY idx_progress_status (user_id, status),
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_exercise FOREIGN KEY (exercise_id) REFERENCES exercises (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_lessons (
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (user_id, lesson_id),
    CONSTRAINT fk_user_lessons_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_lessons_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_activity (
    user_id BIGINT UNSIGNED NOT NULL,
    activity_date DATE NOT NULL,
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    successes INT UNSIGNED NOT NULL DEFAULT 0,
    audio_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    reviews INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, activity_date),
    CONSTRAINT fk_daily_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    kind ENUM('diagnostic','weekly') NOT NULL,
    status ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
    results JSON NULL,
    estimated_level VARCHAR(10) NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_assessments_user (user_id, started_at),
    CONSTRAINT fk_assessments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_assessments_session FOREIGN KEY (session_id) REFERENCES learning_sessions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_usage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_uid CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    session_id BIGINT UNSIGNED NULL,
    provider VARCHAR(30) NOT NULL,
    service ENUM('text','stt','tts') NOT NULL,
    model VARCHAR(80) NOT NULL,
    status ENUM('ok','error') NOT NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cached_tokens INT UNSIGNED NULL,
    reasoning_tokens INT UNSIGNED NULL,
    audio_seconds DECIMAL(8,2) NULL,
    characters INT UNSIGNED NULL,
    estimated_cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
    cost_basis ENUM('actual_usage','estimate') NOT NULL DEFAULT 'estimate',
    provider_request_id VARCHAR(100) NULL,
    latency_ms INT UNSIGNED NULL,
    error_code VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usage_request (request_uid),
    KEY idx_usage_user_created (user_id, created_at),
    KEY idx_usage_created (created_at),
    KEY idx_usage_session (session_id),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket VARCHAR(191) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
