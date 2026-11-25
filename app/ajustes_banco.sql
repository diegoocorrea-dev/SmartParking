-- OPCIONAL. O site funciona sem isto (usa 60 kWh de bateria e 22 kW como padrão).
-- Rode se quiser mudar esses dois valores pelo banco, sem mexer no código.
USE smartparking;
INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('bateria_padrao_kwh', '60'),
('potencia_padrao_kw', '22');
