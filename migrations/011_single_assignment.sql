-- ============================================================
-- 011_single_assignment.sql | Marca as verificações criadas por "Atribuição Única"
-- Permite separar, no painel do ADM, as verificações de atribuição única (um PPPoE por vez)
-- das verificações em lote (planilha). Coluna aditiva: nada é apagado nem alterado.
-- Retroativo: o que já estava no grupo padrão "Avulso" passa a contar como atribuição única.
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN is_single TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;

UPDATE verifications v
  JOIN batches b ON b.id = v.batch_id
   SET v.is_single = 1
 WHERE b.name = 'Avulso';
