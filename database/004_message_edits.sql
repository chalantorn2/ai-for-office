-- Nova: editing an earlier question.
--
-- Rewriting a question drops it and every turn after it from the conversation.
-- Doing that with DELETE would also erase the only record that those turns were
-- ever paid for: `ai_messages` is both the chat log and the spend ledger, so a
-- person editing a question ten turns back would silently take that day's
-- message count and that month's THB total down with it. 002 exists because
-- spend figures built on incomplete rows cannot be believed; deleting whole
-- rows is the same failure with a bigger hole.
--
-- So a superseded turn is stamped rather than removed. It leaves the
-- conversation — the UI never shows it again and there is no way back to it —
-- while the tokens it cost stay counted. The rate limiter and the usage report
-- deliberately do NOT filter on this column: that money was spent.
--
-- Additive and idempotent. Safe to re-run.

ALTER TABLE `ai_messages`
  ADD COLUMN IF NOT EXISTS `superseded_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
