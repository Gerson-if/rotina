-- ============================================================
-- 012_pending_contact.sql | "Pendente de contato": verificação que o técnico não conseguiu concluir
-- e deixa numa área compartilhada para QUALQUER técnico assumir e continuar (ex.: virada de turno).
-- Aditiva: nada é apagado. A verificação continua a mesma (histórico, prazo e atraso preservados);
-- tech_id guarda quem a deixou por último até alguém assumir.
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN pending_contact TINYINT(1) NOT NULL DEFAULT 0 AFTER em_execucao,
  ADD COLUMN pending_since   DATETIME NULL AFTER pending_contact,
  ADD COLUMN pending_note    TEXT NULL AFTER pending_since,
  ADD KEY ix_verif_pending (pending_contact);
