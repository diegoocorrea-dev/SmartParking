<?php
require_once __DIR__ . '/init.php';
exigir_login();
$usuario = usuario_logado();
$pdo = db();
$erro = '';
$mensagem = '';
$pagamento = null;
$codigoBarras = '';

function codigo_svg(string $valor): string
{
    // Representação local de código de barras para identificação no totem (não é QR bancário).
    $seed = hash('sha256', $valor);
    $x = 8;
    $barras = '';
    for ($i = 0; $i < strlen($seed); $i++) {
        $largura = (hexdec($seed[$i]) % 3) + 1;
        if ($i % 2 === 0)
            $barras .= '<rect x="' . $x . '" y="5" width="' . $largura . '" height="48" fill="#10263d"/>';
        $x += $largura + 1;
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . max(220, $x + 8) . ' 60" role="img" aria-label="Código de barras"><rect width="100%" height="100%" fill="#fff"/>' . $barras . '</svg>';
}
function comprovante_unico(): string
{
    return 'SP' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
}

$reservaId = filter_input(INPUT_GET, 'reserva', FILTER_VALIDATE_INT) ?: 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST')
    $reservaId = (int) ($_POST['reserva_id'] ?? $reservaId);
if ($reservaId <= 0) {
    $st = $pdo->prepare("SELECT id FROM reservas WHERE usuario_id=:uid AND status_pagamento IN ('pendente','parcial') AND status NOT IN ('cancelada','expirada') ORDER BY criado_em DESC LIMIT 1");
    $st->execute([':uid' => $usuario['id']]);
    $reservaId = (int) $st->fetchColumn();
}
if ($reservaId > 0) {
    $st = $pdo->prepare("SELECT r.*, v.codigo AS vaga_codigo, p.nome AS piso_nome, vei.nome AS veiculo_nome, vei.placa, vei.bateria_kwh, t.preco_kwh, t.preco_hora
        FROM reservas r JOIN vagas v ON v.id=r.vaga_id JOIN pisos p ON p.id=v.piso_id
        LEFT JOIN veiculos vei ON vei.id=r.veiculo_id LEFT JOIN tarifas t ON t.id=r.tarifa_id
        WHERE r.id=:rid LIMIT 1");
    $st->execute([':rid' => $reservaId]);
    $reserva = $st->fetch();
    if (!$reserva || ((int) $reserva['usuario_id'] !== (int) $usuario['id'] && !in_array($usuario['perfil'], ['admin', 'operador'], true))) {
        http_response_code(404);
        $erro = 'Reserva não encontrada ou sem permissão para visualizá-la.';
        $reserva = null;
    }
} else {
    $reserva = null;
}

if ($reserva && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok(post_str('csrf')))
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    else {
        try {
            $acao = post_str('acao');
            $valorTotal = (float) ($reserva['valor_total'] ?? ((float) $reserva['valor_estadia'] + (float) $reserva['valor_carga'] - (float) $reserva['desconto']));
            $cargaEstimada = 0.0;
            if ((int) $reserva['usa_carregador'] === 1 && (float) $reserva['valor_carga'] <= 0 && $reserva['bateria_kwh'] !== null && $reserva['preco_kwh'] !== null) {
                $kwh = (float) $reserva['bateria_kwh'] * max(0, ((float) $reserva['bateria_meta_pct'] - (float) $reserva['bateria_atual_pct'])) / 100;
                $cargaEstimada = $kwh * (float) $reserva['preco_kwh'];
            }
            $valorBase = max(0, $valorTotal > 0 ? $valorTotal : ((float) $reserva['valor_estadia'] + $cargaEstimada - (float) $reserva['desconto']));
            $st = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE reserva_id=:rid AND status='pago'");
            $st->execute([':rid' => $reservaId]);
            $jaPago = (float) $st->fetchColumn();
            $saldo = max(0, round($valorBase - $jaPago, 2));
            if ($saldo <= 0)
                $erro = 'Esta reserva já está totalmente paga.';
            elseif ($acao === 'totem') {
                $st = $pdo->prepare("SELECT id,transacao_id FROM pagamentos WHERE reserva_id=:rid AND gateway='totem' AND status='pendente' ORDER BY id DESC LIMIT 1");
                $st->execute([':rid' => $reservaId]);
                $existente = $st->fetch();
                if ($existente) {
                    $pid = (int) $existente['id'];
                    $pin = $existente['transacao_id'];
                } else {
                    $pin = 'TOTEM-' . strtoupper(bin2hex(random_bytes(3)));
                    $st = $pdo->prepare("INSERT INTO pagamentos (reserva_id,usuario_id,metodo,valor,status,gateway,transacao_id,criado_em) VALUES (:rid,:uid,NULL,:valor,'pendente','totem',:pin,NOW())");
                    $st->execute([':rid' => $reservaId, ':uid' => $reserva['usuario_id'], ':valor' => $saldo, ':pin' => $pin]);
                    $pid = (int) $pdo->lastInsertId();
                }
                $call = $pdo->prepare('CALL sp_atualizar_status_pagamento(:rid)');
                $call->execute([':rid' => $reservaId]);
                $call->closeCursor();
                $pagamento = ['id' => $pid, 'status' => 'pendente', 'transacao_id' => $pin, 'valor' => $saldo, 'gateway' => 'totem'];
                $mensagem = 'Código gerado. Dirija-se ao totem e informe o PIN abaixo.';
            } elseif ($acao === 'simular_totem' || $acao === 'pix' || $acao === 'cartao') {
                $metodo = $acao === 'pix' ? 'pix' : ($acao === 'cartao' ? (post_str('tipo_cartao') === 'credito' ? 'cartao_credito' : 'cartao_debito') : 'dinheiro');
                $pin = post_str('pin');
                if ($acao === 'simular_totem') {
                    $st = $pdo->prepare("SELECT id FROM pagamentos WHERE reserva_id=:rid AND usuario_id=:uid AND gateway='totem' AND status='pendente' AND transacao_id=:pin ORDER BY id DESC LIMIT 1");
                    $st->execute([':rid' => $reservaId, ':uid' => $reserva['usuario_id'], ':pin' => $pin]);
                    $pid = (int) $st->fetchColumn();
                    if (!$pid)
                        throw new RuntimeException('Código do totem inválido ou já utilizado.');
                    $st = $pdo->prepare("UPDATE pagamentos SET status='pago',metodo='dinheiro',pago_em=NOW(),comprovante=:comp WHERE id=:id AND status='pendente'");
                    $st->execute([':comp' => comprovante_unico(), ':id' => $pid]);
                } else {
                    $final = null;
                    if ($acao === 'cartao') {
                        $numero = preg_replace('/\D/', '', post_str('numero_cartao'));
                        if (strlen($numero) < 13 || strlen($numero) > 19 || trim(post_str('nome_cartao')) === '' || !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', post_str('validade')) || strlen(post_str('cvv')) < 3 || strlen(post_str('cvv')) > 4)
                            throw new RuntimeException('Confira os dados do cartão. Nenhum número completo ou CVV será armazenado.');
                        $final = substr($numero, -4);
                    }
                    // Fluxo demonstrativo: sem gateway/adquirente, não representa liquidação bancária real.
                    $pixCode = $acao === 'pix' ? 'SMARTPARKING-DEMO-PIX-' . $reservaId . '-' . strtoupper(bin2hex(random_bytes(4))) : null;
                    $st = $pdo->prepare("INSERT INTO pagamentos (reserva_id,usuario_id,metodo,valor,parcelas,status,gateway,transacao_id,pix_copia_cola,cartao_final,comprovante,pago_em,criado_em) VALUES (:rid,:uid,:metodo,:valor,:parcelas,'pago','simulacao',:trans,:pix,:final,:comp,NOW(),NOW())");
                    $st->execute([':rid' => $reservaId, ':uid' => $reserva['usuario_id'], ':metodo' => $metodo, ':valor' => $saldo, ':parcelas' => $acao === 'cartao' ? max(1, min(12, (int) ($_POST['parcelas'] ?? 1))) : 1, ':trans' => 'SIM-' . strtoupper(bin2hex(random_bytes(5))), ':pix' => $pixCode, ':final' => $final, ':comp' => comprovante_unico()]);
                    $pid = (int) $pdo->lastInsertId();
                }
                $call = $pdo->prepare('CALL sp_atualizar_status_pagamento(:rid)');
                $call->execute([':rid' => $reservaId]);
                $call->closeCursor();
                $st = $pdo->prepare('SELECT * FROM pagamentos WHERE id=:id');
                $st->execute([':id' => $pid]);
                $pagamento = $st->fetch();
                $mensagem = 'Pagamento registrado no ambiente de demonstração.';
            }
        } catch (Throwable $ex) {
            if ($ex instanceof RuntimeException)
                $erro = $ex->getMessage();
            else {
                error_log('SmartParking pagamento: ' . $ex->getMessage());
                $erro = 'Não foi possível processar a operação. Verifique o banco de dados e tente novamente.';
            }
        }
    }
}
if ($reserva && !$pagamento) {
    $st = $pdo->prepare("SELECT * FROM pagamentos WHERE reserva_id=:rid AND usuario_id=:uid ORDER BY id DESC LIMIT 1");
    $st->execute([':rid' => $reservaId, ':uid' => $reserva['usuario_id']]);
    $pagamento = $st->fetch() ?: null;
}
$valorTotal = $reserva ? (float) ($reserva['valor_total'] ?? ((float) $reserva['valor_estadia'] + (float) $reserva['valor_carga'] - (float) $reserva['desconto'])) : 0;
$cargaEstimada = 0;
if ($reserva && (int) $reserva['usa_carregador'] === 1 && (float) $reserva['valor_carga'] <= 0 && $reserva['bateria_kwh'] !== null && $reserva['preco_kwh'] !== null) {
    $cargaEstimada = (float) $reserva['bateria_kwh'] * max(0, ((float) $reserva['bateria_meta_pct'] - (float) $reserva['bateria_atual_pct'])) / 100 * (float) $reserva['preco_kwh'];
}
if ($reserva) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE reserva_id=:rid AND status='pago'");
    $st->execute([':rid' => $reservaId]);
    $valorBase = max(0, $valorTotal > 0 ? $valorTotal : (float) $reserva['valor_estadia'] + $cargaEstimada - (float) $reserva['desconto']);
    $saldo = max(0, round($valorBase - (float) $st->fetchColumn(), 2));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento | SmartParking</title>
    <link rel="stylesheet" href="./css/pagamento.css">
</head>

<body>
    <?php require __DIR__ . '/nav.php'; ?>
    <main class="pagamento-page">
        <?php if ($erro): ?>
            <p class="alerta erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
        <?php if ($mensagem): ?>
            <p class="alerta sucesso" role="status"><?= e($mensagem) ?></p><?php endif; ?>
        <?php if (!$reserva): ?>
            <section class="pagamento-card">
                <h1>Pagamentos</h1>
                <p><?= e($erro ?: 'Não há reservas pendentes vinculadas à sua conta.') ?></p><a class="acao"
                    href="index.php#vagas">Ver vagas</a>
            </section>
        <?php else: ?>
            <section class="pagamento-card">
                <h1>Resumo da Reserva</h1>
                <div class="resumo">
                    <div><small>Reserva</small><strong>#<?= (int) $reserva['id'] ?></strong></div>
                    <div><small>Vaga</small><strong><?= e($reserva['vaga_codigo']) ?> · <?= e($reserva['piso_nome']) ?></strong>
                    </div>
                    <div><small>Veículo</small><strong><?= e($reserva['veiculo_nome'] ?? 'Não informado') ?> ·
                            <?= e($reserva['placa'] ?? '—') ?></strong></div>
                    <div><small>Período</small><strong><?= e(date('d/m/Y H:i', strtotime($reserva['inicio']))) ?> até
                            <?= e(date('d/m/Y H:i', strtotime($reserva['fim_previsto']))) ?></strong></div>
                    <div><small>Estadia</small><strong>R$
                            <?= number_format((float) $reserva['valor_estadia'], 2, ',', '.') ?></strong></div>
                    <?php if ((int) $reserva['usa_carregador'] === 1): ?>
                        <div><small>Estimativa de recarga</small><strong>R$
                                <?= number_format($cargaEstimada ?: (float) $reserva['valor_carga'], 2, ',', '.') ?></strong></div>
                    <?php endif; ?>
                    <div class="saldo"><small>Saldo a pagar</small><strong>R$ <?= number_format($saldo, 2, ',', '.') ?></strong>
                    </div>
                </div>
                <?php if ($pagamento && $pagamento['gateway'] === 'totem' && $pagamento['status'] === 'pendente'): ?>
                    <div class="ticket">
                        <h2>Aguardando Pagamento no Totem</h2>
                        <p>Informe este código no totem de autoatendimento. Escolha dinheiro, cartão ou PIX diretamente no
                            equipamento.</p><strong class="pin"><?= e($pagamento['transacao_id']) ?></strong>
                        <div class="barcode"><?= codigo_svg($pagamento['transacao_id']) ?></div><small>Reserva
                            #<?= (int) $reservaId ?> · Vaga <?= e($reserva['vaga_codigo']) ?></small>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden"
                                name="reserva_id" value="<?= $reservaId ?>"><input type="hidden" name="acao"
                                value="simular_totem"><input type="hidden" name="pin"
                                value="<?= e($pagamento['transacao_id']) ?>"><button class="acao" type="submit">Simular Pagamento
                                Realizado no Totem</button></form>
                    </div>
                <?php elseif ($saldo <= 0): ?>
                    <div class="ticket">
                        <h2>Pagamento Confirmado</h2>
                        <p>Reserva #<?= (int) $reservaId ?> · Vaga <?= e($reserva['vaga_codigo']) ?> · Placa
                            <?= e($reserva['placa'] ?? '—') ?></p><?php if ($pagamento && $pagamento['comprovante']): ?>
                            <p>Comprovante: <strong><?= e($pagamento['comprovante']) ?></strong></p><?php endif; ?>
                        <p>Horário:
                            <?= e($pagamento && $pagamento['pago_em'] ? date('d/m/Y H:i', strtotime($pagamento['pago_em'])) : '—') ?></p>
                        <button class="acao" onclick="window.print()" type="button">Imprimir Comprovante</button>
                    </div>
                <?php else: ?>
                    <h2>Escolha como deseja pagar</h2>
                    <div class="formas">
                        <form method="post" class="forma destaque"><input type="hidden" name="csrf"
                                value="<?= e(csrf_token()) ?>"><input type="hidden" name="reserva_id"
                                value="<?= $reservaId ?>"><input type="hidden" name="acao" value="totem">
                            <h3>Pagar pessoalmente no Totem</h3>
                            <p>Gere um PIN e pague presencialmente no equipamento de autoatendimento.</p><button class="acao"
                                type="submit">Gerar código do Totem</button>
                        </form>
                        <form method="post" class="forma"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input
                                type="hidden" name="reserva_id" value="<?= $reservaId ?>"><input type="hidden" name="acao"
                                value="pix">
                            <h3>PIX Online</h3>
                            <p>Simulação de confirmação PIX para testes e apresentação. Não é uma cobrança bancária real.</p>
                            <button class="acao secundario" type="submit">Gerar PIX de demonstração</button>
                        </form>
                        <form method="post" class="forma"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input
                                type="hidden" name="reserva_id" value="<?= $reservaId ?>"><input type="hidden" name="acao"
                                value="cartao">
                            <h3>Cartão de Crédito / Débito</h3><label>Número do cartão<input name="numero_cartao"
                                    inputmode="numeric" autocomplete="cc-number" maxlength="23" required></label><label>Nome
                                impresso<input name="nome_cartao" autocomplete="cc-name" required></label>
                            <div class="linha"><label>Validade<input name="validade" placeholder="MM/AA" maxlength="5"
                                        required></label><label>CVV<input name="cvv" inputmode="numeric" maxlength="4"
                                        autocomplete="cc-csc" required></label></div><label>Tipo<select name="tipo_cartao">
                                    <option value="credito">Crédito</option>
                                    <option value="debito">Débito</option>
                                </select></label><label>Parcelas<select name="parcelas"><?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?>x</option><?php endfor; ?>
                                </select></label>
                            <p class="aviso">Ambiente demonstrativo: os dados completos e o CVV não são armazenados; somente os
                                4 últimos dígitos.</p><button class="acao secundario" type="submit">Simular pagamento</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script>document.querySelectorAll('input[name="numero_cartao"]').forEach(function (el) { el.addEventListener('input', function () { this.value = this.value.replace(/\\D/g, '').slice(0, 19).replace(/(.{4})/g, '$1 ').trim(); }); });</script>
</body>

</html>