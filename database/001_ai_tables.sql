-- Nova: chat history storage.
--
-- Additive only. Creates two new tables prefixed `ai_` and touches nothing that
-- the existing ContactRate app reads or writes. Safe to re-run.
--
-- History is private per user: every read must filter on user_id. The index is
-- ordered (user_id, updated_at DESC) so the sidebar query never sorts a filesort.

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)         NOT NULL,
  `title`      VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_updated` (`user_id`, `updated_at`),
  CONSTRAINT `fk_ai_conversations_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id`              BIGINT UNSIGNED            NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED            NOT NULL,
  `role`            ENUM('user','assistant')   NOT NULL,
  `content`         MEDIUMTEXT                 NOT NULL,
  -- Token usage per assistant turn, for tracking spend against the ~2,000 THB
  -- monthly budget. NULL on user turns.
  `input_tokens`    INT UNSIGNED               DEFAULT NULL,
  `output_tokens`   INT UNSIGNED               DEFAULT NULL,
  `created_at`      TIMESTAMP                  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`, `id`),
  CONSTRAINT `fk_ai_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
