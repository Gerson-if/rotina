-- ============================================================
-- 006_madrugada_avatar.sql | Turno MADRUGADA e foto de perfil
-- (aditiva: não altera nem apaga dados existentes)
-- ============================================================
ALTER TABLE users
  MODIFY COLUMN shift ENUM('GERAL','MANHA','TARDE','NOITE','MADRUGADA') NOT NULL DEFAULT 'MANHA';

ALTER TABLE verifications
  MODIFY COLUMN shift ENUM('GERAL','MANHA','TARDE','NOITE','MADRUGADA') NOT NULL DEFAULT 'MANHA';

ALTER TABLE users
  ADD COLUMN avatar    MEDIUMBLOB NULL AFTER bio,
  ADD COLUMN avatar_v  INT UNSIGNED NOT NULL DEFAULT 0 AFTER avatar;
