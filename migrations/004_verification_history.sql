-- ============================================================
-- 004_verification_history.sql | Histórico (auditoria) de cada verificação
-- e classe "ADICIONAL" para PPPoE que já foram verificados antes.
-- (aditiva: não remove nem altera dados existentes)
-- ============================================================

-- Classe da verificação: NORMAL = primeira vez; ADICIONAL = o PPPoE já tinha sido verificado antes.
-- prev_count = quantas verificações anteriores existiam; repeat_of = a verificação anterior mais recente.
ALTER TABLE verifications
  ADD COLUMN kind        ENUM('NORMAL','ADICIONAL') NOT NULL DEFAULT 'NORMAL' AFTER priority,
  ADD COLUMN prev_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER kind,
  ADD COLUMN repeat_of   VARCHAR(40) NULL AFTER prev_count,
  ADD KEY ix_verif_kind (kind);

-- Uma linha por acontecimento de cada PPPoE verificado (quem, o quê, quando).
-- Sem chave estrangeira para verifications de propósito: o histórico sobrevive à exclusão da verificação.
CREATE TABLE IF NOT EXISTS verification_events (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  verification_id  VARCHAR(40)  NOT NULL,
  pppoe            VARCHAR(120) NOT NULL,
  user_id          VARCHAR(40)  NULL,
  event            VARCHAR(30)  NOT NULL,   -- CRIADA, REATRIBUIDA, ASSUMIDA, INICIADA, CHECKLIST, ACAO, OBS, AJUSTE, CONCLUIDA, REABERTA
  details          JSON NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_vev_verif (verification_id, id),
  KEY ix_vev_pppoe (pppoe, id),
  KEY ix_vev_date  (created_at),
  CONSTRAINT fk_vev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico retroativo: o que já existia ganha as linhas básicas (criada, iniciada, concluída)
INSERT INTO verification_events (verification_id, pppoe, user_id, event, created_at)
  SELECT id, pppoe, created_by, 'CRIADA', created_at FROM verifications;

INSERT INTO verification_events (verification_id, pppoe, user_id, event, created_at)
  SELECT id, pppoe, tech_id, 'INICIADA', started_at FROM verifications WHERE started_at IS NOT NULL;

INSERT INTO verification_events (verification_id, pppoe, user_id, event, created_at)
  SELECT id, pppoe, tech_id, 'CONCLUIDA', finished_at FROM verifications WHERE finalizado = 1 AND finished_at IS NOT NULL;
