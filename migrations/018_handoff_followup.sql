-- ============================================================
-- 018_handoff_followup.sql | Acompanhamento das pendências que JÁ FORAM para um técnico.
-- Quando uma pendência de contato sai da fila central (redistribuição automática, designação do ADM ou técnico que assume),
-- o ADM precisa saber se quem recebeu realmente chegou a ver: há técnico que nem entra no sistema, e algumas pendências são urgentes.
--   handoff_seen_at  quando o técnico que recebeu abriu o sistema pela 1ª vez DEPOIS de receber (NULL = ainda não abriu).
--                    É gravado pelo servidor quando o técnico carrega a própria fila; volta a NULL a cada nova entrega
--                    (redistribuição, designação, reatribuição pelo ADM) e quando a verificação volta para a fila central.
-- Aditiva. Sem chave estrangeira de propósito (o histórico não pode travar a exclusão/bloqueio de usuários).
-- ============================================================
ALTER TABLE verifications
  ADD COLUMN handoff_seen_at DATETIME NULL AFTER handoff_at,
  ADD KEY ix_verif_handoff (handoff_at);

-- Retroativo (evita alarme falso nas entregas que já existiam antes desta versão). Só preenche quando há prova de que o técnico
-- já esteve no sistema depois de receber; o que não tiver prova fica NULL e passa a ser acompanhado.
-- 1) já concluída ou já iniciada: o técnico obviamente viu.
UPDATE verifications
   SET handoff_seen_at = COALESCE(started_at, finished_at, handoff_at)
 WHERE handoff_at IS NOT NULL AND handoff_seen_at IS NULL AND (finalizado = 1 OR started_at IS NOT NULL);

-- 2) ainda sem início: vale o último sinal de vida do técnico (último login ou última presença) se for posterior à entrega.
UPDATE verifications v JOIN users u ON u.id = v.tech_id
   SET v.handoff_seen_at = GREATEST(COALESCE(u.last_login_at, '1970-01-01'), COALESCE(u.last_seen_at, '1970-01-01'))
 WHERE v.handoff_at IS NOT NULL AND v.handoff_seen_at IS NULL
   AND GREATEST(COALESCE(u.last_login_at, '1970-01-01'), COALESCE(u.last_seen_at, '1970-01-01')) >= v.handoff_at;
