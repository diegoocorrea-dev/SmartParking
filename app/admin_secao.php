<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/vagas_dados.php';
exigir_perfil(['admin', 'operador']);
$pagina = $_GET['pagina'] ?? 'vagas';
$paginas = ['vagas' => 'Vagas', 'reservas' => 'Reservas', 'carregadores' => 'Carregadores Elétricos', 'financeiro' => 'Financeiro', 'relatorios' => 'Relatórios', 'usuarios' => 'Usuários', 'configuracoes' => 'Configurações'];
if (!isset($paginas[$pagina]))
    $pagina = 'vagas';
$titulo = $paginas[$pagina];
$pdo = db_tentar();
$linhas = [];
$cards = [];
$erro = '';
$financeLabels = [];
$financeValues = [];
$fmt = fn($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
try {
    if ($pagina === 'vagas') {
        $linhas = carregar_vagas();
        $c = contar_vagas($linhas);
        $cards = [['Total de vagas', count($linhas)], ['Livres', $c['livre']], ['Ocupadas', $c['ocupada']], ['Carregadores', $c['carregadores']]];
    } elseif ($pagina === 'reservas' && $pdo) {
        $linhas = $pdo->query("SELECT r.id,r.inicio,r.fim_previsto,r.status,r.status_pagamento,r.valor_estadia,v.codigo,p.nome piso,u.nome cliente FROM reservas r JOIN vagas v ON v.id=r.vaga_id JOIN pisos p ON p.id=v.piso_id LEFT JOIN usuarios u ON u.id=r.usuario_id ORDER BY r.criado_em DESC LIMIT 100")->fetchAll();
        $cards = [['Reservas cadastradas', (int) $pdo->query('SELECT COUNT(*) FROM reservas')->fetchColumn()], ['Hoje', (int) $pdo->query('SELECT COUNT(*) FROM reservas WHERE DATE(inicio)=CURDATE()')->fetchColumn()], ['Pendentes', (int) $pdo->query("SELECT COUNT(*) FROM reservas WHERE status IN ('pendente','confirmada')")->fetchColumn()]];
    } elseif ($pagina === 'carregadores') {
        $v = carregar_vagas();
        $linhas = array_values(array_filter($v, fn($x) => $x['categoria'] === 'eletrica'));
        $busy = count(array_filter($linhas, fn($x) => $x['status'] === 'ocupada'));
        $cards = [['Vagas elétricas', count($linhas)], ['Disponíveis', count($linhas) - $busy], ['Em uso', $busy]];
    } elseif ($pagina === 'financeiro' && $pdo) {
        $hoje = (float) $pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago' AND DATE(COALESCE(pago_em,criado_em))=CURDATE()")->fetchColumn();
        $mes = (float) $pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago' AND YEAR(COALESCE(pago_em,criado_em))=YEAR(CURDATE()) AND MONTH(COALESCE(pago_em,criado_em))=MONTH(CURDATE())")->fetchColumn();
        $total = (float) $pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago'")->fetchColumn();
        $cards = [['Faturamento de hoje', $fmt($hoje)], ['Faturamento do mês', $fmt($mes)], ['Faturamento acumulado', $fmt($total)]];
        $linhas = $pdo->query("SELECT id,reserva_id,metodo,valor,status,COALESCE(pago_em,criado_em) data FROM pagamentos ORDER BY criado_em DESC LIMIT 100")->fetchAll();
        $financeLabels = array_fill(0, 7, '');
        $financeValues = array_fill(0, 7, 0);
        $hist = $pdo->query("SELECT DATE(COALESCE(pago_em,criado_em)) dia,SUM(valor) total FROM pagamentos WHERE status='pago' AND COALESCE(pago_em,criado_em)>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(COALESCE(pago_em,criado_em)) ORDER BY dia")->fetchAll();
        $map = [];
        foreach ($hist as $h)
            $map[$h['dia']] = (float) $h['total'];
        for ($i = 0; $i < 7; $i++) {
            $dia = date('Y-m-d', strtotime('-' . (6 - $i) . ' days'));
            $financeLabels[$i] = date('d/m', strtotime($dia));
            $financeValues[$i] = $map[$dia] ?? 0;
        }
    } elseif ($pagina === 'usuarios' && $pdo) {
        $linhas = $pdo->query('SELECT id,nome,email,perfil,ativo,ultimo_login FROM usuarios ORDER BY id DESC LIMIT 100')->fetchAll();
        $cards = [['Usuários', (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn()], ['Ativos', (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE ativo=1')->fetchColumn()]];
    } elseif ($pagina === 'configuracoes' && $pdo) {
        $linhas = $pdo->query('SELECT id,preco_hora,preco_kwh,ativa FROM tarifas ORDER BY id DESC')->fetchAll();
        $cards = [['Tarifas cadastradas', count($linhas)], ['Preço por hora', isset($linhas[0]['preco_hora']) ? $fmt($linhas[0]['preco_hora']) : 'Não definido'], ['Preço por kWh', isset($linhas[0]['preco_kwh']) ? $fmt($linhas[0]['preco_kwh']) : 'Não definido']];
    } elseif ($pagina === 'relatorios' && $pdo) {
        $cards = [['Faturamento acumulado', $fmt($pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago'")->fetchColumn())], ['Total de reservas', (int) $pdo->query('SELECT COUNT(*) FROM reservas')->fetchColumn()], ['Usuários ativos', (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE ativo=1')->fetchColumn()]];
        $linhas = $pdo->query("SELECT DATE(COALESCE(pago_em,criado_em)) data,COUNT(*) transacoes,SUM(valor) faturamento FROM pagamentos WHERE status='pago' GROUP BY DATE(COALESCE(pago_em,criado_em)) ORDER BY data DESC LIMIT 30")->fetchAll();
    }
} catch (Throwable $e) {
    error_log('SmartParking ADM ' . $pagina . ': ' . $e->getMessage());
    $erro = 'Não foi possível carregar todos os dados. Verifique a conexão e a estrutura do banco de dados.';
}
$u = usuario_logado();
function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartParking | <?= h($titulo) ?></title>
    <link rel="stylesheet" href="./css/adm.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .admin-content {
            max-width: 1250px;
            margin: auto
        }

        .page-head {
            display: flex;
            align-items: end;
            justify-content: space-between;
            margin: 0 7px 18px
        }

        .page-head h1 {
            font-size: 25px;
            margin: 0 0 4px
        }

        .page-head p {
            margin: 0;
            color: #627894
        }

        .back-link {
            color: #0b9f9a;
            text-decoration: none;
            font-weight: 700
        }

        .section-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px
        }

        .section-card {
            background: white;
            border: 1px solid #dce5f0;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 3px 10px #19365c08
        }

        .section-card small {
            display: block;
            color: #607793;
            font-size: 12px
        }

        .section-card strong {
            display: block;
            font-size: 24px;
            margin-top: 7px;
            color: #102347
        }

        .data-panel {
            background: white;
            border: 1px solid #dce5f0;
            border-radius: 12px;
            padding: 16px;
            overflow: auto
        }

        .data-panel h2 {
            font-size: 16px;
            margin: 0 0 14px
        }

        .table-tools {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px
        }

        .table-tools input {
            border: 1px solid #dce5f0;
            border-radius: 8px;
            padding: 9px 11px;
            max-width: 260px;
            width: 100%;
            font: inherit
        }

        .finance-chart {
            height: 230px;
            margin: 0 0 18px;
            background: #fff;
            border: 1px solid #e6edf4;
            border-radius: 10px;
            padding: 12px
        }

        .finance-chart canvas {
            width: 100% !important;
            height: 100% !important
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            white-space: nowrap
        }

        .data-table th,
        .data-table td {
            text-align: left;
            padding: 11px 10px;
            border-bottom: 1px solid #edf1f5
        }

        .data-table th {
            color: #526b88;
            background: #f7fafc
        }

        .status {
            padding: 4px 8px;
            border-radius: 20px;
            background: #e3f8f3;
            color: #09866d
        }

        .empty-state {
            padding: 25px;
            color: #647b96;
            text-align: center
        }

        .warning {
            background: #fff8e8;
            border: 1px solid #f0dfb4;
            color: #795a16;
            padding: 12px;
            border-radius: 9px;
            margin-bottom: 14px
        }

        @media(max-width:850px) {
            .page-head h1 {
                font-size: 21px
            }
        }
    </style>
</head>

<body>
    <header class="topbar"><a class="brand" href="adm.php"><span
                class="brand-mark">P</span><span>Smart<span>Parking</span></span></a>
        <div class="search"><span>⌕</span><input placeholder="SmartParking · Administração" disabled></div>
        <div class="top-user"><span class="avatar"><?= h(strtoupper(substr($u['nome'] ?: 'AD', 0, 2))) ?></span><span
                class="user-copy"><strong><?= h($u['nome'] ?: 'Administrador') ?></strong><small><?= h(ucfirst($u['perfil'])) ?></small></span>
        </div>
    </header>
    <aside class="sidebar">
        <nav class="side-links">
            <?php $links = [['Dashboard', 'adm.php', '⌂', ''], ['Vagas', 'admin_secao.php?pagina=vagas', '▣', 'vagas'], ['Reservas', 'admin_secao.php?pagina=reservas', '▦', 'reservas'], ['Carregadores Elétricos', 'admin_secao.php?pagina=carregadores', 'ϟ', 'carregadores'], ['Financeiro', 'admin_secao.php?pagina=financeiro', '$', 'financeiro'], ['Relatórios', 'admin_secao.php?pagina=relatorios', '▥', 'relatorios'], ['Usuários', 'admin_secao.php?pagina=usuarios', '♙', 'usuarios'], ['Configurações', 'admin_secao.php?pagina=configuracoes', '⚙', 'configuracoes']];
            foreach ($links as $l): ?><a
                    class="side-link <?= $l[3] === $pagina ? 'active' : '' ?>"
                    href="<?= $l[1] ?>"><span><?= $l[2] ?></span><?= $l[0] ?></a><?php endforeach; ?></nav>
        <div class="eco-card">
            <div class="eco-icon">◆</div><strong>Juntos por um futuro mais sustentável!</strong>
            <p>Veja o impacto ambiental do seu estacionamento.</p><a href="admin_secao.php?pagina=relatorios">Ver
                relatório →</a>
        </div><img class="side-photo" src="./img/img_adm.png" alt="Estação de recarga">
    </aside>
    <main class="main">
        <div class="admin-content">
            <div class="page-head">
                <div>
                    <h1><?= h($titulo) ?></h1>
                    <p>Visão geral e gerenciamento da operação do estacionamento.</p>
                </div><a class="back-link" href="adm.php">← Voltar ao dashboard</a>
            </div>
            <?php if ($erro): ?>
                <div class="warning"><?= h($erro) ?></div><?php endif; ?>
            <section class="section-cards"><?php foreach ($cards as $c): ?>
                    <article class="section-card"><small><?= h($c[0]) ?></small><strong><?= h($c[1]) ?></strong></article>
                <?php endforeach; ?>
            </section>
            <?php if ($pagina === 'financeiro'): ?>
                <section class="data-panel" style="margin-bottom:16px">
                    <h2>Faturamento dos últimos 7 dias</h2>
                    <div class="finance-chart"><canvas id="financeChart"></canvas></div>
                    <p style="font-size:11px;color:#647b96;margin:0">O lucro líquido não é calculado porque o projeto ainda
                        não possui dados de custos/despesas cadastrados.</p>
                </section><?php endif; ?>
            <section class="data-panel">
                <div class="table-tools">
                    <h2><?= $pagina === 'financeiro' ? 'Movimentações financeiras' : ($pagina === 'reservas' ? 'Reservas recentes' : ($pagina === 'usuarios' ? 'Usuários cadastrados' : ($pagina === 'configuracoes' ? 'Tarifas configuradas' : ($pagina === 'relatorios' ? 'Resumo por dia' : ($pagina === 'carregadores' ? 'Vagas com carregadores' : 'Vagas cadastradas'))))) ?>
                    </h2><input id="tableSearch" type="search" placeholder="Filtrar registros...">
                </div>
                <?php if (!$linhas): ?>
                    <div class="empty-state">Ainda não existem registros disponíveis para exibir nesta seção.</div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr><?php foreach (array_keys($linhas[0]) as $col): ?>
                                    <th><?= h(ucfirst(str_replace('_', ' ', $col))) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($linhas as $row): ?>
                                <tr><?php foreach ($row as $col => $val): ?>
                                        <td><?php if ($col === 'valor' || $col === 'valor_estadia' || $col === 'preco_hora' || $col === 'preco_kwh'): ?><?= h($fmt($val)) ?><?php elseif ($col === 'ativo' || $col === 'ativa'): ?><span
                                                    class="status"><?= $val ? 'Ativo' : 'Inativo' ?></span><?php else: ?><?= h($val === null ? '—' : $val) ?><?php endif; ?>
                                        </td><?php endforeach; ?>
                                </tr><?php endforeach; ?>
                        </tbody>
                    </table><?php endif; ?>
            </section>
        </div>
    </main>
    <script>const busca = document.getElementById('tableSearch'); if (busca) { busca.addEventListener('input', () => document.querySelectorAll('.data-table tbody tr').forEach(tr => tr.hidden = !tr.textContent.toLowerCase().includes(busca.value.toLowerCase()))) };<?php if ($pagina === 'financeiro'): ?>if (window.Chart) { new Chart(document.getElementById('financeChart'), { type: 'bar', data: { labels: <?= json_encode($financeLabels) ?>, datasets: [{ label: 'Faturamento', data: <?= json_encode($financeValues) ?>, backgroundColor: 'rgba(20,184,166,.55)', borderColor: '#14b8a6', borderWidth: 1, borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'R$ ' + v } }, x: { grid: { display: false } } } } }) } <?php endif; ?></script>
</body>

</html>