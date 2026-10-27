-- ============================================================
-- 003_php_runtime.sql | Tabelas de apoio da versão Apache/PHP
-- (aditiva: não altera nada existente)
-- ============================================================

-- Limite de tentativas (login/cadastro) por IP, sem depender de memória do servidor
CREATE TABLE IF NOT EXISTS rate_limits (
  rk            VARCHAR(80) NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME NOT NULL,
  PRIMARY KEY (rk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado das rotinas agendadas (última execução de métricas, manutenção e backup)
CREATE TABLE IF NOT EXISTS app_state (
  k           VARCHAR(60)  NOT NULL,
  v           VARCHAR(255) NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
