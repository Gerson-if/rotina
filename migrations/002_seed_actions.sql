-- Procedimentos padrão (os mesmos do sistema original)
INSERT INTO actions (id, label, sort_order) VALUES
 ('NONE',              'Nenhuma Ação Registrada', 0),
 ('REBOOT_ONU',        'Reinício Remoto de ONU/Roteador', 1),
 ('FIBER_ATTN',        'Ajuste de Sinal / Limpeza de Fibra', 2),
 ('WIFI_DNS',          'Troca de Canal Wi-Fi / DNS Primário', 3),
 ('SCHEDULE_VISIT',    'Agendamento de Visita Técnica em Campo', 4),
 ('UNSUCCESSFUL',      'Sem Sucesso no Contato / Recorrente', 5),
 ('RESET_CREDENTIALS', 'Redefinição de Senha PPPoE', 6)
ON DUPLICATE KEY UPDATE label = VALUES(label);
