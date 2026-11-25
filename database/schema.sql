-- =====================================================================
-- SmartParking - banco de dados adaptado ao projeto (MySQL 8.0.16+)
-- Baseado em: cadastro.php, login.php, index.php, adm.php, suporte.php
-- =====================================================================
CREATE DATABASE IF NOT EXISTS smartparking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE smartparking;

-- ---------------------------------------------------------------------
-- USUÁRIOS  (cadastro.php: nome, email, cpf, senha, telefone | login.php)
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(120) NOT NULL,
  email        VARCHAR(160) NOT NULL UNIQUE,
  cpf          CHAR(11)     NOT NULL UNIQUE,        -- só dígitos (o PHP faz preg_replace)
  telefone     VARCHAR(11)  NOT NULL,               -- só dígitos
  senha_hash   VARCHAR(255) NOT NULL,               -- password_hash() do PHP
  perfil       ENUM('cliente','operador','admin') NOT NULL DEFAULT 'cliente',
  ativo        BOOLEAN NOT NULL DEFAULT TRUE,
  ultimo_login DATETIME NULL,
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_cpf      CHECK (cpf REGEXP '^[0-9]{11}$'),
  CONSTRAINT chk_telefone CHECK (telefone REGEXP '^[0-9]{10,11}$')
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VEÍCULOS  (cadastro.php: modelo = tipo, nome_veiculo, placa)
-- ---------------------------------------------------------------------
CREATE TABLE veiculos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  nome        VARCHAR(80) NOT NULL,                 -- "Nome do veículo"
  placa       CHAR(7) NOT NULL UNIQUE,              -- ABC1D23 (sem hífen, maiúscula)
  tipo        ENUM('combustao','hibrido','eletrico') NOT NULL DEFAULT 'combustao',
  bateria_kwh DECIMAL(5,1) NULL,                    -- simulador usa 60 como padrão
  criado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_placa CHECK (placa REGEXP '^[A-Z0-9]{7}$'),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PISOS e VAGAS  (adm.php: Térreo, Piso 1, 2, 3 | setores A-D | comum/eletrica)
-- ---------------------------------------------------------------------
CREATE TABLE pisos (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome  VARCHAR(30) NOT NULL UNIQUE,
  ordem TINYINT NOT NULL UNIQUE                     -- 0 = térreo
) ENGINE=InnoDB;

CREATE TABLE vagas (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  piso_id INT UNSIGNED NOT NULL,
  codigo  VARCHAR(5) NOT NULL,                      -- A01, B03...
  setor   CHAR(1) NOT NULL,                         -- A, B, C, D
  tipo    ENUM('comum','eletrica','pcd','idoso','moto') NOT NULL DEFAULT 'comum',
  status  ENUM('livre','ocupada','manutencao') NOT NULL DEFAULT 'livre',
  UNIQUE KEY uq_piso_codigo (piso_id, codigo),      -- A01 pode existir em cada piso
  FOREIGN KEY (piso_id) REFERENCES pisos(id)
) ENGINE=InnoDB;

-- Carregador: 1 por vaga do tipo 'eletrica' (index.php: CCS2/Tipo 2, 7-150 kW)
CREATE TABLE carregadores (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vaga_id     INT UNSIGNED NOT NULL UNIQUE,
  conector    ENUM('CCS2','Tipo2') NOT NULL,
  potencia_kw DECIMAL(5,1) NOT NULL,                -- 7.4, 11, 22, 50, 150
  status      ENUM('disponivel','em_uso','defeito') NOT NULL DEFAULT 'disponivel',
  FOREIGN KEY (vaga_id) REFERENCES vagas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE manutencoes_vaga (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vaga_id    INT UNSIGNED NOT NULL,
  motivo     VARCHAR(255) NOT NULL,
  inicio     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fim        DATETIME NULL,
  criado_por INT UNSIGNED NULL,
  FOREIGN KEY (vaga_id)    REFERENCES vagas(id) ON DELETE CASCADE,
  FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TARIFAS e CONFIGURAÇÕES  (Configuracoes.php | simulador: hora iniciada x proporcional)
-- ---------------------------------------------------------------------
CREATE TABLE tarifas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  preco_hora     DECIMAL(8,2) NOT NULL,             -- vagas.js: PRECO_HORA = 6
  preco_kwh      DECIMAL(8,2) NOT NULL,             -- vagas.js: PRECO_KWH = 1.5
  tolerancia_min SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  cobranca       ENUM('hora_iniciada','proporcional') NOT NULL DEFAULT 'hora_iniciada',
  ativa          BOOLEAN NOT NULL DEFAULT TRUE,
  criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE configuracoes (
  chave         VARCHAR(60) PRIMARY KEY,
  valor         VARCHAR(255) NOT NULL,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESERVAS / ESTADIAS
-- ---------------------------------------------------------------------
CREATE TABLE reservas (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id        INT UNSIGNED NOT NULL,
  vaga_id           INT UNSIGNED NOT NULL,
  veiculo_id        INT UNSIGNED NULL,
  tarifa_id         INT UNSIGNED NOT NULL,          -- congela o preço da época da reserva
  inicio            DATETIME NOT NULL,
  fim_previsto      DATETIME NOT NULL,
  entrada_real      DATETIME NULL,
  saida_real        DATETIME NULL,
  usa_carregador    BOOLEAN NOT NULL DEFAULT FALSE,
  bateria_atual_pct TINYINT UNSIGNED NULL,          -- simulador: "Bateria atual (%)"
  bateria_meta_pct  TINYINT UNSIGNED NULL,          -- simulador: "Carga desejada (%)"
  valor_estadia     DECIMAL(10,2) NOT NULL DEFAULT 0,
  valor_carga       DECIMAL(10,2) NOT NULL DEFAULT 0,
  desconto          DECIMAL(10,2) NOT NULL DEFAULT 0,
  valor_total       DECIMAL(10,2) GENERATED ALWAYS AS (valor_estadia + valor_carga - desconto) STORED,
  status            ENUM('ativa','em_andamento','concluida','cancelada','expirada') NOT NULL DEFAULT 'ativa',
  status_pagamento  ENUM('pendente','parcial','pago','isento') NOT NULL DEFAULT 'pendente',
  criado_em         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (vaga_id)    REFERENCES vagas(id),
  FOREIGN KEY (veiculo_id) REFERENCES veiculos(id) ON DELETE SET NULL,
  FOREIGN KEY (tarifa_id)  REFERENCES tarifas(id),
  INDEX idx_vaga_periodo (vaga_id, inicio, fim_previsto),
  INDEX idx_usuario (usuario_id, status),
  CONSTRAINT chk_periodo CHECK (fim_previsto > inicio),
  CONSTRAINT chk_bateria CHECK (
    (bateria_atual_pct IS NULL OR bateria_atual_pct <= 100) AND
    (bateria_meta_pct  IS NULL OR bateria_meta_pct  <= 100))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ENTRADA E SAÍDA  (home: "Controle de entrada e saída" + leitura ANPR de placas)
-- reserva_id NULL = entrada sem reserva / tentativa negada
-- ---------------------------------------------------------------------
CREATE TABLE registros_acesso (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id    INT UNSIGNED NULL,
  veiculo_id    INT UNSIGNED NULL,
  placa_lida    VARCHAR(10) NULL,
  confianca     DECIMAL(5,2) NULL,                  -- % de confiança da leitura ANPR
  imagem_path   VARCHAR(255) NULL,                  -- foto da placa (opcional)
  tipo          ENUM('entrada','saida') NOT NULL,
  origem        ENUM('camera','cancela','app','manual') NOT NULL DEFAULT 'manual',
  autorizado    BOOLEAN NOT NULL DEFAULT TRUE,
  observacao    VARCHAR(255) NULL,
  operador_id   INT UNSIGNED NULL,
  registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reserva_id)  REFERENCES reservas(id) ON DELETE SET NULL,
  FOREIGN KEY (veiculo_id)  REFERENCES veiculos(id) ON DELETE SET NULL,
  FOREIGN KEY (operador_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_reserva (reserva_id),
  INDEX idx_data (registrado_em),
  INDEX idx_placa_data (placa_lida, registrado_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CARREGAMENTO ELÉTRICO  (adm: box "Carregamento Elétrico")
-- ---------------------------------------------------------------------
CREATE TABLE sessoes_carga (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id           INT UNSIGNED NOT NULL,
  carregador_id        INT UNSIGNED NOT NULL,
  inicio               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fim                  DATETIME NULL,
  bateria_inicial_pct  TINYINT UNSIGNED NULL,
  bateria_final_pct    TINYINT UNSIGNED NULL,
  kwh_consumido        DECIMAL(8,2) NOT NULL DEFAULT 0,
  valor                DECIMAL(10,2) NOT NULL DEFAULT 0,
  status               ENUM('em_andamento','finalizada','interrompida') NOT NULL DEFAULT 'em_andamento',
  FOREIGN KEY (reserva_id)    REFERENCES reservas(id) ON DELETE CASCADE,
  FOREIGN KEY (carregador_id) REFERENCES carregadores(id),
  INDEX idx_reserva (reserva_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PAGAMENTOS (a página ainda será feita: o banco já está pronto)
-- ---------------------------------------------------------------------
-- Cartões salvos: SOMENTE token do gateway, nunca o número/CVV
CREATE TABLE cartoes_salvos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  gateway_token VARCHAR(120) NOT NULL,
  bandeira      VARCHAR(20) NOT NULL,               -- visa, mastercard, elo...
  final         CHAR(4) NOT NULL,
  validade      CHAR(5) NOT NULL,                   -- MM/AA
  principal     BOOLEAN NOT NULL DEFAULT FALSE,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pagamentos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id      INT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  cartao_id       INT UNSIGNED NULL,
  metodo          ENUM('pix','cartao_credito','cartao_debito','dinheiro') NULL, -- NULL até o cliente escolher
  valor           DECIMAL(10,2) NOT NULL,
  parcelas        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status          ENUM('pendente','pago','falhou','cancelado','estornado') NOT NULL DEFAULT 'pendente',
  gateway         VARCHAR(30) NULL,                 -- mercadopago, stripe...
  transacao_id    VARCHAR(100) NULL UNIQUE,         -- id no gateway (webhook)
  pix_copia_cola  TEXT NULL,
  pix_expira_em   DATETIME NULL,
  cartao_final    CHAR(4) NULL,
  comprovante     VARCHAR(20) NULL UNIQUE,          -- código do recibo
  motivo_falha    VARCHAR(255) NULL,
  pago_em         DATETIME NULL,
  estornado_em    DATETIME NULL,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (reserva_id) REFERENCES reservas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (cartao_id)  REFERENCES cartoes_salvos(id) ON DELETE SET NULL,
  INDEX idx_reserva_status (reserva_id, status),
  INDEX idx_pago_em (pago_em),
  CONSTRAINT chk_valor_pos CHECK (valor >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SUPORTE (suporte.php: nome, e-mail, dúvida | "recargas EV")
-- ---------------------------------------------------------------------
CREATE TABLE chamados_suporte (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NULL,                  -- NULL se não estiver logado
  nome          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL,
  assunto       ENUM('duvida','recarga_ev','pagamento','outro') NOT NULL DEFAULT 'duvida',
  mensagem      TEXT NOT NULL,
  status        ENUM('aberto','em_andamento','resolvido') NOT NULL DEFAULT 'aberto',
  resposta      TEXT NULL,
  respondido_por INT UNSIGNED NULL,
  respondido_em DATETIME NULL,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY (respondido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICAÇÕES e AUDITORIA
-- ---------------------------------------------------------------------
CREATE TABLE notificacoes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  titulo     VARCHAR(120) NOT NULL,
  mensagem   VARCHAR(500) NOT NULL,
  lida       BOOLEAN NOT NULL DEFAULT FALSE,
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_lida (usuario_id, lida)
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NULL,
  acao        VARCHAR(60) NOT NULL,
  tabela      VARCHAR(60) NULL,
  registro_id BIGINT UNSIGNED NULL,
  detalhes    JSON NULL,
  criado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- DADOS INICIAIS (espelham os arrays de index.php e adm.php)
-- =====================================================================
INSERT INTO tarifas (preco_hora, preco_kwh, tolerancia_min, cobranca)
VALUES (6.00, 1.50, 10, 'hora_iniciada');

INSERT INTO configuracoes (chave, valor) VALUES
('nome_estacionamento',       'SmartParking'),
('eficiencia_carregamento',   '0.90'),   -- simulador (teste.php) usa 90%
('expiracao_reserva_min',     '15'),     -- no-show: reserva vira "expirada"
('pix_expiracao_min',         '15');

INSERT INTO pisos (nome, ordem) VALUES
('Térreo',0), ('Piso 1',1), ('Piso 2',2), ('Piso 3',3);

SET @terreo = (SELECT id FROM pisos WHERE ordem = 0);
SET @piso3  = (SELECT id FROM pisos WHERE ordem = 3);

-- Térreo: as 12 vagas de index.php
INSERT INTO vagas (piso_id, codigo, setor, tipo, status) VALUES
(@terreo,'A01','A','comum','livre'),   (@terreo,'A02','A','comum','ocupada'),
(@terreo,'A03','A','comum','livre'),   (@terreo,'A04','A','comum','livre'),
(@terreo,'A05','A','comum','ocupada'), (@terreo,'A06','A','eletrica','livre'),
(@terreo,'B01','B','comum','livre'),   (@terreo,'B02','B','comum','ocupada'),
(@terreo,'B03','B','eletrica','livre'),(@terreo,'B04','B','comum','livre'),
(@terreo,'B05','B','comum','ocupada'), (@terreo,'B06','B','eletrica','livre');

-- Piso 3: as 24 vagas de adm.php
INSERT INTO vagas (piso_id, codigo, setor, tipo, status) VALUES
(@piso3,'A01','A','comum','livre'),    (@piso3,'A02','A','comum','livre'),
(@piso3,'A03','A','eletrica','livre'), (@piso3,'A04','A','comum','ocupada'),
(@piso3,'A05','A','comum','livre'),    (@piso3,'A06','A','eletrica','livre'),
(@piso3,'B01','B','comum','livre'),    (@piso3,'B02','B','eletrica','ocupada'),
(@piso3,'B03','B','comum','ocupada'),  (@piso3,'B04','B','eletrica','livre'),
(@piso3,'B05','B','comum','livre'),    (@piso3,'B06','B','comum','livre'),
(@piso3,'C01','C','comum','ocupada'),  (@piso3,'C02','C','comum','livre'),
(@piso3,'C03','C','eletrica','ocupada'),(@piso3,'C04','C','comum','livre'),
(@piso3,'C05','C','comum','livre'),    (@piso3,'C06','C','eletrica','livre'),
(@piso3,'D01','D','eletrica','livre'), (@piso3,'D02','D','comum','ocupada'),
(@piso3,'D03','D','eletrica','livre'), (@piso3,'D04','D','comum','livre'),
(@piso3,'D05','D','eletrica','livre'), (@piso3,'D06','D','comum','ocupada');

-- Carregadores: toda vaga elétrica recebe um (padrão Tipo2 22 kW) ...
INSERT INTO carregadores (vaga_id, conector, potencia_kw)
SELECT id, 'Tipo2', 22 FROM vagas WHERE tipo = 'eletrica';

-- ... e alguns variam, como nos cartões do index.php (dados demonstrativos)
UPDATE carregadores c JOIN vagas v ON v.id = c.vaga_id
   SET c.conector = 'CCS2', c.potencia_kw = 50
 WHERE (v.piso_id = @terreo AND v.codigo = 'A06') OR (v.piso_id = @piso3 AND v.codigo = 'A03');
UPDATE carregadores c JOIN vagas v ON v.id = c.vaga_id
   SET c.conector = 'CCS2', c.potencia_kw = 150
 WHERE v.piso_id = @piso3 AND v.codigo = 'D01';
UPDATE carregadores c JOIN vagas v ON v.id = c.vaga_id
   SET c.potencia_kw = 7.4
 WHERE v.piso_id = @piso3 AND v.codigo = 'A06';

-- Usuário admin: gere o hash no PHP e cole aqui (nunca senha pura)
--   php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT);"
-- INSERT INTO usuarios (nome,email,cpf,telefone,senha_hash,perfil)
-- VALUES ('Administrador','admin@smartparking.com','00000000000','11900000000','<HASH>','admin');

-- =====================================================================
-- VIEWS  (alimentam os blocos do adm.php e da home)
-- =====================================================================
-- Mapa de vagas (grid do index.php e do adm.php, por piso)
CREATE VIEW vw_mapa_vagas AS
SELECT v.id AS vaga_id, p.id AS piso_id, p.nome AS piso, v.codigo, v.setor, v.tipo, v.status,
       c.conector, c.potencia_kw, c.status AS carregador_status
FROM vagas v
JOIN pisos p ON p.id = v.piso_id
LEFT JOIN carregadores c ON c.vaga_id = v.id;

-- Cards: Total / Livres / Ocupadas / Carregadores
CREATE VIEW vw_resumo_vagas AS
SELECT COUNT(*)                         AS total,
       SUM(status = 'livre')            AS livres,
       SUM(status = 'ocupada')          AS ocupadas,
       SUM(status = 'manutencao')       AS manutencao,
       SUM(tipo = 'eletrica')           AS eletricas,
       (SELECT COUNT(*) FROM carregadores WHERE status = 'em_uso') AS carregadores_em_uso,
       ROUND(100 * SUM(status = 'ocupada') / COUNT(*)) AS pct_ocupacao
FROM vagas;

CREATE VIEW vw_ocupacao_piso AS
SELECT p.id AS piso_id, p.nome AS piso, COUNT(v.id) AS total,
       SUM(v.status = 'livre') AS livres, SUM(v.status = 'ocupada') AS ocupadas,
       ROUND(100 * SUM(v.status = 'ocupada') / COUNT(v.id)) AS pct_ocupacao
FROM pisos p LEFT JOIN vagas v ON v.piso_id = p.id
GROUP BY p.id, p.nome;

-- "Faturamento do Dia": acumulado por hora (gráfico do adm)
CREATE VIEW vw_faturamento_hoje_por_hora AS
SELECT HOUR(pago_em) AS hora,
       SUM(valor)    AS total_hora,
       SUM(SUM(valor)) OVER (ORDER BY HOUR(pago_em)) AS acumulado
FROM pagamentos
WHERE status = 'pago' AND DATE(pago_em) = CURDATE()
GROUP BY HOUR(pago_em);

-- Financeiro: por dia e por método (compare hoje x ontem no PHP)
CREATE VIEW vw_faturamento_diario AS
SELECT DATE(pago_em) AS dia, metodo, COUNT(*) AS qtd_pagamentos, SUM(valor) AS total
FROM pagamentos
WHERE status = 'pago'
GROUP BY DATE(pago_em), metodo;

-- "Tempo Médio de Permanência" (últimos 7 dias x 7 dias anteriores)
CREATE VIEW vw_tempo_medio_permanencia AS
SELECT
  AVG(CASE WHEN saida_real >= NOW() - INTERVAL 7 DAY
           THEN TIMESTAMPDIFF(MINUTE, entrada_real, saida_real) END) AS media_min_7d,
  AVG(CASE WHEN saida_real <  NOW() - INTERVAL 7 DAY
            AND saida_real >= NOW() - INTERVAL 14 DAY
           THEN TIMESTAMPDIFF(MINUTE, entrada_real, saida_real) END) AS media_min_7d_anterior
FROM reservas
WHERE status = 'concluida' AND saida_real >= NOW() - INTERVAL 14 DAY;

-- Base para "Previsão de Lotação": média de entradas por dia da semana e hora
CREATE VIEW vw_lotacao_historica AS
SELECT DAYOFWEEK(registrado_em) AS dia_semana,   -- 1 = domingo
       HOUR(registrado_em)      AS hora,
       COUNT(*) / COUNT(DISTINCT DATE(registrado_em)) AS media_entradas
FROM registros_acesso
WHERE tipo = 'entrada' AND autorizado = TRUE
GROUP BY DAYOFWEEK(registrado_em), HOUR(registrado_em);

-- "Carregamento Elétrico" / relatório de sustentabilidade
CREATE VIEW vw_energia_diaria AS
SELECT DATE(inicio) AS dia, COUNT(*) AS sessoes,
       SUM(kwh_consumido) AS kwh_total, SUM(valor) AS receita_carga
FROM sessoes_carga
WHERE status IN ('finalizada','interrompida')
GROUP BY DATE(inicio);

-- Saldo de cada reserva (página de pagamento)
CREATE VIEW vw_saldo_reservas AS
SELECT r.id AS reserva_id, r.usuario_id, r.status, r.valor_total,
       COALESCE(p.pago, 0) AS valor_pago,
       r.valor_total - COALESCE(p.pago, 0) AS saldo,
       r.status_pagamento
FROM reservas r
LEFT JOIN (SELECT reserva_id, SUM(valor) AS pago
           FROM pagamentos WHERE status = 'pago' GROUP BY reserva_id) p
       ON p.reserva_id = r.id;

-- "Últimos Eventos" do adm (use: SELECT * FROM vw_ultimos_eventos ORDER BY ocorrido_em DESC LIMIT 10)
CREATE VIEW vw_ultimos_eventos AS
SELECT a.registrado_em AS ocorrido_em, a.tipo AS evento,
       CONCAT(COALESCE(a.placa_lida, v.placa, '-'), IF(a.autorizado, '', ' (negado)')) AS descricao,
       a.reserva_id
FROM registros_acesso a LEFT JOIN veiculos v ON v.id = a.veiculo_id
UNION ALL
SELECT p.pago_em, 'pagamento', CONCAT('R$ ', FORMAT(p.valor, 2)), p.reserva_id
FROM pagamentos p WHERE p.status = 'pago'
UNION ALL
SELECT s.inicio, 'carga_inicio', CONCAT('Carregador #', s.carregador_id), s.reserva_id
FROM sessoes_carga s
UNION ALL
SELECT s.fim, 'carga_fim', CONCAT(s.kwh_consumido, ' kWh'), s.reserva_id
FROM sessoes_carga s WHERE s.fim IS NOT NULL;

-- =====================================================================
-- FUNÇÃO e PROCEDURES
-- =====================================================================
DELIMITER $$

-- Cálculo da estadia conforme a tarifa (tolerância + hora iniciada/proporcional)
CREATE FUNCTION fn_calcular_estadia(p_minutos INT, p_tarifa_id INT UNSIGNED)
RETURNS DECIMAL(10,2)
READS SQL DATA
BEGIN
  DECLARE v_preco DECIMAL(8,2);
  DECLARE v_tol   SMALLINT UNSIGNED;
  DECLARE v_cob   VARCHAR(20);

  SELECT preco_hora, tolerancia_min, cobranca INTO v_preco, v_tol, v_cob
    FROM tarifas WHERE id = p_tarifa_id;

  IF v_preco IS NULL OR p_minutos <= v_tol THEN
    RETURN 0;
  END IF;
  IF v_cob = 'proporcional' THEN
    RETURN ROUND(p_minutos / 60 * v_preco, 2);
  END IF;
  RETURN CEIL(p_minutos / 60) * v_preco;
END$$

-- ---------- CADASTRO (cadastro.php): usuário + veículo numa transação ----------
CREATE PROCEDURE sp_cadastrar_usuario(
  IN  p_nome VARCHAR(120), IN p_email VARCHAR(160), IN p_cpf CHAR(11),
  IN  p_telefone VARCHAR(11), IN p_senha_hash VARCHAR(255),
  IN  p_tipo_veiculo VARCHAR(10),      -- 'eletrico' | 'combustao' | 'hibrido'
  IN  p_nome_veiculo VARCHAR(80), IN p_placa CHAR(7),
  OUT p_usuario_id INT UNSIGNED
)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;          -- erro 1062 = e-mail, CPF ou placa já cadastrados
  END;

  START TRANSACTION;
  INSERT INTO usuarios (nome, email, cpf, telefone, senha_hash)
  VALUES (p_nome, p_email, p_cpf, p_telefone, p_senha_hash);
  SET p_usuario_id = LAST_INSERT_ID();

  INSERT INTO veiculos (usuario_id, nome, placa, tipo, bateria_kwh)
  VALUES (p_usuario_id, p_nome_veiculo, p_placa, p_tipo_veiculo,
          IF(p_tipo_veiculo = 'combustao', NULL, 60.0));   -- 60 kWh = padrão do simulador
  COMMIT;
END$$

-- ---------- RESERVA (index.php: "Escolher vaga" + simulador) ----------
CREATE PROCEDURE sp_criar_reserva(
  IN  p_usuario_id INT UNSIGNED, IN p_vaga_id INT UNSIGNED, IN p_veiculo_id INT UNSIGNED,
  IN  p_inicio DATETIME, IN p_fim DATETIME, IN p_usa_carregador BOOLEAN,
  IN  p_bateria_atual TINYINT UNSIGNED, IN p_bateria_meta TINYINT UNSIGNED,
  OUT p_reserva_id INT UNSIGNED
)
BEGIN
  DECLARE v_ativo BOOLEAN;
  DECLARE v_status VARCHAR(20);
  DECLARE v_tipo_vaga VARCHAR(20);
  DECLARE v_tipo_veic VARCHAR(20);
  DECLARE v_carr_status VARCHAR(20);
  DECLARE v_conflitos INT;
  DECLARE v_tarifa_id INT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  SELECT ativo INTO v_ativo FROM usuarios WHERE id = p_usuario_id;
  IF v_ativo IS NULL OR v_ativo = FALSE THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário inexistente ou inativo';
  END IF;

  IF p_veiculo_id IS NOT NULL THEN
    SELECT tipo INTO v_tipo_veic FROM veiculos
     WHERE id = p_veiculo_id AND usuario_id = p_usuario_id;
    IF v_tipo_veic IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Veículo não pertence ao usuário';
    END IF;
  END IF;

  SELECT status, tipo INTO v_status, v_tipo_vaga
    FROM vagas WHERE id = p_vaga_id FOR UPDATE;      -- trava a vaga
  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Vaga inexistente';
  END IF;
  IF v_status = 'manutencao' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Vaga em manutenção';
  END IF;

  IF p_usa_carregador THEN
    IF v_tipo_vaga <> 'eletrica' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta vaga não possui carregador';
    END IF;
    IF v_tipo_veic = 'combustao' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Veículo a combustão não pode usar carregador';
    END IF;
    SELECT status INTO v_carr_status FROM carregadores WHERE vaga_id = p_vaga_id;
    IF v_carr_status = 'defeito' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carregador com defeito';
    END IF;
  END IF;

  SELECT COUNT(*) INTO v_conflitos FROM reservas
   WHERE vaga_id = p_vaga_id AND status IN ('ativa','em_andamento')
     AND inicio < p_fim AND fim_previsto > p_inicio;
  IF v_conflitos > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Vaga já reservada neste período';
  END IF;

  SELECT id INTO v_tarifa_id FROM tarifas WHERE ativa = TRUE ORDER BY id DESC LIMIT 1;

  INSERT INTO reservas (usuario_id, vaga_id, veiculo_id, tarifa_id, inicio, fim_previsto,
                        usa_carregador, bateria_atual_pct, bateria_meta_pct, valor_estadia)
  VALUES (p_usuario_id, p_vaga_id, p_veiculo_id, v_tarifa_id, p_inicio, p_fim,
          p_usa_carregador, p_bateria_atual, p_bateria_meta,
          fn_calcular_estadia(TIMESTAMPDIFF(MINUTE, p_inicio, p_fim), v_tarifa_id));

  SET p_reserva_id = LAST_INSERT_ID();
  COMMIT;
END$$

CREATE PROCEDURE sp_cancelar_reserva(IN p_reserva_id INT UNSIGNED, IN p_usuario_id INT UNSIGNED)
BEGIN
  UPDATE reservas SET status = 'cancelada'
   WHERE id = p_reserva_id AND usuario_id = p_usuario_id AND status = 'ativa';
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva não pode ser cancelada';
  END IF;
  -- Se já houve pagamento, o admin estorna com sp_estornar_pagamento
END$$

-- ---------- ENTRADA ----------
CREATE PROCEDURE sp_registrar_entrada(
  IN p_reserva_id INT UNSIGNED, IN p_origem VARCHAR(10), IN p_operador_id INT UNSIGNED
)
BEGIN
  DECLARE v_vaga_id INT UNSIGNED;
  DECLARE v_veiculo_id INT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT vaga_id, veiculo_id INTO v_vaga_id, v_veiculo_id
    FROM reservas WHERE id = p_reserva_id AND status = 'ativa' FOR UPDATE;
  IF v_vaga_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva não encontrada ou já utilizada';
  END IF;

  UPDATE reservas SET entrada_real = NOW(), status = 'em_andamento' WHERE id = p_reserva_id;
  UPDATE vagas    SET status = 'ocupada' WHERE id = v_vaga_id;
  INSERT INTO registros_acesso (reserva_id, veiculo_id, tipo, origem, operador_id)
  VALUES (p_reserva_id, v_veiculo_id, 'entrada', p_origem, p_operador_id);
  COMMIT;
END$$

-- Entrada pela câmera ANPR: acha a reserva pela placa (janela: 15 min antes do início)
CREATE PROCEDURE sp_entrada_por_placa(
  IN p_placa VARCHAR(10), IN p_confianca DECIMAL(5,2), IN p_origem VARCHAR(10),
  OUT p_reserva_id INT UNSIGNED
)
BEGIN
  SET p_reserva_id = NULL;

  SELECT r.id INTO p_reserva_id
    FROM reservas r JOIN veiculos v ON v.id = r.veiculo_id
   WHERE v.placa = p_placa AND r.status = 'ativa'
     AND NOW() BETWEEN r.inicio - INTERVAL 15 MINUTE AND r.fim_previsto
   ORDER BY r.inicio LIMIT 1;

  IF p_reserva_id IS NULL THEN
    INSERT INTO registros_acesso (placa_lida, confianca, tipo, origem, autorizado, observacao)
    VALUES (p_placa, p_confianca, 'entrada', p_origem, FALSE, 'Sem reserva ativa para a placa');
  ELSE
    CALL sp_registrar_entrada(p_reserva_id, p_origem, NULL);
    UPDATE registros_acesso SET placa_lida = p_placa, confianca = p_confianca
     WHERE reserva_id = p_reserva_id AND tipo = 'entrada' ORDER BY id DESC LIMIT 1;
  END IF;
END$$

-- ---------- CARREGAMENTO ----------
CREATE PROCEDURE sp_iniciar_carga(
  IN p_reserva_id INT UNSIGNED, IN p_bateria_inicial TINYINT UNSIGNED,
  OUT p_sessao_id INT UNSIGNED
)
BEGIN
  DECLARE v_vaga_id INT UNSIGNED;
  DECLARE v_carr_id INT UNSIGNED;
  DECLARE v_carr_status VARCHAR(20);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT vaga_id INTO v_vaga_id FROM reservas
   WHERE id = p_reserva_id AND status = 'em_andamento' AND usa_carregador = TRUE FOR UPDATE;
  IF v_vaga_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva sem carregamento ou fora de andamento';
  END IF;

  SELECT id, status INTO v_carr_id, v_carr_status FROM carregadores WHERE vaga_id = v_vaga_id FOR UPDATE;
  IF v_carr_id IS NULL OR v_carr_status <> 'disponivel' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carregador indisponível';
  END IF;

  INSERT INTO sessoes_carga (reserva_id, carregador_id, bateria_inicial_pct)
  VALUES (p_reserva_id, v_carr_id, p_bateria_inicial);
  SET p_sessao_id = LAST_INSERT_ID();
  UPDATE carregadores SET status = 'em_uso' WHERE id = v_carr_id;
  COMMIT;
END$$

CREATE PROCEDURE sp_finalizar_carga(
  IN p_sessao_id INT UNSIGNED, IN p_kwh DECIMAL(8,2), IN p_bateria_final TINYINT UNSIGNED
)
BEGIN
  DECLARE v_carr_id INT UNSIGNED;
  DECLARE v_reserva_id INT UNSIGNED;
  DECLARE v_preco_kwh DECIMAL(8,2);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT s.carregador_id, s.reserva_id, t.preco_kwh INTO v_carr_id, v_reserva_id, v_preco_kwh
    FROM sessoes_carga s
    JOIN reservas r ON r.id = s.reserva_id
    JOIN tarifas  t ON t.id = r.tarifa_id
   WHERE s.id = p_sessao_id AND s.status = 'em_andamento' FOR UPDATE;
  IF v_carr_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sessão de carga não encontrada ou já encerrada';
  END IF;

  UPDATE sessoes_carga
     SET fim = NOW(), kwh_consumido = p_kwh, bateria_final_pct = p_bateria_final,
         valor = ROUND(p_kwh * v_preco_kwh, 2), status = 'finalizada'
   WHERE id = p_sessao_id;
  UPDATE carregadores SET status = 'disponivel' WHERE id = v_carr_id;
  UPDATE reservas SET valor_carga = (SELECT COALESCE(SUM(valor),0) FROM sessoes_carga WHERE reserva_id = v_reserva_id)
   WHERE id = v_reserva_id;
  COMMIT;
END$$

-- ---------- STATUS DE PAGAMENTO DA RESERVA (usado pelas procedures abaixo) ----------
CREATE PROCEDURE sp_atualizar_status_pagamento(IN p_reserva_id INT UNSIGNED)
BEGIN
  DECLARE v_total DECIMAL(10,2);
  DECLARE v_pago  DECIMAL(10,2);

  SELECT valor_total INTO v_total FROM reservas WHERE id = p_reserva_id;
  SELECT COALESCE(SUM(valor), 0) INTO v_pago FROM pagamentos
   WHERE reserva_id = p_reserva_id AND status = 'pago';

  UPDATE reservas SET status_pagamento = CASE
      WHEN v_total = 0      THEN 'isento'
      WHEN v_pago >= v_total THEN 'pago'
      WHEN v_pago > 0        THEN 'parcial'
      ELSE 'pendente' END
   WHERE id = p_reserva_id;
END$$

-- ---------- SAÍDA: fecha a conta (o pagamento é feito na página) ----------
CREATE PROCEDURE sp_registrar_saida(
  IN p_reserva_id INT UNSIGNED, IN p_origem VARCHAR(10), IN p_operador_id INT UNSIGNED
)
BEGIN
  DECLARE v_vaga_id INT UNSIGNED;
  DECLARE v_veiculo_id INT UNSIGNED;
  DECLARE v_tarifa_id INT UNSIGNED;
  DECLARE v_entrada DATETIME;
  DECLARE v_carga DECIMAL(10,2);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT vaga_id, veiculo_id, tarifa_id, entrada_real
    INTO v_vaga_id, v_veiculo_id, v_tarifa_id, v_entrada
    FROM reservas WHERE id = p_reserva_id AND status = 'em_andamento' FOR UPDATE;
  IF v_vaga_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva não está em andamento';
  END IF;

  -- encerra carga ainda aberta (cobra o kWh já registrado)
  UPDATE carregadores c JOIN sessoes_carga s ON s.carregador_id = c.id
     SET c.status = 'disponivel'
   WHERE s.reserva_id = p_reserva_id AND s.status = 'em_andamento';
  UPDATE sessoes_carga s
    JOIN reservas r ON r.id = s.reserva_id
    JOIN tarifas  t ON t.id = r.tarifa_id
     SET s.fim = NOW(), s.status = 'interrompida', s.valor = ROUND(s.kwh_consumido * t.preco_kwh, 2)
   WHERE s.reserva_id = p_reserva_id AND s.status = 'em_andamento';

  SELECT COALESCE(SUM(valor), 0) INTO v_carga FROM sessoes_carga WHERE reserva_id = p_reserva_id;

  UPDATE reservas
     SET saida_real = NOW(),
         valor_estadia = fn_calcular_estadia(TIMESTAMPDIFF(MINUTE, v_entrada, NOW()), v_tarifa_id),
         valor_carga = v_carga,
         status = 'concluida'
   WHERE id = p_reserva_id;

  UPDATE vagas SET status = 'livre' WHERE id = v_vaga_id;
  INSERT INTO registros_acesso (reserva_id, veiculo_id, tipo, origem, operador_id)
  VALUES (p_reserva_id, v_veiculo_id, 'saida', p_origem, p_operador_id);

  CALL sp_atualizar_status_pagamento(p_reserva_id);
  COMMIT;
END$$

-- ---------- PAGAMENTO ----------
-- 1) A página chama ao clicar em "Pagar": cria a cobrança do SALDO da reserva
CREATE PROCEDURE sp_iniciar_pagamento(
  IN  p_reserva_id INT UNSIGNED, IN p_usuario_id INT UNSIGNED,
  IN  p_metodo VARCHAR(20),              -- 'pix','cartao_credito','cartao_debito','dinheiro'
  IN  p_cartao_id INT UNSIGNED, IN p_parcelas TINYINT UNSIGNED,
  OUT p_pagamento_id INT UNSIGNED
)
BEGIN
  DECLARE v_total DECIMAL(10,2);
  DECLARE v_status VARCHAR(20);
  DECLARE v_pago DECIMAL(10,2);
  DECLARE v_saldo DECIMAL(10,2);
  DECLARE v_pix_min INT DEFAULT 15;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT valor_total, status INTO v_total, v_status FROM reservas
   WHERE id = p_reserva_id AND usuario_id = p_usuario_id FOR UPDATE;
  IF v_total IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva não encontrada';
  END IF;
  IF v_status IN ('cancelada','expirada') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva cancelada/expirada não gera cobrança';
  END IF;

  SELECT COALESCE(SUM(valor), 0) INTO v_pago FROM pagamentos
   WHERE reserva_id = p_reserva_id AND status = 'pago';
  SET v_saldo = v_total - v_pago;
  IF v_saldo <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserva já quitada';
  END IF;

  -- cancela tentativas pendentes anteriores (ex.: trocou PIX por cartão)
  UPDATE pagamentos SET status = 'cancelado'
   WHERE reserva_id = p_reserva_id AND status = 'pendente';

  SELECT CAST(valor AS UNSIGNED) INTO v_pix_min FROM configuracoes WHERE chave = 'pix_expiracao_min';

  INSERT INTO pagamentos (reserva_id, usuario_id, cartao_id, metodo, valor, parcelas, pix_expira_em)
  VALUES (p_reserva_id, p_usuario_id, p_cartao_id, p_metodo, v_saldo,
          IF(p_metodo = 'cartao_credito', COALESCE(p_parcelas, 1), 1),
          IF(p_metodo = 'pix', NOW() + INTERVAL v_pix_min MINUTE, NULL));
  SET p_pagamento_id = LAST_INSERT_ID();
  COMMIT;
END$$

-- 2) Webhook do gateway (ou operador, no caso de dinheiro): confirma o pagamento
CREATE PROCEDURE sp_confirmar_pagamento(
  IN p_pagamento_id INT UNSIGNED, IN p_transacao_id VARCHAR(100),
  IN p_gateway VARCHAR(30), IN p_cartao_final CHAR(4)
)
BEGIN
  DECLARE v_reserva_id INT UNSIGNED;
  DECLARE v_usuario_id INT UNSIGNED;
  DECLARE v_valor DECIMAL(10,2);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT reserva_id, usuario_id, valor INTO v_reserva_id, v_usuario_id, v_valor
    FROM pagamentos WHERE id = p_pagamento_id AND status = 'pendente' FOR UPDATE;
  IF v_reserva_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pagamento não encontrado ou não está pendente';
  END IF;

  UPDATE pagamentos
     SET status = 'pago', pago_em = NOW(), transacao_id = p_transacao_id, gateway = p_gateway,
         cartao_final = p_cartao_final,
         comprovante = UPPER(SUBSTRING(REPLACE(UUID(), '-', ''), 1, 12))
   WHERE id = p_pagamento_id;

  CALL sp_atualizar_status_pagamento(v_reserva_id);

  INSERT INTO notificacoes (usuario_id, titulo, mensagem)
  VALUES (v_usuario_id, 'Pagamento confirmado',
          CONCAT('Recebemos R$ ', FORMAT(v_valor, 2), ' da reserva #', v_reserva_id, '.'));
  COMMIT;
END$$

-- 3) Cartão recusado / erro do gateway
CREATE PROCEDURE sp_registrar_falha_pagamento(IN p_pagamento_id INT UNSIGNED, IN p_motivo VARCHAR(255))
BEGIN
  UPDATE pagamentos SET status = 'falhou', motivo_falha = p_motivo
   WHERE id = p_pagamento_id AND status = 'pendente';
END$$

-- 4) Estorno (admin)
CREATE PROCEDURE sp_estornar_pagamento(IN p_pagamento_id INT UNSIGNED, IN p_admin_id INT UNSIGNED)
BEGIN
  DECLARE v_reserva_id INT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT reserva_id INTO v_reserva_id FROM pagamentos
   WHERE id = p_pagamento_id AND status = 'pago' FOR UPDATE;
  IF v_reserva_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só é possível estornar pagamentos pagos';
  END IF;

  UPDATE pagamentos SET status = 'estornado', estornado_em = NOW() WHERE id = p_pagamento_id;
  CALL sp_atualizar_status_pagamento(v_reserva_id);
  INSERT INTO auditoria (usuario_id, acao, tabela, registro_id)
  VALUES (p_admin_id, 'pagamento_estornado', 'pagamentos', p_pagamento_id);
  COMMIT;
END$$

-- ---------- ADMIN: nova tarifa (Configuracoes.php) ----------
CREATE PROCEDURE sp_definir_tarifa(
  IN p_preco_hora DECIMAL(8,2), IN p_preco_kwh DECIMAL(8,2),
  IN p_tolerancia SMALLINT UNSIGNED, IN p_cobranca VARCHAR(20), IN p_admin_id INT UNSIGNED
)
BEGIN
  DECLARE v_id INT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  UPDATE tarifas SET ativa = FALSE WHERE ativa = TRUE;   -- reservas antigas mantêm o tarifa_id delas
  INSERT INTO tarifas (preco_hora, preco_kwh, tolerancia_min, cobranca)
  VALUES (p_preco_hora, p_preco_kwh, p_tolerancia, p_cobranca);
  SET v_id = LAST_INSERT_ID();
  INSERT INTO auditoria (usuario_id, acao, tabela, registro_id, detalhes)
  VALUES (p_admin_id, 'tarifa_alterada', 'tarifas', v_id,
          JSON_OBJECT('preco_hora', p_preco_hora, 'preco_kwh', p_preco_kwh,
                      'tolerancia_min', p_tolerancia, 'cobranca', p_cobranca));
  COMMIT;
END$$

-- ---------- EVENTO: no-show e PIX vencido (SET GLOBAL event_scheduler = ON;) ----------
CREATE EVENT IF NOT EXISTS ev_manutencao_periodica
ON SCHEDULE EVERY 5 MINUTE
DO
BEGIN
  DECLARE v_min INT DEFAULT 15;
  SELECT CAST(valor AS UNSIGNED) INTO v_min FROM configuracoes WHERE chave = 'expiracao_reserva_min';

  UPDATE reservas SET status = 'expirada'
   WHERE status = 'ativa' AND entrada_real IS NULL
     AND inicio < NOW() - INTERVAL v_min MINUTE;

  UPDATE pagamentos SET status = 'cancelado'
   WHERE status = 'pendente' AND metodo = 'pix' AND pix_expira_em < NOW();
END$$

DELIMITER ;

-- =====================================================================
-- EXEMPLO DE FLUXO
-- =====================================================================
-- CALL sp_cadastrar_usuario('Maria','maria@email.com','12345678901','11999998888','<hash>','eletrico','Meu Carro','ABC1D23',@uid);
-- CALL sp_criar_reserva(@uid, 3, 1, '2026-11-24 14:00:00', '2026-11-24 16:00:00', TRUE, 25, 80, @rid);
-- CALL sp_registrar_entrada(@rid, 'camera', NULL);
-- CALL sp_iniciar_carga(@rid, 25, @sessao);
-- CALL sp_finalizar_carga(@sessao, 13.30, 47);
-- CALL sp_registrar_saida(@rid, 'cancela', NULL);
-- CALL sp_iniciar_pagamento(@rid, @uid, 'pix', NULL, 1, @pgto);
-- CALL sp_confirmar_pagamento(@pgto, 'TX-123', 'mercadopago', NULL);