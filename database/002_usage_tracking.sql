-- Nova: complete the usage record on ai_messages.
--
-- 001 stored input_tokens and output_tokens only. Those are the two smallest
-- numbers in a cached, searching turn — `input_tokens` counts the *uncached*
-- remainder, so a turn with the system prompt cached reports ~150 input tokens
-- and looks nearly free. Cache reads bill at ~10% of input, cache writes at
-- 125%, and each web search is charged per search on top of tokens. Without
-- these four columns the stored figure understates real spend severalfold and
-- no report built on it can be trusted.
--
-- Additive and idempotent: MariaDB 11.8 supports IF NOT EXISTS on ADD.
-- Safe to re-run.

ALTER TABLE `ai_messages`
  ADD COLUMN IF NOT EXISTS `cache_read_tokens`  INT UNSIGNED DEFAULT NULL AFTER `output_tokens`,
  ADD COLUMN IF NOT EXISTS `cache_write_tokens` INT UNSIGNED DEFAULT NULL AFTER `cache_read_tokens`,
  ADD COLUMN IF NOT EXISTS `web_searches`       SMALLINT UNSIGNED DEFAULT NULL AFTER `cache_write_tokens`,
  -- Pricing is per model, so a stored row is only interpretable alongside the
  -- model that produced it. Rows written before this column exists are Sonnet 5.
  ADD COLUMN IF NOT EXISTS `model` VARCHAR(64) DEFAULT NULL AFTER `web_searches`;

-- The rate limiter counts a user's messages since midnight, and the usage
-- report sums a calendar month across every user. Both filter on created_at
-- across conversations rather than within one, which idx_conversation cannot
-- serve.
ALTER TABLE `ai_messages`
  ADD INDEX IF NOT EXISTS `idx_created` (`created_at`);
