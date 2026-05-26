-- Allstar — Little League Anonymous All-Star Voting
-- Schema for MySQL 8.0+

CREATE DATABASE IF NOT EXISTS allstar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE allstar;

-- ── Global settings (KV) ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(100) PRIMARY KEY,
  value       TEXT,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Elections (a "vote") ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS elections (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  vote_code       VARCHAR(64)  NOT NULL,
  status          ENUM('setup','active','completed','archived') NOT NULL DEFAULT 'setup',
  expected_voters INT NOT NULL DEFAULT 0,
  max_roster_size INT NOT NULL DEFAULT 12,
  coach_password  VARCHAR(255) DEFAULT NULL,
  current_round   INT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vote_code (vote_code)
) ENGINE=InnoDB;

-- ── Players (roster per election) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS players (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  election_id  INT NOT NULL,
  name         VARCHAR(255) NOT NULL,
  jersey       VARCHAR(16) DEFAULT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_election (election_id, active, sort_order),
  FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Rounds ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rounds (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  election_id             INT NOT NULL,
  round_num               INT NOT NULL,
  picks_per_coach         INT NOT NULL,
  picks_to_lock           INT NOT NULL,
  state                   ENUM('pending','active','all_submitted','finalized') NOT NULL DEFAULT 'pending',
  finalized_at            DATETIME DEFAULT NULL,
  finalized_by_override   TINYINT(1) NOT NULL DEFAULT 0,
  has_tie_at_cutoff       TINYINT(1) NOT NULL DEFAULT 0,
  tie_player_ids_json     JSON DEFAULT NULL,
  created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_election_round (election_id, round_num),
  FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Voter codes (one per coach, anonymous) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS voter_codes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  election_id    INT NOT NULL,
  word           VARCHAR(64) NOT NULL,
  session_token  CHAR(64) DEFAULT NULL,
  claimed_at     DATETIME DEFAULT NULL,
  last_seen_at   DATETIME DEFAULT NULL,
  revoked        TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_election_word (election_id, word),
  INDEX idx_token (session_token),
  FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Submissions (the only voter_code ↔ ballot bridge) ─────────────────────────
-- Records that a coach has submitted in a round. NOT used for tallying.
CREATE TABLE IF NOT EXISTS submissions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  round_id        INT NOT NULL,
  voter_code_id   INT NOT NULL,
  ballot_token    CHAR(32) NOT NULL,
  submitted_at    DATETIME NOT NULL,
  UNIQUE KEY uniq_round_voter (round_id, voter_code_id),
  UNIQUE KEY uniq_ballot_token (ballot_token),
  FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
  FOREIGN KEY (voter_code_id) REFERENCES voter_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Ballot picks (tallying surface — keyed by ballot_token only) ──────────────
-- No FK to voter_codes. All tallies join only rounds → ballot_picks → players.
CREATE TABLE IF NOT EXISTS ballot_picks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  round_id      INT NOT NULL,
  ballot_token  CHAR(32) NOT NULL,
  player_id     INT NOT NULL,
  INDEX idx_round_player (round_id, player_id),
  INDEX idx_round_token  (round_id, ballot_token),
  FOREIGN KEY (round_id)  REFERENCES rounds(id)  ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Locked roster (per election, ordered by round locked) ─────────────────────
CREATE TABLE IF NOT EXISTS locked_roster (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  election_id     INT NOT NULL,
  player_id       INT NOT NULL,
  locked_in_round INT NOT NULL,
  was_manual      TINYINT(1) NOT NULL DEFAULT 0,
  locked_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_election_player (election_id, player_id),
  INDEX idx_election_round (election_id, locked_in_round),
  FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id)   REFERENCES players(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Audit log (admin overrides + sensitive state changes) ─────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  election_id  INT DEFAULT NULL,
  actor        VARCHAR(32) NOT NULL,
  action       VARCHAR(64) NOT NULL,
  detail       JSON DEFAULT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_election_time (election_id, created_at)
) ENGINE=InnoDB;

-- ── Defaults ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO settings (`key`, value) VALUES
  ('league_name', 'My Little League'),
  ('admin_pin', '');
