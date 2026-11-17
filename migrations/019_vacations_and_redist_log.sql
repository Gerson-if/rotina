-- ============================================================
-- 019_vacations_and_redist_log.sql
--  1) FÉRIAS do técnico. O ADM registra direto (já aprovada) ou o técnico solicita e o ADM aprova/recusa.
--     Durante as férias aprovadas o técnico NÃO recebe verificações (redistribuição, designação, atribuição automática,
--     reatribuição e "assumir"), e a equipe vê o status no perfil/cartão dele. Aditiva.
--       status        SOLICITADA (aguarda o ADM) · APROVADA · RECUSADA · CANCELADA (antes de começar) · ENCERRADA (terminou antes do fim)
--       return_open   1 = ao começar, as verificações abertas e NÃO iniciadas voltam para a fila central (redistribuição cuida)
--       returned_at   quando isso foi feito (evita repetir); returned_n = quantas voltaram
--  2) HISTÓRICO DAS EXECUÇÕES da redistribuição: cada ciclo do motor que tinha pendência para olhar (moveu ou não) e o MOTIVO
--     de não ter movido (em espera, sem técnico elegível, todos no teto...). Só grava quando algo mudou, para não crescer à toa.
-- ============================================================
CREATE TABLE IF NOT EXISTS vacations (
  id             VARCHAR(40)  NOT NULL,
  user_id        VARCHAR(40)  NOT NULL,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  status         ENUM('SOLICITADA','APROVADA','RECUSADA','CANCELADA','ENCERRADA') NOT NULL DEFAULT 'SOLICITADA',
  requested_by   VARCHAR(40)  NULL,
  note           VARCHAR(300) NULL,
  decided_by     VARCHAR(40)  NULL,
  decided_at     DATETIME     NULL,
  decision_note  VARCHAR(300) NULL,
  return_open    TINYINT(1)   NOT NULL DEFAULT 1,
  returned_at    DATETIME     NULL,
  returned_n     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_vac_user (user_id, status, start_date, end_date),
  KEY ix_vac_status (status, start_date),
  CONSTRAINT fk_vac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redistribution_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ran_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source      VARCHAR(10)  NOT NULL,            -- auto | manual
  state       VARCHAR(20)  NOT NULL,            -- ok | waiting | empty | no_eligible | all_full | no_shift | error
  moved       INT UNSIGNED NOT NULL DEFAULT 0,
  pool        INT UNSIGNED NOT NULL DEFAULT 0,  -- tamanho da fila central no fim do ciclo
  waiting     INT UNSIGNED NOT NULL DEFAULT 0,  -- ainda dentro da espera mínima
  stuck       INT UNSIGNED NOT NULL DEFAULT 0,  -- esgotaram o limite de trocas (cabe ao ADM)
  eligible    INT UNSIGNED NOT NULL DEFAULT 0,  -- técnicos que podiam receber
  message     VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_redist_runs_at (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
