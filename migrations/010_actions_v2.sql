-- ============================================================
-- 010_actions_v2.sql | Novos procedimentos padrão
-- Troca de equipamento, Ajuste de DBM, Necessário visita técnica ao local,
-- Feito procedimento no equipamento, Outros e Sem sucesso no contato.
-- Os 6 procedimentos antigos saem da lista (exclusão lógica) e as verificações que
-- usavam cada um são migradas para o equivalente novo. Procedimentos criados pelo ADM
-- e a opção 'NONE' não são tocados. Nada é apagado.
-- ============================================================
INSERT INTO actions (id, label, sort_order) VALUES
 ('TROCA_EQUIPAMENTO',        'Troca de equipamento', 1),
 ('AJUSTE_DBM',               'Ajuste de DBM', 2),
 ('VISITA_TECNICA',           'Necessário visita técnica ao local', 3),
 ('PROCEDIMENTO_EQUIPAMENTO', 'Feito procedimento no equipamento', 4),
 ('OUTROS',                   'Outros', 5),
 ('SEM_SUCESSO_CONTATO',      'Sem sucesso no contato', 6)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), deleted_at = NULL;

UPDATE verifications SET action_id = 'PROCEDIMENTO_EQUIPAMENTO' WHERE action_id IN ('REBOOT_ONU', 'WIFI_DNS');
UPDATE verifications SET action_id = 'AJUSTE_DBM'               WHERE action_id = 'FIBER_ATTN';
UPDATE verifications SET action_id = 'VISITA_TECNICA'           WHERE action_id = 'SCHEDULE_VISIT';
UPDATE verifications SET action_id = 'SEM_SUCESSO_CONTATO'      WHERE action_id = 'UNSUCCESSFUL';
UPDATE verifications SET action_id = 'OUTROS'                   WHERE action_id = 'RESET_CREDENTIALS';

UPDATE actions SET deleted_at = NOW()
 WHERE id IN ('REBOOT_ONU', 'FIBER_ATTN', 'WIFI_DNS', 'SCHEDULE_VISIT', 'UNSUCCESSFUL', 'RESET_CREDENTIALS')
   AND deleted_at IS NULL;
