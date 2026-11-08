-- ============================================================
-- 016_rework_chain.sql | Retrabalho: "cadeia de reaberturas" de um PPPoE
-- Reabrir uma verificação concluída continua criando uma NOVA verificação (classe REABERTA, ligada à anterior por repeat_of).
-- Isso NÃO muda: a conclusão original (técnico, data, resultado, tempo) fica intacta e continua contando na produtividade.
-- O que esta migration acrescenta é só um jeito barato de a tela tratar a original e as reabertas como UM PPPoE:
--   root_id  id da PRIMEIRA verificação da cadeia (a que foi reaberta pela 1ª vez).
--            Preenchido só nas linhas REABERTA. A original NÃO é alterada: para ela, a cadeia é o próprio id (root = COALESCE(root_id, id)).
--            Sem chave estrangeira de propósito (o histórico não pode travar a exclusão de verificações/conjuntos).
-- Quantas vezes o PPPoE foi reaberto = COUNT(*) das linhas com root_id = <raiz da cadeia>. É o indicador de retrabalho.
-- Aditiva e retroativa: nada é apagado nem alterado nas linhas existentes, além de preencher root_id das REABERTA que já existiam.
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN root_id VARCHAR(40) NULL AFTER repeat_of,
  ADD KEY ix_verif_root (root_id);

-- Retroativo: cada passada resolve um nível da cadeia (reaberta de original; reaberta de reaberta; ...).
-- A condição "root_id IS NULL" faz repetir a passada ser inofensivo; 8 passadas cobrem cadeias de até 8 reaberturas seguidas.
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
UPDATE verifications v JOIN verifications o ON o.id = v.repeat_of
   SET v.root_id = IF(o.kind = 'REABERTA', o.root_id, o.id)
 WHERE v.kind = 'REABERTA' AND v.root_id IS NULL AND (o.kind <> 'REABERTA' OR o.root_id IS NOT NULL);
