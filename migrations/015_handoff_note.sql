-- ============================================================
-- 015_handoff_note.sql | "Passagem de bastão": quando uma pendência de contato vai para OUTRO técnico (ele assumiu, ou a
-- Redistribuição automática enviou), a nota de quem deixou ("o que já foi tentado e o que falta") agora acompanha a verificação.
--   handoff_note  o que o técnico anterior descreveu como pendente
--   handoff_from  quem deixou (id do usuário)
--   handoff_at    quando passou para o novo técnico
-- Aditiva. Sem chave estrangeira de propósito (o histórico não pode travar a exclusão/bloqueio de usuários).
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN handoff_note TEXT NULL AFTER pending_note,
  ADD COLUMN handoff_from VARCHAR(40) NULL AFTER handoff_note,
  ADD COLUMN handoff_at   DATETIME NULL AFTER handoff_from;
