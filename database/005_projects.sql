-- Nova: projects — folders that group chats.
--
-- A project holds conversations and nothing else: no per-project instructions,
-- no files. Deliberate. Instructions would have to be folded into the system
-- prompt, and anything appended per-project sits inside the cached prefix, so
-- switching projects would break the prompt cache on every turn. Folders cost
-- nothing per turn.
--
-- `project_id` is nullable and defaults to NULL, so every conversation that
-- already exists stays exactly where it is — outside any project, in the
-- age-grouped list. There is no backfill.
--
-- The foreign key is ON DELETE SET NULL, not CASCADE. Deleting a project must
-- release its chats, never take them with it: `ai_conversations` cascades into
-- `ai_messages`, which is the spend ledger (see 002 and 004), so a CASCADE here
-- would let one click on a folder erase a month of recorded spend. A released
-- chat reappears under its date heading.
--
-- Additive and idempotent. Safe to re-run.

CREATE TABLE IF NOT EXISTS `ai_projects` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)         NOT NULL,
  `name`       VARCHAR(120)    NOT NULL DEFAULT '',
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_ai_projects_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ai_conversations`
  ADD COLUMN IF NOT EXISTS `project_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `user_id`;

-- MariaDB puts the guard after FOREIGN KEY, not after CONSTRAINT: the grammar
-- is ADD [CONSTRAINT [symbol]] FOREIGN KEY [IF NOT EXISTS] [index_name] (...).
-- `ADD CONSTRAINT IF NOT EXISTS … FOREIGN KEY` is a 1064 syntax error.
ALTER TABLE `ai_conversations`
  ADD CONSTRAINT `fk_ai_conversations_project`
    FOREIGN KEY IF NOT EXISTS `fk_ai_conversations_project` (`project_id`)
    REFERENCES `ai_projects` (`id`) ON DELETE SET NULL;
