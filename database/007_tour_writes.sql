-- Nova: proposed writes to ContactRate, and the record of who applied them.
--
-- Nova can now add and edit tours. Nothing it proposes is written when the tool
-- runs: the proposal lands here as a `pending` row and the person who asked has
-- to press a button before a single column of `tours` changes. That split is the
-- whole point of the table — a model that misreads "800" as "8000" costs a
-- confirmation, not a wrong net cost sitting in the system that staff quote off.
--
-- One table serves both halves deliberately. A pending row already carries
-- everything an audit entry needs — who, which chat, which record, the exact
-- before and after — so applying it is a status change rather than a copy into a
-- second table that could disagree with the first. Cancelled and expired
-- proposals stay too: what Nova offered to do and was told not to is as much a
-- part of the record as what it did.
--
-- Rows are never deleted, and there is no foreign key to `tours`. Deleting a
-- tour in ContactRate must not erase the history of who changed it.
--
-- Additive and idempotent. Safe to re-run.

CREATE TABLE IF NOT EXISTS `ai_tour_writes` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT(11)         NOT NULL,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  -- The assistant turn that proposed this. Filled in after the reply is stored,
  -- so the confirm card can be put back under the right message on reload.
  -- NULL if the turn died before its reply was saved.
  `message_id`      BIGINT UNSIGNED          DEFAULT NULL,
  `action`          ENUM('create','update')  NOT NULL,
  -- tours.id. NULL on a create until it is applied and the row exists.
  `tour_id`         INT(11)                  DEFAULT NULL,
  -- What the record is called, frozen at proposal time. The card has to name
  -- something even for a create, where no row exists yet, and even after a
  -- rename, where the current name would no longer describe what was decided.
  `tour_name`       VARCHAR(255)    NOT NULL DEFAULT '',
  -- {"field": {"from": …, "to": …}} — the diff, already validated and coerced
  -- to the column's type. `from` is null on a create. This is what the confirm
  -- card renders and what the apply step writes; the model's raw arguments are
  -- deliberately not kept, because they are not what would be applied.
  `changes`         JSON            NOT NULL,
  `status`          ENUM('pending','applied','cancelled') NOT NULL DEFAULT 'pending',
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- When someone pressed a button. NULL while pending.
  `decided_at`      TIMESTAMP       NULL     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`, `id`),
  KEY `idx_tour` (`tour_id`, `id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_ai_tour_writes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  -- Deleting a chat takes its proposals with it. A pending proposal outside any
  -- conversation could never be confirmed, and an applied one is already
  -- recorded on the tour itself via `updated_by`.
  CONSTRAINT `fk_ai_tour_writes_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
