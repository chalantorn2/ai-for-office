-- Nova: images attached to a question.
--
-- The bytes live on disk under api/uploads/, not in the row. `ai_messages.content`
-- is MEDIUMTEXT and is read back on every turn to rebuild the prompt — a 2576px
-- screenshot base64-encoded is close to a megabyte, and twenty of those in one
-- history query would make every question slower than the model call it feeds.
-- The row carries the path and the dimensions; the dimensions are what price an
-- image (tokens ≈ w × h / 750), so a spend report can be built without opening
-- a single file.
--
-- Rows cascade with their message, which cascades with the conversation. The
-- files themselves are left on disk — an edit re-links the same file to the
-- rewritten question rather than copying it, so nothing here may assume one
-- file belongs to exactly one row.
--
-- Additive and idempotent. Safe to re-run.

CREATE TABLE IF NOT EXISTS `ai_message_images` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED  NOT NULL,
  -- Relative to api/uploads/, e.g. `2026/07/0f3c….jpg`. Never a client-supplied
  -- name: the server picks it, so nothing user-controlled reaches the filesystem.
  `path`       VARCHAR(255)     NOT NULL,
  `media_type` VARCHAR(40)      NOT NULL,
  `width`      SMALLINT UNSIGNED NOT NULL,
  `height`     SMALLINT UNSIGNED NOT NULL,
  `bytes`      INT UNSIGNED     NOT NULL,
  `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`message_id`),
  CONSTRAINT `fk_ai_message_images_message`
    FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
