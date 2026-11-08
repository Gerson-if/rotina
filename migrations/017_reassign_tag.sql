-- ============================================================
-- 017_reassign_tag.sql | Tag "Reatribuída por X": quando o ADM passa uma verificação em aberto para OUTRO técnico,
-- o novo técnico vê quem reatribuiu e quando.
--   reassigned_by  quem reatribuiu (id do usuário ADM)
--   reassigned_at  quando
-- Só valem enquanto a verificação não for concluída; saem quando o técnico a conclui, a deixa pendente ou ela troca de dono de novo.
-- Aditiva. Sem chave estrangeira de propósito (o histórico não pode travar a exclusão/bloqueio de usuários).
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN reassigned_by VARCHAR(40) NULL AFTER handoff_at,
  ADD COLUMN reassigned_at DATETIME NULL AFTER reassigned_by;
