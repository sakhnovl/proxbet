-- Migration: Add algorithm_version and live_score_components fields
-- Purpose: Support Algorithm 1 v2 with component tracking and dual-run capability
-- Date: 2026-03-20

ALTER TABLE matches
    ADD COLUMN algorithm_version INT NOT NULL DEFAULT 1 COMMENT '1 = legacy, 2 = v2',
    ADD COLUMN live_score_components LONGTEXT NULL COMMENT 'JSON with Algorithm 1 v2 components';

-- Index for filtering by algorithm version
CREATE INDEX idx_algorithm_version ON matches(algorithm_version);
