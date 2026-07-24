-- Nova: proposed changes to ContactRate — one table for every kind of record.
--
-- Supersedes `ai_tour_writes` from 007, which was the same table with `tour`
-- baked into its name and columns. Suppliers needed the identical machinery one
-- day later — a pending diff, a person to confirm it, a record of who did — and
-- hotels would need it again. The only thing that differs per record type is
-- which table and which columns, which is data, not a schema.
--
-- Done now rather than later on purpose: 007 held exactly one row, a cancelled
-- test, so nothing is being migrated and no history is lost. `ai_tour_writes`
-- can be dropped by hand once this is deployed — `scripts/db-migrate.php`
-- rejects DROP, deliberately.
--
-- Everything else is unchanged from 007 and the reasoning there still holds:
-- nothing is written when Nova proposes, rows are never deleted, cancelled
-- proposals are kept, and there is no foreign key to the record being changed —
-- deleting a tour must not erase the history of who edited it.
--
-- Additive and idempotent. Safe to re-run.

CREATE TABLE IF NOT EXISTS `ai_record_writes` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT(11)         NOT NULL,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  -- The assistant turn that proposed this, filled in once the reply is stored.
  -- NULL if the turn died before that happened.
  `message_id`      BIGINT UNSIGNED          DEFAULT NULL,
  -- Which ContactRate table this is about. Not a foreign key to anything — the
  -- mapping from a name here to a table and its columns lives in
  -- api/lib/writes.php, where the per-column validation rules are.
  `entity`          ENUM('tour','supplier')  NOT NULL,
  `action`          ENUM('create','update')  NOT NULL,
  -- The row's primary key. NULL on a create until it is applied and exists.
  `record_id`       INT(11)                  DEFAULT NULL,
  -- What the record is called, frozen at proposal time. The card has to name
  -- something even for a create, where no row exists yet, and even after a
  -- rename, where the current name no longer describes what was agreed to.
  `record_name`     VARCHAR(255)    NOT NULL DEFAULT '',
  -- {"column": {"from": …, "to": …}} — the diff, validated and already coerced
  -- to each column's type. `from` is null on a create. This is what the confirm
  -- card renders and what the apply step writes; the model's raw arguments are
  -- deliberately not kept, because they are not what would be applied.
  `changes`         JSON            NOT NULL,
  `status`          ENUM('pending','applied','cancelled') NOT NULL DEFAULT 'pending',
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`      TIMESTAMP       NULL     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`, `id`),
  KEY `idx_record` (`entity`, `record_id`, `id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_ai_record_writes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_record_writes_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
