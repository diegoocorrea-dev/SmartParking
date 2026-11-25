<?php
require_once 'vagas_dados.php';

// Guarda a vaga escolhida: o login perde o ?vaga= na volta
$vagaId = (int) ($_GET['vaga'] ?? $_POST['vaga'] ?? 0);
if ($vagaId > 0) {
    $_SESSION['vaga_pendente'] = $vagaId;
}
exigir_login();
if ($vagaId <= 0) {
    $vagaId = (int) ($_SESSION['vaga_pendente'] ?? 0);
}

$usuario = usuario_logado();
$vaga = $vagaId > 0 ? buscar_vaga($vagaId) : null;
$cfg = config_preco();
$veiculos = [];
$erro = '';
$reserva = null;   // dados da reserva criada

if ($vaga) {
    try {
        $st = db()->prepare('SELECT id, nome, placa, tipo FROM veiculos WHERE usuario_id = :u ORDER BY nome');
        $st->execute([':u' => $usuario['id']]);
        $veiculos = $st->fetchAll();
    } catch (PDOException $ex) {
        error_log('SmartParking - veiculos: ' . $ex->getMessage());
    }
}

if ($vaga && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $veiculoId = (int) post_str('veiculo');
    $horas = (int) post_str('horas');
    $usaCarga = $vaga['tem_carregador'] && post_str('carregador') === '1';
    $bAtual = $usaCarga ? (int) post_str('bateria_atual') : null;
    $bMeta = $usaCarga ? (int) post_str('bateria_meta') : null;

    $idsDoUsuario = array_map(function ($v) {
        return (int) $v['id']; }, $veiculos);

    if (!csrf_ok(post_str('csrf'))) {
        $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif ($vaga['status'] !== 'livre') {
        $erro = 'Essa vaga não está livre no momento.';
    } elseif ($horas < 1 || $horas > 12) {
        $erro = 'Escolha entre 1 e 12 horas.';
    } elseif ($veiculos && !in_array($veiculoId, $idsDoUsuario, true)) {
        $erro = 'Escolha um dos seus veículos.';
    } elseif ($usaCarga && ($bAtual < 0 || $bAtual > 100 || $bMeta < 1 || $bMeta > 100 || $bMeta <= $bAtual)) {
        $erro = 'Informe a bateria atual e a carga desejada (a desejada deve ser maior que a atual).';
    } else {
        $inicio = date('Y-m-d H:i:s');
        $fim = date('Y-m-d H:i:s', time() + $horas * 3600);
        try {
            $pdo = db();
            $pdo->prepare('CALL sp_criar_reserva(?, ?, ?, ?, ?, ?, ?, ?, @reserva_id)')
                ->execute([
                    $usuario['id'],
                    $vaga['id'],
                    $veiculos ? $veiculoId : null,
                    $inicio,
                    $fim,
                    $usaCarga ? 1 : 0,
                    $bAtual,
                    $bMeta,
                ]);
            $rid = (int) $pdo->query('SELECT @reserva_id')->fetchColumn();
            $st = $pdo->prepare('SELECT id, inicio, fim_previsto, valor_estadia FROM reservas WHERE id = :id');
            $st->execute([':id' => $rid]);
            $reserva = $st->fetch();
            if ($reserva) {
                unset($_SESSION['vaga_pendente']);
                redirecionar('pagamento.php?reserva=' . $rid);
            }
        } catch (PDOException $ex) {
            // as regras da procedure (vaga em manutenção, já reservada, veículo sem carregador...) vêm com SQLSTATE 45000
            if ($ex->getCode() === '45000') {
                $erro = $ex->errorInfo[2] ?? 'Não foi possível reservar essa vaga.';
            } else {
                error_log('SmartParking - sp_criar_reserva: ' . $ex->getMessage());
                $erro = 'Não foi possível concluir a reserva agora. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservar vaga | SmartParking</title>
    <style>
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: #eefafa;
            color: #12263f;
            margin: 0;
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 24px 0;
        }

        .card {
            background: #fff;
            border-radius: 18px;
            padding: 32px;
            width: min(460px, 92vw);
            box-shadow: 0 18px 40px -20px rgba(18, 38, 63, .35);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        dl {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px 16px;
            margin: 20px 0;
        }

        dt {
            color: #788995;
        }

        dd {
            margin: 0;
            font-weight: 600;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 600;
            font-size: 14px;
        }

        select,
        input[type=number] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d3dde1;
            border-radius: 8px;
            font: inherit;
            box-sizing: border-box;
        }

        .linha {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            font-weight: 600;
        }

        .check input {
            width: auto;
        }

        .btn {
            display: block;
            width: 100%;
            margin-top: 20px;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            background: #16c9a6;
            color: #02132a;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
        }

        .msg-erro {
            background: #fdecef;
            color: #b4233a;
            padding: 10px 12px;
            border-radius: 8px;
            margin: 14px 0;
        }

        .msg-ok {
            background: #e3fbf8;
            color: #0b7a69;
            padding: 10px 12px;
            border-radius: 8px;
            margin: 14px 0;
        }

        .voltar {
            display: block;
            margin-top: 14px;
            text-align: center;
            color: #12263f;
        }

        small {
            color: #788995;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>Reservar vaga</h1>
        <?php if ($erro): ?>
            <div class="msg-erro"><?= e($erro) ?></div><?php endif; ?>

        <?php if (!$vaga): ?>
            <p>Não encontramos essa vaga. Volte e escolha uma vaga no mapa.</p>
            <a class="btn" href="index.php#vagas">Ver vagas</a>
        <?php else: ?>
            <dl>
                <dt>Vaga</dt>
                <dd><?= e($vaga['codigo']) ?></dd>
                <dt>Piso</dt>
                <dd><?= e($vaga['piso']) ?></dd>
                <dt>Tipo</dt>
                <dd><?= e(rotulo_categoria($vaga['categoria'])) ?></dd>
                <?php if ($vaga['tem_carregador']): ?>
                    <dt>Carregador</dt>
                    <dd><?= e(rotulo_conector($vaga['conector'])) ?> · <?= e(fmt_kw($vaga['potencia_kw'])) ?> kW</dd>
                <?php endif; ?>
                <dt>Situação</dt>
                <dd><?= e(rotulo_status($vaga['status'])) ?></dd>
            </dl>

            <?php if ($reserva): ?>
                <div class="msg-ok">
                    Reserva #<?= (int) $reserva['id'] ?> confirmada!<br>
                    Das <?= e(date('H:i', strtotime($reserva['inicio']))) ?> às
                    <?= e(date('H:i d/m', strtotime($reserva['fim_previsto']))) ?>
                    · estadia prevista: <?= e(brl((float) $reserva['valor_estadia'])) ?>
                </div>
            <?php elseif ($vaga['status'] === 'livre'): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="vaga" value="<?= $vaga['id'] ?>">

                    <?php if ($veiculos): ?>
                        <label for="veiculo">Veículo</label>
                        <select name="veiculo" id="veiculo" required>
                            <?php foreach ($veiculos as $vei): ?>
                                <option value="<?= (int) $vei['id'] ?>"><?= e($vei['nome']) ?> · <?= e($vei['placa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <p><small>Você ainda não tem veículo cadastrado; a reserva será feita sem veículo.</small></p>
                    <?php endif; ?>

                    <label for="horas">Tempo de permanência</label>
                    <select name="horas" id="horas">
                        <?php for ($h = 1; $h <= 12; $h++): ?>
                            <option value="<?= $h ?>" <?= $h === 3 ? 'selected' : '' ?>><?= $h ?> h ·
                                <?= e(brl($cfg['preco_hora'] * $h)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <small>Valor da estadia: <?= e(brl($cfg['preco_hora'])) ?> por hora.</small>

                    <?php if ($vaga['tem_carregador']): ?>
                        <label class="check"><input type="checkbox" name="carregador" value="1" id="usaCarga"> Usar o carregador
                            (<?= e(brl($cfg['preco_kwh'])) ?>/kWh)</label>
                        <div class="linha" id="camposCarga" hidden>
                            <div><label for="ba">Bateria atual (%)</label><input type="number" name="bateria_atual" id="ba" min="0"
                                    max="99" value="20"></div>
                            <div><label for="bm">Carga desejada (%)</label><input type="number" name="bateria_meta" id="bm" min="1"
                                    max="100" value="80"></div>
                        </div>
                        <script>
                            document.getElementById('usaCarga').addEventListener('change', function () {
                                document.getElementById('camposCarga').hidden = !this.checked;
                            });
                        </script>
                    <?php endif; ?>

                    <button type="submit" class="btn">Confirmar reserva</button>
                </form>
            <?php else: ?>
                <p>Essa vaga não está disponível agora. Escolha outra no mapa.</p>
            <?php endif; ?>
            <a class="voltar" href="index.php#vagas">← Voltar às vagas</a>
        <?php endif; ?>
    </div>
</body>

</html>