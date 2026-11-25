(() => {
  'use strict';
  const data = window.SP_DASHBOARD || { pisos: {}, series: {} };
  const $ = (id) => document.getElementById(id);
  const money = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
  let pisoAtual = Object.keys(data.pisos)[0];
  let graficoLotacao = null;
  let graficoFaturamento = null;
  function previsaoOcupacao(pct) { return [Math.max(0, pct - 28), Math.max(0, pct - 12), Math.min(100, pct + 4), Math.min(100, pct + 12), Math.min(100, pct + 3), Math.max(0, pct - 8)]; }
  const totalKpi = $('kpiTotal'), livresKpi = $('kpiLivres'), ocupadasKpi = $('kpiOcupadas'), eletricasKpi = $('kpiEletricas');

  function renderPiso(nome) {
    const piso = data.pisos[nome]; if (!piso) return;
    pisoAtual = nome;
    document.querySelectorAll('.floor-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.floor === nome));
    const setor = $('setorFiltro').value;
    const grid = $('slotsGrid'); grid.innerHTML = '';
    piso.vagas.filter(v => setor === 'todos' || String(v.codigo).startsWith(setor)).forEach(v => {
      const ocupado = v.status === 'ocupada', manut = v.status === 'manutencao', eletrica = v.categoria === 'eletrica';
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'slot' + (ocupado ? ' occupied' : '') + (eletrica ? ' electric' : '') + (manut ? ' maintenance' : '');
      btn.dataset.codigo = v.codigo; btn.dataset.status = v.status;
      btn.innerHTML = `<span class="${eletrica ? 'bolt' : 'vehicle'}">${eletrica ? 'ϟ' : '🚘'}</span><strong></strong><small></small>`;
      btn.querySelector('strong').textContent = v.codigo;
      btn.querySelector('small').textContent = manut ? 'Manutenção' : (ocupado ? 'Ocupada' : (eletrica ? 'Livre · Elétrica' : 'Livre'));
      btn.title = `${v.codigo} · ${btn.querySelector('small').textContent}`;
      grid.appendChild(btn);
    });
    const total = piso.total, ocupadas = piso.ocupadas, livres = piso.livres, eletricas = piso.eletricas;
    totalKpi.textContent = total; livresKpi.textContent = livres; ocupadasKpi.textContent = ocupadas; eletricasKpi.textContent = eletricas;
    $('kpiLivrePct').textContent = `${total ? Math.round(livres / total * 100) : 0}% disponíveis`;
    $('kpiOcupPct').textContent = `${piso.ocupacao}% em uso`;
    $('ocupacaoPct').textContent = piso.ocupacao + '%';
    $('ocupacaoNumeros').textContent = `${ocupadas} / ${total} vagas`;
    $('donutOcupacao').style.background = `conic-gradient(#14b8a6 ${piso.ocupacao * 3.6}deg,#dce6ee 0deg)`;
    $('floorSummary').textContent = `${nome}: ${total} vagas · ${livres} livres · ${ocupadas} ocupadas · ${piso.manutencao} em manutenção`;
    const bars = $('ocupacaoBarras'); bars.innerHTML = '';
    const count = 10; for (let i = 0; i < count; i++) { const el = document.createElement('i'); const value = Math.max(8, Math.min(100, ((i + 1) / count) * piso.ocupacao)); el.style.height = value + '%'; el.style.opacity = String(.3 + value / 140); bars.appendChild(el); }
    const eletricasOcupadas = piso.vagas.filter(v => v.categoria === 'eletrica' && v.status === 'ocupada').length;
    const eletricasLivres = Math.max(0, eletricas - eletricasOcupadas);
    $('chargeCount').textContent = `${eletricasOcupadas}/${eletricas}`;
    $('chargeFree').textContent = eletricasLivres;
    $('chargeBusy').textContent = eletricasOcupadas;
    const pct = eletricas ? eletricasOcupadas / eletricas * 100 : 0;
    $('chargeDonut').style.background = `conic-gradient(#14b8a6 ${pct * 3.6}deg,#dce6ee 0deg)`;
    if (graficoLotacao) { graficoLotacao.data.datasets[0].data = previsaoOcupacao(piso.ocupacao); graficoLotacao.update(); }
  }
  document.querySelectorAll('.floor-btn').forEach(btn => btn.addEventListener('click', () => renderPiso(btn.dataset.floor)));
  $('setorFiltro').addEventListener('change', () => renderPiso(pisoAtual));
  $('buscaVaga').addEventListener('input', e => {
    const termo = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#slotsGrid .slot').forEach(slot => slot.hidden = !!termo && !slot.textContent.toLowerCase().includes(termo));
  });

  if (window.Chart) {
    const ctx = $('graficoLotacao').getContext('2d');
    const initial = data.series['Hoje'] || { labels: [], valores: [] };
    const gradient = ctx.createLinearGradient(0, 0, 0, 180); gradient.addColorStop(0, 'rgba(20,184,166,.34)'); gradient.addColorStop(1, 'rgba(20,184,166,.02)');
    const labelsLotacao = { Hoje: ['06h', '09h', '12h', '15h', '18h', '21h'], '7 dias': ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], '30 dias': ['01', '06', '11', '16', '21', '26', '30'] };
    graficoLotacao = new Chart(ctx, { type: 'line', data: { labels: labelsLotacao['Hoje'], datasets: [{ label: 'Ocupação prevista', data: previsaoOcupacao(data.pisos[pisoAtual]?.ocupacao || 0), borderColor: '#14b8a6', backgroundColor: gradient, fill: true, tension: .4, borderWidth: 2, pointRadius: 2, pointBackgroundColor: '#14b8a6' }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.raw + '% de ocupação' } } }, scales: { y: { beginAtZero: true, max: 100, ticks: { stepSize: 25, callback: v => v + '%' } }, x: { grid: { display: false } } } } });
    $('periodoGrafico').addEventListener('change', e => { graficoLotacao.data.labels = labelsLotacao[e.target.value] || labelsLotacao['Hoje']; graficoLotacao.update(); });
    const fctx = $('graficoFaturamento').getContext('2d'); const fg = fctx.createLinearGradient(0, 0, 0, 45); fg.addColorStop(0, 'rgba(20,184,166,.4)'); fg.addColorStop(1, 'rgba(20,184,166,0)');
    graficoFaturamento = new Chart(fctx, { type: 'line', data: { labels: initial.labels, datasets: [{ data: initial.valores, borderColor: '#14b8a6', backgroundColor: fg, fill: true, tension: .45, borderWidth: 2, pointRadius: 0 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false, beginAtZero: true } }, layout: { padding: 0 } } });
  }
  if (pisoAtual) renderPiso(pisoAtual);
})();
