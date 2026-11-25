/* =====================================================
   1) FILTROS E VAGAS
   ===================================================== */
const slots = document.querySelectorAll('#grid .slot');
const filtros = document.querySelectorAll('.filter');

const slotStatus = document.getElementById('slotStatus');
const slotTitle = document.getElementById('slotTitle');
const slotId = document.getElementById('slotId');
const slotDescription = document.getElementById('slotDescription');
const useSlot = document.getElementById('useSlot');

let vagaSelecionada = null;

// ---- Filtros ----
filtros.forEach(function (botao) {
  botao.addEventListener('click', function () {
    filtros.forEach(function (b) { b.classList.remove('active'); });
    botao.classList.add('active');
    aplicarFiltro(botao.dataset.filter);
  });
});

function aplicarFiltro(filtro) {
  slots.forEach(function (slot) {
    let mostrar = true;
    if (filtro === 'livre') mostrar = slot.dataset.status === 'livre';
    if (filtro === 'eletrica') mostrar = slot.dataset.type === 'eletrica';
    slot.classList.toggle('hide', !mostrar);
  });
}

// ---- Seleção de vaga ----
// data-id é o id da vaga no banco; o código (A01) pode repetir em pisos diferentes
slots.forEach(function (slot) {
  slot.addEventListener('click', function () {
    slots.forEach(function (s) { s.classList.remove('selected'); });
    slot.classList.add('selected');

    const codigo = slot.dataset.codigo;
    const nome = slot.dataset.piso + ' · ' + codigo;
    const status = slot.dataset.status;
    const eletrica = slot.dataset.type === 'eletrica';

    slotId.textContent = nome;
    useSlot.classList.remove('done');

    if (status !== 'livre') {
      vagaSelecionada = null;
      slotStatus.textContent = status === 'manutencao' ? 'VAGA EM MANUTENÇÃO' : 'VAGA OCUPADA';
      slotStatus.style.color = status === 'manutencao' ? '#c98a1b' : '#71828d';
      slotTitle.textContent = 'Indisponível';
      slotDescription.textContent = status === 'manutencao'
        ? 'A vaga ' + codigo + ' (' + slot.dataset.piso + ') está em manutenção. Escolha uma vaga livre.'
        : 'A vaga ' + codigo + ' (' + slot.dataset.piso + ') está ocupada no momento. Escolha uma vaga livre.';
      useSlot.disabled = true;
      useSlot.textContent = 'Escolher uma vaga';
    } else {
      vagaSelecionada = slot.dataset.id;
      slotStatus.textContent = 'VAGA LIVRE';
      slotStatus.style.color = '#00bda9';
      slotTitle.textContent = eletrica ? 'Vaga com carregador' : 'Vaga comum';
      slotDescription.textContent = eletrica
        ? 'A vaga ' + codigo + ' (' + slot.dataset.piso + ') está livre e possui carregador ' +
        (slot.dataset.conector || '') + ' de ' + slot.dataset.potencia.replace('.', ',') + ' kW.'
        : 'A vaga ' + codigo + ' (' + slot.dataset.piso + ') está livre e pronta para uso.';
      useSlot.disabled = false;
      useSlot.textContent = 'Escolher vaga ' + codigo;
    }
  });
});

// Leva para a página de reserva com a vaga escolhida (lá é pedido o login, se preciso)
useSlot.addEventListener('click', function () {
  if (!vagaSelecionada) return;
  useSlot.disabled = true;
  useSlot.textContent = 'Abrindo reserva…';
  window.location.href = 'reservar.php?vaga=' + encodeURIComponent(vagaSelecionada);
});


/* =====================================================
   2) GRÁFICOS  (mude os valores aqui para ajustar tudo)
   ===================================================== */
// Valores vindos do banco (window.SP_CONFIG é montado no index.php); os números abaixo são só reserva
const CFG = window.SP_CONFIG || {};
const PRECO_HORA = Number(CFG.precoHora) || 6;        // R$ por hora de estadia
const POTENCIA_KW = Number(CFG.potenciaKw) || 22;     // potência do carregador (kW)
const PRECO_KWH = Number(CFG.precoKwh) || 1.5;        // R$ por kWh
const BATERIA_KWH = Number(CFG.bateriaKwh) || 60;     // limite de energia que o carro aceita
const MAX_HORAS = 12;      // quantidade de barras

function brl(valor) {
  return valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

// Gráfico 1: só estadia
function dadosGrafico1(h) {
  return { estadia: PRECO_HORA * h, carga: 0 };
}

// Gráfico 2: estadia + carga (carga limitada pela bateria)
function dadosGrafico2(h) {
  const energia = Math.min(POTENCIA_KW * h, BATERIA_KWH);
  return { estadia: PRECO_HORA * h, carga: energia * PRECO_KWH };
}

function criarGrafico(cfg) {
  const canvas = document.getElementById(cfg.canvas);
  const tip = document.getElementById(cfg.tip);
  const range = document.getElementById(cfg.range);
  const saidaHoras = document.getElementById(cfg.horasOut);
  const saidaTotal = document.getElementById(cfg.total);

  let hover = 0; // barra sob o mouse (0 = nenhuma)
  const pad = { esq: 52, dir: 8, topo: 10, base: 24 };

  // Ajusta o canvas à tela (nitidez em telas retina)
  function preparar() {
    const r = window.devicePixelRatio || 1;
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    canvas.width = w * r;
    canvas.height = h * r;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(r, 0, 0, r, 0, 0);
    return { ctx: ctx, w: w, h: h };
  }

  function desenhar() {
    const c = preparar();
    const ctx = c.ctx;
    const areaW = c.w - pad.esq - pad.dir;
    const areaH = c.h - pad.topo - pad.base;
    const sel = Number(range.value);

    // valor máximo para a escala vertical
    const d = cfg.dados(MAX_HORAS);
    const maxVal = (d.estadia + d.carga) * 1.1;

    ctx.clearRect(0, 0, c.w, c.h);
    ctx.font = '10px sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    // linhas de grade + valores do eixo Y
    for (let i = 0; i <= 4; i++) {
      const y = pad.topo + areaH - (areaH * i) / 4;
      ctx.strokeStyle = '#e0eaee';
      ctx.beginPath();
      ctx.moveTo(pad.esq, y);
      ctx.lineTo(c.w - pad.dir, y);
      ctx.stroke();
      ctx.fillStyle = '#8a9aa4';
      ctx.fillText('R$ ' + Math.round((maxVal * i) / 4), pad.esq - 6, y);
    }

    // barras
    const slotW = areaW / MAX_HORAS;
    const barW = slotW * 0.6;
    ctx.textAlign = 'center';

    for (let h = 1; h <= MAX_HORAS; h++) {
      const v = cfg.dados(h);
      const x = pad.esq + slotW * (h - 1) + (slotW - barW) / 2;
      const hEstadia = (v.estadia / maxVal) * areaH;
      const hCarga = (v.carga / maxVal) * areaH;
      const yBase = pad.topo + areaH;

      // barras até a hora escolhida ficam fortes; as outras, claras
      ctx.globalAlpha = h <= sel ? 1 : 0.3;
      if (h === hover) ctx.globalAlpha = 0.8;

      ctx.fillStyle = cfg.corEstadia;
      ctx.fillRect(x, yBase - hEstadia, barW, hEstadia);
      if (hCarga > 0) {
        ctx.fillStyle = '#14B8A6';
        ctx.fillRect(x, yBase - hEstadia - hCarga, barW, hCarga);
      }
      ctx.globalAlpha = 1;

      // número da hora no eixo X
      ctx.fillStyle = h === sel ? '#0F172A' : '#8a9aa4';
      ctx.font = (h === sel ? 'bold ' : '') + '10px sans-serif';
      ctx.textBaseline = 'top';
      ctx.fillText(h, x + barW / 2, yBase + 6);
    }
  }

  // Atualiza textos e a barra verde do slider
  function atualizar() {
    const h = Number(range.value);
    const v = cfg.dados(h);
    saidaHoras.textContent = h + ' h';
    saidaTotal.textContent = brl(v.estadia + v.carga);
    range.style.setProperty('--pct', ((range.value - range.min) / (range.max - range.min)) * 100 + '%');
    desenhar();
  }

  // Descobre qual barra está sob o mouse
  function barraDoMouse(e) {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left - pad.esq;
    const slotW = (canvas.clientWidth - pad.esq - pad.dir) / MAX_HORAS;
    const h = Math.floor(x / slotW) + 1;
    return h >= 1 && h <= MAX_HORAS ? h : 0;
  }

  canvas.addEventListener('mousemove', function (e) {
    hover = barraDoMouse(e);
    desenhar();
    if (!hover) { tip.style.display = 'none'; return; }

    const v = cfg.dados(hover);
    let html = '<b>' + hover + ' h</b><br>Estadia: ' + brl(v.estadia);
    if (cfg.mostraCarga) html += '<br>Carga: ' + brl(v.carga);
    html += '<br><b>Total: ' + brl(v.estadia + v.carga) + '</b>';
    tip.innerHTML = html;
    tip.style.display = 'block';

    // posiciona o tooltip perto do mouse
    const box = canvas.parentElement.getBoundingClientRect();
    let left = e.clientX - box.left + 12;
    if (left + tip.offsetWidth > box.width) left = e.clientX - box.left - tip.offsetWidth - 12;
    tip.style.left = left + 'px';
    tip.style.top = e.clientY - box.top - 10 + 'px';
  });

  canvas.addEventListener('mouseleave', function () {
    hover = 0;
    tip.style.display = 'none';
    desenhar();
  });

  // clicar na barra move o slider para aquela hora
  canvas.addEventListener('click', function (e) {
    const h = barraDoMouse(e);
    if (h) { range.value = h; atualizar(); }
  });

  range.addEventListener('input', atualizar);
  window.addEventListener('resize', desenhar);
  atualizar();
}

criarGrafico({
  canvas: 'chart1', tip: 'tip1', range: 'hours1',
  horasOut: 'hoursOut1', total: 'total1',
  dados: dadosGrafico1, corEstadia: '#14B8A6', mostraCarga: false
});

criarGrafico({
  canvas: 'chart2', tip: 'tip2', range: 'hours2',
  horasOut: 'hoursOut2', total: 'total2',
  dados: dadosGrafico2, corEstadia: '#0F7A66', mostraCarga: true
});