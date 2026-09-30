-- Grundschema för Meetingax.
-- Sammansatta nycklar gör att möte, omröstning, alternativ och deltagare
-- inte kan kopplas ihop över gränser som är logiskt omöjliga.

CREATE TABLE schema_migrations (
  version VARCHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meetings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(12) NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  meeting_code VARCHAR(12) NOT NULL,
  meeting_date DATE NULL,
  status ENUM('draft', 'open', 'closed', 'archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meetings_public_id (public_id),
  UNIQUE KEY uq_meetings_code (meeting_code),
  KEY idx_meetings_owner (owner_user_id),
  CONSTRAINT fk_meetings_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('owner', 'admin') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meeting_user (meeting_id, user_id),
  KEY idx_meeting_roles_user (user_id),
  CONSTRAINT fk_roles_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id),
  CONSTRAINT fk_roles_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(12) NOT NULL,
  meeting_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(255) NOT NULL,
  -- Aktiv e-post är unik. Avslagen eller borttagen rad släpper adressen
  -- så att personen kan anmäla sig på nytt, medan historiken finns kvar.
  email_key VARCHAR(255) GENERATED ALWAYS AS (
    CASE
      WHEN status IN ('pending', 'approved') THEN email
      ELSE NULL
    END
  ) STORED,
  status ENUM('pending', 'approved', 'rejected', 'removed') NOT NULL DEFAULT 'pending',
  is_voting_eligible TINYINT(1) NOT NULL DEFAULT 0,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participants_public_id (public_id),
  UNIQUE KEY uq_participants_id_meeting (id, meeting_id),
  UNIQUE KEY uq_participants_active_email (meeting_id, email_key),
  KEY idx_participants_meeting_status (meeting_id, status),
  KEY idx_participants_approver (approved_by_user_id),
  CONSTRAINT fk_participants_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id),
  CONSTRAINT fk_participants_approver FOREIGN KEY (approved_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  participant_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participant_token (token_hash),
  KEY idx_participant_sessions_participant (participant_id),
  KEY idx_participant_sessions_expires (expires_at),
  CONSTRAINT fk_ps_participant FOREIGN KEY (participant_id) REFERENCES participants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_registration_fields (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  field_type ENUM('text', 'number', 'select') NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  options_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fields_id_meeting (id, meeting_id),
  UNIQUE KEY uq_meeting_field_key (meeting_id, field_key),
  CONSTRAINT fk_fields_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant_field_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  registration_field_id BIGINT UNSIGNED NOT NULL,
  value TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participant_field (participant_id, registration_field_id),
  KEY idx_pfv_participant_meeting (participant_id, meeting_id),
  KEY idx_pfv_field_meeting (registration_field_id, meeting_id),
  CONSTRAINT fk_pfv_participant_meeting FOREIGN KEY (participant_id, meeting_id)
    REFERENCES participants (id, meeting_id),
  CONSTRAINT fk_pfv_field_meeting FOREIGN KEY (registration_field_id, meeting_id)
    REFERENCES meeting_registration_fields (id, meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE polls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(12) NOT NULL,
  meeting_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  voting_type ENUM('yes_no_abstain', 'single_choice', 'multiple_choice', 'person', 'ranked') NOT NULL DEFAULT 'yes_no_abstain',
  visibility ENUM('open', 'secret') NOT NULL DEFAULT 'open',
  show_results_to_participants TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft', 'open', 'closed') NOT NULL DEFAULT 'draft',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_polls_public_id (public_id),
  UNIQUE KEY uq_polls_id_meeting (id, meeting_id),
  KEY idx_polls_meeting_status (meeting_id, status),
  KEY idx_polls_creator (created_by_user_id),
  CONSTRAINT fk_polls_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id),
  CONSTRAINT fk_polls_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id BIGINT UNSIGNED NOT NULL,
  option_key VARCHAR(32) NOT NULL,
  label VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_poll_options_id_poll (id, poll_id),
  UNIQUE KEY uq_poll_option_key (poll_id, option_key),
  CONSTRAINT fk_options_poll FOREIGN KEY (poll_id) REFERENCES polls (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Högst en öppen omröstning per möte, och den måste tillhöra samma möte.
CREATE TABLE meeting_open_poll (
  meeting_id BIGINT UNSIGNED NOT NULL,
  poll_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (meeting_id),
  UNIQUE KEY uq_mop_poll (poll_id),
  KEY idx_mop_poll_meeting (poll_id, meeting_id),
  CONSTRAINT fk_mop_poll_meeting FOREIGN KEY (poll_id, meeting_id)
    REFERENCES polls (id, meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE open_ballots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  poll_id BIGINT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  poll_option_id BIGINT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_open_ballot_once (poll_id, participant_id),
  KEY idx_ob_poll_meeting (poll_id, meeting_id),
  KEY idx_ob_participant_meeting (participant_id, meeting_id),
  KEY idx_ob_option_poll (poll_option_id, poll_id),
  CONSTRAINT fk_ob_poll_meeting FOREIGN KEY (poll_id, meeting_id)
    REFERENCES polls (id, meeting_id),
  CONSTRAINT fk_ob_participant_meeting FOREIGN KEY (participant_id, meeting_id)
    REFERENCES participants (id, meeting_id),
  CONSTRAINT fk_ob_option_poll FOREIGN KEY (poll_option_id, poll_id)
    REFERENCES poll_options (id, poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Förberedd för sluten omröstning. Identiteten är skild från röstsedeln.
-- MVP skriver inte hit.
CREATE TABLE vote_participation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  poll_id BIGINT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participation_once (poll_id, participant_id),
  KEY idx_vp_poll_meeting (poll_id, meeting_id),
  KEY idx_vp_participant_meeting (participant_id, meeting_id),
  CONSTRAINT fk_vp_poll_meeting FOREIGN KEY (poll_id, meeting_id)
    REFERENCES polls (id, meeting_id),
  CONSTRAINT fk_vp_participant_meeting FOREIGN KEY (participant_id, meeting_id)
    REFERENCES participants (id, meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Röstsedeln saknar deltagar-id och tidsstämpel så att den inte kan
-- kopplas till vote_participation med en enkel join. Inmatningsordning
-- kan fortfarande korreleras. Det hanteras inte i MVP.
CREATE TABLE secret_ballots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id BIGINT UNSIGNED NOT NULL,
  poll_option_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sb_option_poll (poll_option_id, poll_id),
  CONSTRAINT fk_sb_option_poll FOREIGN KEY (poll_option_id, poll_id)
    REFERENCES poll_options (id, poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  participant_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  event_data_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_meeting_created (meeting_id, created_at),
  KEY idx_audit_user (user_id),
  KEY idx_audit_participant (participant_id),
  CONSTRAINT fk_audit_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_audit_participant FOREIGN KEY (participant_id) REFERENCES participants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket_key VARCHAR(190) NOT NULL,
  window_start DATETIME NOT NULL,
  hit_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket_key, window_start),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
