-- Personlig återlänk. Bara hashen sparas. En rad per aktiv anmälan.
CREATE TABLE participant_return_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  participant_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_return_token_hash (token_hash),
  UNIQUE KEY uq_return_participant (participant_id),
  KEY idx_return_expires (expires_at),
  CONSTRAINT fk_return_participant FOREIGN KEY (participant_id) REFERENCES participants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
