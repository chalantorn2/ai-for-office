-- Nova: who each member of staff is, in their own office's terms.
--
-- Nova already knows a person's nickname, office and position from `users`.
-- That is enough to address them and nothing more — it does not say that พี่หนุ่ย
-- handles flight tickets, or that พี่ดา has been doing this long enough not to
-- want the basics explained. That context changes every answer Nova gives, so it
-- has to live somewhere the assistant can read at request time.
--
-- It is a separate `ai_` table rather than a column on `users` for two reasons:
-- the existing ContactRate app owns that table and has its own User Management
-- screen over it, and db-migrate.php refuses to touch anything outside `ai_` on
-- purpose. Nothing the old app reads changes.
--
-- Rows are written by scripts/sync-profiles.ps1 from database/profiles.json.
-- There is no UI: this is edited by hand, deliberately.
--
-- Additive only. Safe to re-run.

CREATE TABLE IF NOT EXISTS `ai_user_profiles` (
  `user_id`    INT(11)   NOT NULL,
  -- Free prose, addressed to Nova and folded into the system prompt verbatim.
  -- Keep it short: it is billed on every turn this person takes.
  `about`      TEXT      NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_ai_user_profiles_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
