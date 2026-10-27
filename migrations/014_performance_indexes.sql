-- ============================================================
-- 014_performance_indexes.sql | Índices para grande volume (gráficos, sincronização, redistribuição)
-- Aditiva: só cria índices; nenhum dado é alterado. Em tabelas muito grandes pode levar alguns segundos.
--   ix_verif_done        gráficos/indicadores (concluídas por período) e "em aberto" (finalizado = 0)
--   ix_verif_tech_done   o mesmo, filtrado por técnico (painel do técnico)
--   ix_verif_pend_queue  fila central de pendentes de contato (Redistribuição); o ix_verif_pending da 012 continua existindo
--   ix_verif_tech_redist "quantas recebeu hoje" de cada técnico (Redistribuição)
-- ============================================================
ALTER TABLE verifications
  ADD KEY ix_verif_done        (finalizado, finished_at),
  ADD KEY ix_verif_tech_done   (tech_id, finalizado, finished_at),
  ADD KEY ix_verif_pend_queue  (pending_contact, finalizado, pending_since),
  ADD KEY ix_verif_tech_redist (tech_id, redist_at);
