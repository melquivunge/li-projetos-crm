-- 004: próxima ação (follow-up) e data da última mudança de etapa
ALTER TABLE clients
  ADD COLUMN next_action VARCHAR(160) NULL AFTER lost_reason,
  ADD COLUMN next_action_at DATE NULL AFTER next_action,
  ADD COLUMN stage_changed_at DATETIME NULL AFTER next_action_at,
  ADD INDEX (next_action_at);
UPDATE clients SET stage_changed_at = updated_at WHERE stage_changed_at IS NULL;
