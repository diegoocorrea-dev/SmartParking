<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/vagas_dados.php';
exigir_perfil(['admin', 'operador']);
date_default_timezone_set('America/Sao_Paulo');

$usuario = usuario_logado();
$vagasBanco = carregar_vagas();
$pisos = ['Piso 3', 'Piso 2', 'Piso 1', 'Térreo'];
$porPiso = array_fill_keys($pisos, []);
foreach ($vagasBanco as $vaga) {
    $nome = trim((string)($vaga['piso'] ?? ''));
    if (!isset($porPiso[$nome])) $porPiso[$nome] = [];
    $porPiso[$nome][] = $vaga;
}
// Dados demonstrativos apenas para pisos sem registros. Ao cadastrar vagas no banco, os dados reais prevalecem.
$simulados = [
 'Piso 3' => [['A01','livre','comum'],['A02','ocupada','comum'],['A03','livre','eletrica'],['A04','ocupada','comum'],['A05','livre','comum'],['A06','livre','eletrica'],['B01','ocupada','comum'],['B02','livre','comum'],['B03','livre','eletrica'],['B04','ocupada','comum'],['B05','livre','comum'],['B06','livre','comum']],
 'Piso 2' => [['A01','ocupada','comum'],['A02','livre','comum'],['A03','livre','eletrica'],['A04','livre','comum'],['A05','ocupada','comum'],['A06','livre','comum'],['B01','livre','comum'],['B02','ocupada','comum'],['B03','livre','eletrica'],['B04','livre','comum'],['B05','ocupada','comum'],['B06','livre','comum'],['C01','livre','comum'],['C02','ocupada','comum'],['C03','livre','comum'],['C04','livre','eletrica']],
 'Piso 1' => [['A01','livre','comum'],['A02','ocupada','comum'],['A03','ocupada','comum'],['A04','livre','eletrica'],['A05','livre','comum'],['A06','ocupada','comum'],['B01','livre','comum'],['B02','livre','eletrica'],['B03','ocupada','comum'],['B04','livre','comum'],['B05','livre','comum'],['B06','ocupada','comum']],
 'Térreo' => [['A01','livre','comum'],['A02','ocupada','comum'],['A03','livre','comum'],['A04','livre','comum'],['A05','ocupada','comum'],['A06','livre','eletrica'],['B01','livre','comum'],['B02','ocupada','comum'],['B03','livre','eletrica'],['B04','manutencao','comum'],['B05','ocupada','comum'],['B06','livre','eletrica']]
];
foreach ($pisos as $piso) {
    if (!$porPiso[$piso]) {
        foreach ($simulados[$piso] as $i => $item) $porPiso[$piso][] = ['id'=>0,'piso'=>$piso,'codigo'=>$item[0],'categoria'=>$item[2],'status'=>$item[1],'tem_carregador'=>$item[2]==='eletrica','conector'=>null,'potencia_kw'=>null,'demo'=>true];
    }
}
$estatisticas = [];
foreach ($porPiso as $piso => $lista) $estatisticas[$piso] = contar_vagas($lista);
$dadosPisos = [];
foreach ($porPiso as $piso => $lista) {
    $c = $estatisticas[$piso]; $total = count($lista);
    $dadosPisos[$piso] = ['total'=>$total,'livres'=>$c['livre'],'ocupadas'=>$c['ocupada'],'manutencao'=>$c['manutencao'],'eletricas'=>$c['carregadores'],'ocupacao'=>$total ? round($c['ocupada']/$total*100) : 0,'vagas'=>$lista];
}
$financeiro = ['hoje'=>0,'ontem'=>0,'total'=>0]; $reservasHoje=0; $usuariosAtivos=0;
$pdo = db_tentar();
if ($pdo) {
 try {
   $st=$pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago' AND DATE(COALESCE(pago_em,criado_em))=CURDATE()"); $financeiro['hoje']=(float)$st->fetchColumn();
   $st=$pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago' AND DATE(COALESCE(pago_em,criado_em))=DATE_SUB(CURDATE(), INTERVAL 1 DAY)"); $financeiro['ontem']=(float)$st->fetchColumn();
   $financeiro['total']=(float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE status='pago'")->fetchColumn();
   $reservasHoje=(int)$pdo->query("SELECT COUNT(*) FROM reservas WHERE DATE(inicio)=CURDATE()")->fetchColumn();
   $usuariosAtivos=(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo=1")->fetchColumn();
 } catch (PDOException $e) { error_log('ADM indicadores: '.$e->getMessage()); }
}
$variacao = $financeiro['ontem'] > 0 ? round(($financeiro['hoje']-$financeiro['ontem'])/$financeiro['ontem']*100) : 0;
$series = ['Hoje'=>['labels'=>['06h','09h','12h','15h','18h','21h'],'valores'=>[0,0,0,0,0,0]],'7 dias'=>['labels'=>['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'],'valores'=>array_fill(0,7,0)],'30 dias'=>['labels'=>['01','06','11','16','21','26','30'],'valores'=>array_fill(0,7,0)]];
if ($pdo) {
 try {
   $rows=$pdo->query("SELECT HOUR(COALESCE(pago_em,criado_em)) h, SUM(valor) total FROM pagamentos WHERE status='pago' AND DATE(COALESCE(pago_em,criado_em))=CURDATE() GROUP BY HOUR(COALESCE(pago_em,criado_em))")->fetchAll();
   $map=[]; foreach($rows as $r)$map[(int)$r['h']]=(float)$r['total'];
   foreach([6,9,12,15,18,21] as $i=>$h){$sum=0;foreach($map as $hora=>$v)if($hora<=$h)$sum+=$v;$series['Hoje']['valores'][$i]=$sum;}
   $rows=$pdo->query("SELECT WEEKDAY(COALESCE(pago_em,criado_em)) d, SUM(valor) total FROM pagamentos WHERE status='pago' AND COALESCE(pago_em,criado_em)>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY WEEKDAY(COALESCE(pago_em,criado_em))")->fetchAll();
   foreach($rows as $r)$series['7 dias']['valores'][(int)$r['d']]=(float)$r['total'];
   $rows=$pdo->query("SELECT DAY(COALESCE(pago_em,criado_em)) d, SUM(valor) total FROM pagamentos WHERE status='pago' AND YEAR(COALESCE(pago_em,criado_em))=YEAR(CURDATE()) AND MONTH(COALESCE(pago_em,criado_em))=MONTH(CURDATE()) GROUP BY DAY(COALESCE(pago_em,criado_em))")->fetchAll();
   $map=[];foreach($rows as $r)$map[(int)$r['d']]=(float)$r['total'];$dias=[1,6,11,16,21,26,(int)date('t')];foreach($dias as $i=>$d){$sum=0;foreach($map as $dia=>$v)if($dia<=$d)$sum+=$v;$series['30 dias']['valores'][$i]=$sum;}
 } catch(PDOException $e){error_log('ADM séries: '.$e->getMessage());}
}
$eventos=[];
if($pdo){try{$eventos=$pdo->query("SELECT r.id,r.status,r.inicio,v.codigo,p.nome piso FROM reservas r JOIN vagas v ON v.id=r.vaga_id JOIN pisos p ON p.id=v.piso_id ORDER BY r.criado_em DESC LIMIT 5")->fetchAll();}catch(PDOException $e){error_log('ADM eventos: '.$e->getMessage());}}
$eventosDemo=[['Entrada de veículo','Placa ABC1D23','14:28','car'],['Carregador liberado','Vaga E-03','14:20','bolt'],['Reserva confirmada','Vaga B-12','14:15','calendar'],['Saída de veículo','Placa FGH4E56','14:05','car']];
function adm_brl($v){return 'R$ '.number_format((float)$v,2,',','.');}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SmartParking | Administração</title><link rel="stylesheet" href="./css/adm.css"><script src="https://cdn.jsdelivr.net/npm/chart.js"></script></head><body>
<header class="topbar"><a class="brand brand-home" href="index.php" aria-label="Home"><span class="brand-mark">P</span><span>Smart<b>Parking</b></span></a><div class="search"><span>⌕</span><input id="buscaVaga" type="search" placeholder="Buscar por vaga, placa ou cliente..."></div><div class="top-user"><span class="bell">♧</span><span class="avatar"><?=e(strtoupper(substr($usuario['nome'] ?: 'AD',0,2)))?></span><span class="user-copy"><strong><?=e($usuario['nome'] ?: 'Administrador')?></strong><small><?=e(ucfirst($usuario['perfil']))?></small></span></div></header>
<aside class="sidebar"><nav class="side-links">
<?php $links=[['Dashboard','adm.php','⌂'],['Vagas','admin_secao.php?pagina=vagas','▣'],['Reservas','admin_secao.php?pagina=reservas','▦'],['Carregadores Elétricos','admin_secao.php?pagina=carregadores','ϟ'],['Financeiro','admin_secao.php?pagina=financeiro','$'],['Relatórios','admin_secao.php?pagina=relatorios','▥'],['Usuários','admin_secao.php?pagina=usuarios','♙'],['Configurações','admin_secao.php?pagina=configuracoes','⚙']];foreach($links as $i=>$l):?><a class="side-link <?=$i===0?'active':''?>" href="<?=$l[1]?>"><span><?=$l[2]?></span><?=$l[0]?></a><?php endforeach;?>
</nav><div class="eco-card"><div class="eco-icon">◆</div><strong>Juntos por um futuro mais sustentável!</strong><p>Veja o impacto ambiental do seu estacionamento.</p><a href="admin_secao.php?pagina=relatorios">Ver relatório →</a></div><img class="side-photo" src="./img/img_adm.png" alt="Veículo elétrico em estação de recarga"></aside>
<main class="main"><div class="welcome"><div><h1>Olá, <?=e(explode(' ',trim($usuario['nome'] ?: 'Administrador'))[0])?>!</h1><p>Confira o resumo da operação do seu estacionamento hoje.</p></div><div class="datetime">▦ <?=date('D, d \d\e M \d\e Y')?> <span>◷ <?=date('H:i')?></span></div></div>
<section class="kpis" id="kpis"><article class="kpi"><span class="kpi-icon blue">P</span><div><small>Total de Vagas</small><strong id="kpiTotal">—</strong><em>100% da capacidade cadastrada</em></div></article><article class="kpi"><span class="kpi-icon aqua">🚘</span><div><small>Vagas Livres</small><strong id="kpiLivres">—</strong><em id="kpiLivrePct">— disponíveis</em></div></article><article class="kpi"><span class="kpi-icon blue">🚗</span><div><small>Vagas Ocupadas</small><strong id="kpiOcupadas">—</strong><em id="kpiOcupPct">— em uso</em></div></article><article class="kpi"><span class="kpi-icon aqua">ϟ</span><div><small>Carregadores Elétricos</small><strong id="kpiEletricas">—</strong><em>Vagas equipadas</em></div></article></section>
<section class="dashboard-grid"><article class="panel map-panel"><div class="panel-head"><h2>Mapa de Vagas</h2><div class="legend"><span><i class="dot free"></i> Livre</span><span><i class="dot busy"></i> Ocupada</span><span><i class="dot electric"></i> Elétrica</span><span><i class="dot maintenance"></i> Manutenção</span></div><select id="setorFiltro"><option value="todos">Todos os setores</option><option value="A">Setor A</option><option value="B">Setor B</option><option value="C">Setor C</option><option value="D">Setor D</option></select></div><div class="map-content"><div class="floor-tabs"><?php foreach($pisos as $i=>$piso):?><button type="button" class="floor-btn <?=$i===0?'active':''?>" data-floor="<?=e($piso)?>"><strong><?=e($piso)?></strong><small>Setor <?=chr(65+$i)?></small></button><?php endforeach;?></div><div class="parking-map"><div class="parking-aisle"><span>ENTRADA</span><span>↗</span><span>SAÍDA</span></div><div class="slots-grid" id="slotsGrid"></div><div class="map-side-chargers"><strong>Carregadores<br>Elétricos</strong><span>ϟ</span><span>ϟ</span><span>ϟ</span><span>ϟ</span></div></div></div><div class="map-foot"><span id="floorSummary">Selecione um piso para visualizar as vagas.</span><span class="demo-note">* Dados demonstrativos nos pisos sem cadastro no banco.</span></div></article>
<aside class="right-stack"><article class="panel mini-panel"><div class="mini-title"><span class="round-icon">◷</span><strong>Tempo Médio de Permanência</strong></div><div class="mini-value">2h 45min</div><small class="positive">↑ 12% <span>em relação à semana passada</span></small></article><article class="panel mini-panel revenue"><div class="mini-title"><span class="round-icon">$</span><strong>Faturamento do Dia</strong></div><div class="mini-value"><?=adm_brl($financeiro['hoje'])?></div><small class="<?=$variacao>=0?'positive':'negative'?>"><?=$variacao>=0?'↑':'↓'?> <?=abs($variacao)?>% <span>em relação a ontem</span></small><div class="sparkline"><canvas id="graficoFaturamento"></canvas></div></article><article class="panel mini-panel occupancy"><div class="mini-title"><span class="round-icon">◷</span><strong>Ocupação</strong></div><div class="occupancy-row"><div class="donut" id="donutOcupacao"><strong id="ocupacaoPct">0%</strong></div><div><strong id="ocupacaoNumeros">0 / 0 vagas</strong><small>ocupação do piso</small></div></div><div class="occupancy-bars" id="ocupacaoBarras"></div></article></aside>
<article class="panel chart-panel"><div class="panel-head"><h2>Previsão de Lotação</h2><select id="periodoGrafico"><option>Hoje</option><option>7 dias</option><option>30 dias</option></select></div><div class="chart-wrap"><canvas id="graficoLotacao"></canvas></div></article><article class="panel charging-panel"><div class="panel-head"><h2>ϟ　Carregamento Elétrico</h2><span class="live-dot">● Em tempo real*</span></div><div class="charge-content"><div class="charge-donut" id="chargeDonut"><strong id="chargeCount">0/0</strong><small>em uso</small></div><div class="charge-legend"><span><i class="dot electric"></i> Carregadores disponíveis <b id="chargeFree">0</b></span><span><i class="dot busy"></i> Vagas elétricas ocupadas <b id="chargeBusy">0</b></span></div></div><div class="charge-foot"><span>ϟ Energia fornecida hoje <b>— kWh</b></span><span>$ Custo estimado <b>—</b></span></div></article><article class="panel events-panel"><div class="panel-head"><h2>Últimos Eventos</h2><a href="admin_secao.php?pagina=reservas">Ver todos →</a></div><div class="events-list"><?php if($eventos): foreach($eventos as $ev):?><div class="event"><span class="event-icon">▦</span><div><strong>Reserva <?=e($ev['status'])?></strong><small><?=e($ev['codigo'].' · '.$ev['piso'])?></small></div><time><?=date('H:i',strtotime($ev['inicio']))?></time></div><?php endforeach;else:foreach($eventosDemo as $ev):?><div class="event"><span class="event-icon"> <?= $ev[3]==='bolt'?'ϟ':($ev[3]==='calendar'?'▦':'🚘')?> </span><div><strong><?=e($ev[0])?></strong><small><?=e($ev[1])?></small></div><time><?=e($ev[2])?></time></div><?php endforeach;endif;?></div></article></section>
</main>
<script>window.SP_DASHBOARD=<?=json_encode(['pisos'=>$dadosPisos,'series'=>$series],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;</script><script src="./js/adm.js"></script></body></html>