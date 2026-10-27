-- ============================================================
-- 001_schema.sql  |  Modelo inicial - Sistema de Verificação de Rotina
-- MySQL 8.x / InnoDB / utf8mb4
-- Regra: migrations são SOMENTE-AVANÇO e ADITIVAS (nunca editar um
-- arquivo já aplicado; para mudar algo, crie o próximo número).
-- ============================================================

-- Usuários (ADM = administrador, TEC = técnico)
CREATE TABLE users (
  id                 VARCHAR(40)  NOT NULL,
  name               VARCHAR(120) NOT NULL,
  email              VARCHAR(190) NOT NULL,
  password_hash      VARCHAR(100) NOT NULL,
  role               ENUM('ADM','TEC') NOT NULL DEFAULT 'TEC',
  shift              ENUM('GERAL','MANHA','TARDE','NOITE') NOT NULL DEFAULT 'MANHA',
  status             ENUM('PENDENTE','APROVADO','BLOQUEADO') NOT NULL DEFAULT 'PENDENTE',
  token_version      INT UNSIGNED NOT NULL DEFAULT 0,      -- incrementa => invalida sessões antigas
  failed_logins      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME NULL,
  last_login_at      DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Procedimentos / ações executadas (lista configurável pelo ADM).
-- Exclusão é lógica (deleted_at) para preservar o histórico das verificações.
CREATE TABLE actions (
  id          VARCHAR(40)  NOT NULL,
  label       VARCHAR(160) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lotes (planilhas / atribuições em massa). Nome único = agrupador visual do painel.
CREATE TABLE batches (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160) NOT NULL,
  created_by  VARCHAR(40) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_batches_name (name),
  CONSTRAINT fk_batches_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificações de rotina (cada login PPPoE a ser verificado por um técnico)
CREATE TABLE verifications (
  id               VARCHAR(40)  NOT NULL,
  pppoe            VARCHAR(120) NOT NULL,
  batch_id         BIGINT UNSIGNED NOT NULL,
  tech_id          VARCHAR(40)  NOT NULL,
  shift            ENUM('GERAL','MANHA','TARDE','NOITE') NOT NULL DEFAULT 'MANHA',
  due_date         DATE NOT NULL,                              -- prazo (antigo campo "date")
  priority         ENUM('NORMAL','ALTA','URGENTE') NOT NULL DEFAULT 'NORMAL',
  obs              TEXT NULL,
  ligou            TINYINT(1) NOT NULL DEFAULT 0,
  nao_atende       TINYINT(1) NOT NULL DEFAULT 0,
  resolvido        TINYINT(1) NOT NULL DEFAULT 0,
  os_criada        TINYINT(1) NOT NULL DEFAULT 0,
  action_id        VARCHAR(40)  NOT NULL DEFAULT 'NONE',
  handle_time_min  SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  em_execucao      TINYINT(1) NOT NULL DEFAULT 0,
  finalizado       TINYINT(1) NOT NULL DEFAULT 0,
  started_at       DATETIME NULL,
  finished_at      DATETIME NULL,
  created_by       VARCHAR(40) NULL,
  version          INT UNSIGNED NOT NULL DEFAULT 1,            -- controle otimista de concorrência
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Regra de negócio no banco: só pode existir UMA verificação em aberto por PPPoE.
  -- (NULL não conflita em índice UNIQUE, então as finalizadas podem repetir.)
  active_pppoe     VARCHAR(120) GENERATED ALWAYS AS (IF(finalizado = 0, pppoe, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_verif_active_pppoe (active_pppoe),
  KEY ix_verif_tech_open   (tech_id, finalizado, due_date),
  KEY ix_verif_due         (due_date, finalizado),
  KEY ix_verif_batch       (batch_id),
  KEY ix_verif_action      (action_id),
  KEY ix_verif_pppoe       (pppoe),
  KEY ix_verif_finished    (finished_at),
  CONSTRAINT fk_verif_batch   FOREIGN KEY (batch_id)   REFERENCES batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_verif_tech    FOREIGN KEY (tech_id)    REFERENCES users(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_verif_action  FOREIGN KEY (action_id)  REFERENCES actions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_verif_creator FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trilha de auditoria (quem fez o quê): login, reatribuição, exclusão, aprovação...
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     VARCHAR(40) NULL,
  event       VARCHAR(40) NOT NULL,        -- LOGIN, LOGIN_FAIL, TASK_UPDATE, TASK_DELETE, USER_APPROVE ...
  entity      VARCHAR(30) NULL,
  entity_id   VARCHAR(60) NULL,
  details     JSON NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity, entity_id, created_at),
  KEY ix_audit_user   (user_id, created_at),
  KEY ix_audit_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Métricas diárias pré-agregadas (alimentadas pela rotina agendada) => dashboards rápidos
CREATE TABLE daily_metrics (
  metric_date      DATE NOT NULL,
  tech_id          VARCHAR(40) NOT NULL,
  assigned         INT UNSIGNED NOT NULL DEFAULT 0,
  finished         INT UNSIGNED NOT NULL DEFAULT 0,
  resolved_direct  INT UNSIGNED NOT NULL DEFAULT 0,
  os_created       INT UNSIGNED NOT NULL DEFAULT 0,
  overdue          INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_date, tech_id),
  CONSTRAINT fk_metrics_tech FOREIGN KEY (tech_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
