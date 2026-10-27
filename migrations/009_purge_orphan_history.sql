-- ============================================================
-- 009_purge_orphan_history.sql | Remove o histórico "órfão": linhas da linha do tempo e da auditoria que
-- sobraram de verificações que já foram excluídas (antes da v1.5.17 a exclusão deixava esse rastro).
-- (só apaga registros sem verificação correspondente; nada de verificação existente é tocado)
-- ============================================================
DELETE FROM verification_events WHERE verification_id NOT IN (SELECT id FROM verifications);
DELETE FROM audit_log WHERE entity = 'verification' AND event <> 'TASK_DELETE'
   AND entity_id NOT IN (SELECT id FROM verifications);
