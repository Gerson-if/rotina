-- ============================================================
-- 005_profiles_presence.sql | Perfil do técnico (bio) e presença online
-- (aditiva: não altera dados existentes)
-- ============================================================
ALTER TABLE users
  ADD COLUMN bio           VARCHAR(500) NULL AFTER shift,
  ADD COLUMN last_seen_at  DATETIME NULL AFTER last_login_at,
  ADD KEY ix_users_seen (last_seen_at);
