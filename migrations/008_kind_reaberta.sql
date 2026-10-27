-- ============================================================
-- 008_kind_reaberta.sql | Nova classe de verificação: REABERTA
-- Reabrir uma verificação concluída cria uma NOVA verificação (classe especial REABERTA),
-- ligada à anterior por repeat_of. A anterior continua concluída, com seu resultado preservado.
-- (aditiva: só amplia o conjunto de valores permitido)
-- ============================================================
ALTER TABLE verifications
  MODIFY COLUMN kind ENUM('NORMAL','ADICIONAL','REABERTA') NOT NULL DEFAULT 'NORMAL';
