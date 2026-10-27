-- ============================================================
-- 007_backfill_kind.sql | Corrige a classe (NORMAL/ADICIONAL) e a contagem de verificações anteriores
-- de PPPoE que já tinham histórico antes da migration 004 (ficaram como NORMAL por padrão).
-- (aditiva: só ajusta kind/prev_count; não apaga nada)
-- ============================================================
UPDATE verifications v
   SET v.prev_count = GREATEST(v.prev_count, (SELECT COUNT(*) FROM (SELECT id, pppoe, created_at FROM verifications) o
                                               WHERE o.pppoe = v.pppoe AND o.id <> v.id
                                                 AND (o.created_at < v.created_at OR (o.created_at = v.created_at AND o.id < v.id))));
UPDATE verifications SET kind = 'ADICIONAL' WHERE prev_count > 0 AND kind = 'NORMAL';
