-- ============================================================
-- 013_redistribution.sql | Redistribuição automática de "Pendentes de contato"
-- Aditiva: nada é apagado nem alterado. A configuração fica em app_state (chave 'redist'), sem tabela nova.
--   redist_count: quantas vezes o sistema já mudou esta verificação de técnico (trava contra "vai e vem")
--   redist_at:    quando foi a última redistribuição automática (usado para dividir de forma justa no dia)
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN redist_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER pending_note,
  ADD COLUMN redist_at    DATETIME NULL AFTER redist_count;
