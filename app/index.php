<?php
require_once 'vagas_dados.php';   // sessão e banco antes do HTML

$vagas = carregar_vagas();
$cfg = config_preco();
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartParking | Estacionamento inteligente</title>
    <link rel="stylesheet" href="./css/home.css">
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <!----------------------------------------------------------PÁGINA PRINCIPAL(HOME) ---------------------------------------------------------->
    <section class="home" id="home">
        <video autoplay muted loop playsinline class="video-fundo">
            <source src="./video/estacionamento.mp4" type="video/mp4">
        </video>
        <div class="conteudo-hom">
            <div class="Titulo">
                <h1>Mais Controle.<br>
                    Mais Eficiência.<br>
                    <span class="txt_colorido"> Mais Economia.</span>
                </h1>
            </div>
            <div class="subtitulo">
                <h3> Gerencie seu estacionamento<br> com uma plataforma moderna,<br> segura e sustentável.
                </h3>
            </div>

            <a class="btn-comecar" href="#vagas">
                Começar agora
                <span>→</span>
            </a>
            <a class="saiba" href="#sobre-nos"><strong><span>→</span> Saiba Mais</strong></a>


            <br>
            <br>

            <div class="grupos">
                <div class="grupo">
                    <img src="./img/img_box1.png" alt="">
                    <p class="p1">Controle de entrada
                        e saída
                    </p>

                </div>

                <div class="grupo">
                    <img src="./img/img_box2.png" alt="">
                    <p class="p2">Vagas em tempo real
                    </p>

                </div>

                <div class="grupo">
                    <img src="./img/img_box3.png" alt="">
                    <p class="p3">Sistema de segurança verificado
                        e completo
                    </p>

                </div>
            </div>
        </div>

    </section>



    <!----------------------------------------------------------VAGAS ELÉTRICAS ---------------------------------------------------------->


    <section class="vagas_eletricas" id="vagas-eletricas">
        <?php
        // Cada fila é uma lista de tipos de vaga: 'comum' ou 'eletrica'
        $ve_fila_cima = ['comum', 'comum', 'comum', 'comum', 'eletrica', 'eletrica'];
        $ve_fila_baixo = ['eletrica', 'comum', 'eletrica', 'comum', 'comum', 'eletrica'];

        // Cartões: as primeiras 8 vagas com carregador (elétricas e híbridas) do banco
        $ve_vagas = array_slice(array_values(array_filter($vagas, function ($v) {
            return $v['tem_carregador']; })), 0, 8);

        // Conta quantas vagas estão livres, ocupadas e em manutenção (aparece na legenda)
        $ve_cont = contar_vagas($ve_vagas);
        $ve_livres = $ve_cont['livre'];
        $ve_ocupadas = $ve_cont['ocupada'];
        $ve_manutencao = $ve_cont['manutencao'];

        // Raio dentro de um círculo que pulsa
        function ve_raio()
        {
            return '<span class="ve-raio-caixa">
                <svg class="ve-raio" viewBox="0 0 40 70">
                    <polygon points="26,0 2,38 17,38 10,68 38,26 22,26 32,0"/>
                </svg>
            </span>
            <span class="ve-rotulo">Carregador</span>';
        }

        // Cartão com as informações de uma vaga
        function ve_cartao($v)
        {
            $status = $v['status'];
            $livre = ($status === 'livre');

            echo '<div class="ve-cartao' . ($livre ? '' : ' ve-cartao-ocupado') . '">';
            echo '<div class="ve-cartao-topo">';
            echo '<h3>Vaga ' . e($v['codigo']) . '</h3>';
            echo '<span class="ve-status ' . e($status) . '">' . e(rotulo_status($status)) . '</span>';
            echo '</div>';
            echo '<p class="ve-info"><span>' . e($v['piso']) . '</span><span>' . e(rotulo_categoria($v['categoria'])) . '</span><span>' . e(rotulo_conector($v['conector'])) . '</span><span class="ve-potencia">' . e(fmt_kw($v['potencia_kw'])) . ' kW</span></p>';
            if ($status === 'livre') {
                // link estilizado como botão (botão dentro de <a> não é HTML válido)
                $rot = 'vaga ' . $v['codigo'] . ', ' . $v['piso'];
                echo '<a class="ve-reservar" href="reservar.php?vaga=' . $v['id'] . '" aria-label="Reservar ' . e($rot) . '">Reservar ' . e($v['codigo']) . ' <span>→</span></a>';
            } else {
                echo '<span class="ve-indisponivel">' . ($status === 'manutencao' ? 'Em manutenção' : 'Indisponível agora') . '</span>';
            }
            echo '</div>';
        }

        // Desenha uma fila inteira.
// Se $vagas vier preenchido, cada vaga comum recebe 2 cartões:
// o da linha de cima (posição $i) e o da linha de baixo (posição $i + 4).
        function ve_fila($fila, $classe, $vagas = null)
        {
            echo '<div class="ve-fila ' . $classe . '">';
            foreach ($fila as $i => $tipo) {
                if ($tipo === 'eletrica') {
                    echo '<div class="ve-vaga ve-vaga-eletrica">' . ve_raio() . '</div>';
                } elseif ($vagas) {
                    echo '<div class="ve-vaga">';
                    if (isset($vagas[$i])) {
                        ve_cartao($vagas[$i]);
                    }
                    if (isset($vagas[$i + 4])) {
                        ve_cartao($vagas[$i + 4]);
                    }
                    echo '</div>';
                } else {
                    echo '<div class="ve-vaga ve-vaga-vazia"></div>';
                }
            }
            echo '</div>';
        }
        ?>

        <header class="ve-cabecalho">
            <span class="ve-etiqueta">Vagas com carregador</span>
            <h2 class="ve-titulo">Estacionamento inteligente para <span>veículos elétricos</span></h2>
            <p class="ve-sub">Escolha uma vaga livre, confira o tipo de conector e a potência do carregador e reserve em
                poucos cliques.</p>

            <ul class="ve-legenda">
                <li><i class="ve-ponto livre"></i><?= $ve_livres ?> livres</li>
                <li><i class="ve-ponto ocupada"></i><?= $ve_ocupadas ?> ocupadas</li>
                <?php if ($ve_manutencao > 0): ?>
                    <li><i class="ve-ponto manutencao"></i><?= $ve_manutencao ?> em manutenção</li><?php endif; ?>
                <li><i class="ve-ponto carga"></i>Ponto de carregador</li>
            </ul>
        </header>

        <div class="ve-lote">
            <?php ve_fila($ve_fila_cima, 've-fila-cima', $ve_vagas); ?>

            <!-- Corredor com o carro passando (SVG visto de cima, virado para a direita) -->
            <div class="ve-corredor">
                <svg class="ve-carro" viewBox="0 0 160 76">
                    <defs>
                        <linearGradient id="ve-corpo" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#c3d0d8" />
                            <stop offset="1" stop-color="#8296a2" />
                        </linearGradient>
                    </defs>
                    <!-- rodas -->
                    <rect x="22" y="2" width="26" height="9" rx="4" fill="#0b121b" />
                    <rect x="22" y="65" width="26" height="9" rx="4" fill="#0b121b" />
                    <rect x="104" y="2" width="26" height="9" rx="4" fill="#0b121b" />
                    <rect x="104" y="65" width="26" height="9" rx="4" fill="#0b121b" />
                    <!-- carroceria -->
                    <rect x="4" y="8" width="152" height="60" rx="24" fill="url(#ve-corpo)" />
                    <!-- vidros: para-brisa (frente, à direita) e vidro traseiro -->
                    <path d="M96 16 L122 20 Q128 38 122 56 L96 60 Z" fill="#26343f" />
                    <path d="M40 18 L30 20 Q26 38 30 56 L40 58 Z" fill="#26343f" />
                    <!-- teto -->
                    <rect x="40" y="17" width="56" height="42" rx="8" fill="#a9b8c1" />
                    <!-- faróis e lanternas -->
                    <ellipse cx="150" cy="20" rx="4" ry="6" fill="#fff6c8" />
                    <ellipse cx="150" cy="56" rx="4" ry="6" fill="#fff6c8" />
                    <rect x="4" y="18" width="4" height="10" rx="2" fill="#e5484d" />
                    <rect x="4" y="48" width="4" height="10" rx="2" fill="#e5484d" />
                </svg>
            </div>

            <?php ve_fila($ve_fila_baixo, 've-fila-baixo'); ?>
        </div>

    </section>
    <!----------------------------------------------------------SISTEMA DE VAGAS---------------------------------------------------------->


    <section class="section" id="vagas">
        <div class="section-head">
            <?php
            $cont = contar_vagas($vagas);
            $livres = $cont['livre'];
            $ocupadas = $cont['ocupada'];
            $carregadores = $cont['carregadores'];
            ?>



            <div>
                <div class="eyebrow">Disponibilidade</div>
                <h1 class="titulo_vagas">Escolha sua vaga</h1>
                <p class="subtitulo_vagas">Selecione uma vaga livre para consultar os detalhes e iniciar sua simulação.
                </p>
            </div>
            <div class="stats">
                <div class="stat"><strong id="freeCount"><?= $livres ?></strong><small>Vagas livres</small></div>
                <div class="stat"><strong id="busyCount"
                        style="color:#71828d"><?= $ocupadas ?></strong><small>Ocupadas</small></div>
                <div class="stat"><strong><?= $carregadores ?></strong><small>Carregadores</small></div>
            </div>
        </div>

        <div class="panel">
            <?php // legenda ?>
            <div class="legend">
                <span><i class="dot"></i>Livre</span>
                <span><i class="dot busy"></i>Ocupada</span>
                <span><i class="dot maint"></i>Manutenção</span>
                <span><i class="dot ev"></i>Carregador elétrico</span>
            </div>

            <?php // filtros ?>
            <div class="filters">
                <button type="button" class="filter active" data-filter="todas">Todas as vagas</button>
                <button type="button" class="filter" data-filter="livre">Somente livres</button>
                <button type="button" class="filter" data-filter="eletrica">Com carregador</button>
            </div>

            <div class="layout">
                <div class="grid" id="grid">
                    <?php foreach ($vagas as $v):
                        $classes = 'slot'
                            . ($v['status'] === 'ocupada' ? ' occupied' : '')
                            . ($v['status'] === 'manutencao' ? ' maintenance' : '')
                            . ($v['tem_carregador'] ? ' electric' : '');
                        $rotulo = $v['status'] !== 'livre'
                            ? rotulo_status($v['status'])
                            : ($v['tem_carregador'] ? 'Livre · Elétrica' : 'Livre');
                        ?>
                        <button type="button" class="<?= $classes ?>" data-id="<?= $v['id'] ?>"
                            data-codigo="<?= e($v['codigo']) ?>" data-piso="<?= e($v['piso']) ?>"
                            data-status="<?= e($v['status']) ?>"
                            data-type="<?= $v['tem_carregador'] ? 'eletrica' : 'comum' ?>"
                            data-conector="<?= e(rotulo_conector($v['conector'])) ?>"
                            data-potencia="<?= e(fmt_kw($v['potencia_kw'])) ?>">
                            <span
                                class="<?= $v['tem_carregador'] ? 'bolt' : 'vehicle' ?>"><?= $v['tem_carregador'] ? 'ϟ' : '🚘' ?></span>
                            <strong><?= e($v['codigo']) ?></strong>
                            <small><?= e($v['piso']) ?> · <?= e($rotulo) ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>

                <aside class="side" aria-live="polite">
                    <div>
                        <div id="slotStatus" style="color:#00bda9;font-size:11px;font-weight:bold">VAGA NÃO SELECIONADA
                        </div>
                        <h3 id="slotTitle" style="margin-top:8px">Selecione uma vaga</h3>
                    </div>
                    <div>
                        <div class="note">Identificação</div>
                        <div class="big" id="slotId">—</div>
                    </div>
                    <p id="slotDescription">Clique em uma vaga livre no mapa para consultar os detalhes.</p>
                    <button type="button" class="btn" id="useSlot" disabled>Escolher uma vaga</button>
                </aside>
            </div>
        </div>

        <?php // ================= GRÁFICOS ================= ?>
        <div class="charts">

            <article class="chart-card">
                <span class="calc-tag">Gráfico 1</span>
                <h3>Estadia por hora</h3>
                <p class="chart-sub">Valor da permanência: <?= e(brl($cfg['preco_hora'])) ?> por hora.</p>

                <div class="rangehead"><span>Tempo de permanência</span><strong id="hoursOut1">3 h</strong></div>
                <input type="range" id="hours1" class="calc-range" min="1" max="12" step="1" value="3">

                <div class="chart">
                    <canvas id="chart1"></canvas>
                    <div class="tip" id="tip1"></div>
                </div>
                <div class="chartfoot"><span>Passe o mouse ou clique em uma barra</span><span>horas</span></div>

                <div class="total">
                    <div><small>Total da estadia</small><strong
                            id="total1"><?= brl(total_estadia(3, $cfg, false)) ?></strong></div>
                </div>
            </article>

            <article class="chart-card">
                <span class="calc-tag">Gráfico 2</span>
                <h3>Estadia + carga elétrica por hora</h3>
                <p class="chart-sub">Carregador de
                    <?= e(rtrim(rtrim(number_format($cfg['potencia_padrao_kw'], 1, ',', ''), '0'), ',')) ?> kW a
                    <?= e(brl($cfg['preco_kwh'])) ?>/kWh (bateria de
                    <?= e(rtrim(rtrim(number_format($cfg['bateria_kwh'], 1, ',', ''), '0'), ',')) ?> kWh).</p>

                <div class="rangehead"><span>Tempo de permanência</span><strong id="hoursOut2">3 h</strong></div>
                <input type="range" id="hours2" class="calc-range" min="1" max="12" step="1" value="3">

                <div class="chart">
                    <canvas id="chart2"></canvas>
                    <div class="tip" id="tip2"></div>
                </div>
                <div class="chartfoot">
                    <span><i class="dot" style="background:#0F172A"></i>Estadia <i class="dot ev"
                            style="margin-left:8px"></i>Carga</span>
                    <span>horas</span>
                </div>

                <div class="total">
                    <div><small>Total estadia + carga</small><strong
                            id="total2"><?= brl(total_estadia(3, $cfg, true)) ?></strong></div>
                </div>
            </article>

        </div>
    </section>

    <script>
        window.SP_CONFIG = <?= json_encode([
            'precoHora' => $cfg['preco_hora'],
            'precoKwh' => $cfg['preco_kwh'],
            'bateriaKwh' => $cfg['bateria_kwh'],
            'potenciaKw' => $cfg['potencia_padrao_kw'],
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="./js/vagas.js"></script>

    <!---------------------------------------------------------- SOBRE NÓS ---------------------------------------------------------->
    <section class="sobre_nos" id="sobre-nos">

        <?php
        // ===== Textos e imagens (edite aqui) =====
        
        // Bloco 1
        $titulo1 = "SOBRE";
        $titulo2 = ' <span class="destaques">NÓS</span>';
        $descricao = 'Simplificando seu carregamento e sua gestão de estacionamentos, com <span class="destaquesr"><strong>tecnoogia e sustentabilidade </strong></span>.';
        $heading1 = "Inclusão Total e";
        $heading2 = "Gestão Inteligente.";
        $paragrafo = "SmartParking simplifica operações e maximiza sua receita. Nosso sistema inclusivo abrange todos os veículos (carros, motos, híbridos e elétricos), com faturamento preciso, carregadores elétricos, sistema de segurança completo e monitoramento em tempo real. Estamos prontos para o futuro! ";
        $indice = "SmartParking";


        // Bloco 2
$titulo3 = 'Muito além de <span class="destaque">estacionar</span>';
$titulo4 = 'uma <span class="destaque">nova forma de viver</span> a cidade';
$descricao2 = "A SmartParking surgiu para transformar estacionamentos em espaços mais ágeis, organizados e conectados com o dia a dia das pessoas.";
$descricao3 = "Aliamos tecnologia e praticidade para aproximar quem opera e quem estaciona, abrindo caminho para uma mobilidade mais inteligente e sustentável.";
$heading3 = "Mais controle,";
$heading4 = "menos complicação.";
$paragrafo2 = "Do primeiro acesso à saída do veículo, o motorista reserva, paga e recarrega pelo sistema, enquanto o operador acompanha vagas, reservas e faturamento em um único painel. Menos filas, menos erros e mais tempo para o que importa.";

        // Bloco 3
        $titulo5 = 'Porque escolher a <span class="destaquess">Smartparking</span>';
        $titulo6 = 'Conheça as nossas <span class="destaquess">técnologias</span>';


        $slides = [
            [
                "imagem" => "./img/estacionamento_carrosl.png",
                "titulo" => "Estacionamento Inteligente",
                "descricao" => "Uma nova maneira de estacionar, com tecnologia, praticidade e segurança."
            ],

            [
                "imagem" => "./img/placa.png",
                "titulo" => "Leitura Automática de Placas",
                "descricao" => "Nossa tecnologia ANPR identifica automaticamente as placas dos veículos."
            ],

            [
                "imagem" => "./img/imagem_1.png",
                "titulo" => "Mobilidade Sustentável",
                "descricao" => "Vagas equipadas com carregadores para veículos elétricos."
            ]
        ];


        // Imagens
        $imagemGrande = "./img/fundo_sobre.jpg";
        $imagemPequena = "./img/abastecer.png";
        $imagemReserva = "./img/estacionamento.png";
        $imagemGrande2 = "./img/02_cancela_leitor_acesso.png";
        $imagemPequena2 = "./img/camera.png";
        $imagemGrande3 = "./img/fundo_sobre.jpg";



        ?>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
            rel="stylesheet">

        <!-- ================= BLOCO 1 ================= -->
        <main class="about" id="sobre-nos">
            <div class="logo-orbita">
                <svg viewBox="0 0 400 400" aria-label="SmartParking">
                    <circle class="logo-orbita-tracos" cx="200" cy="200" r="155" />
                    <path class="logo-orbita-p" d="
      M130 110 Q130 85 155 85 H225 A73.5 73.5 0 0 1 225 232 H172 V285 Q172 300 157 300 H145 Q130 300 130 285 Z
      M172 118 H222 A40 40 0 0 1 222 198 H172 Z" />
                </svg>
            </div>

            <h1 class="title">
                <span class="mask"><span class="word word-1"><?= $titulo1 ?></span></span>
                <span class="mask"><span class="word word-2"><?= $titulo2 ?></span></span>
            </h1>

            <div class="left-copy">
                <p class="label fade fade-1"><?= $descricao ?></p>

            </div>

            <figure class="main-image">
                <img src="<?= $imagemGrande ?>" alt="Sala minimalista com texturas naturais e luz suave"
                    data-fallback="<?= $imagemReserva ?>">
                <span class="overlay"></span>
            </figure>

            <aside class="right-panel">
                <figure class="small-image">
                    <img src="<?= $imagemPequena ?>" alt="Detalhe de interior moderno"
                        data-fallback="<?= $imagemReserva ?>">
                    <span class="overlay"></span>
                </figure>

                <div class="right-text">
                    <div class="heading-mask">
                        <h2 class="heading"><?= $heading1 ?><br><?= $heading2 ?></h2>
                    </div>
                    <p class="right-paragraph"><?= $paragrafo ?></p>
                </div>
            </aside>

            <div class="index"><?= $indice ?></div>

        </main>

        <!-- ================= BLOCO 2 ================= -->
        <section class="about-extra">

            <h1 class="title2">
                <span class="mask"><span class="word word-1"><?= $titulo3 ?></span></span>
                <span class="mask"><span class="word word-2"><?= $titulo4 ?></span></span>
            </h1>

            <div class="left-copy1">
                <p class="label fade fade-3"><?= $descricao2 ?></p>
                <p class="description fade fade-4"><strong><?= $descricao3 ?></strong></p>
            </div>

            <aside class="left-panels">
                <figure class="small-images">
                    <img src="<?= $imagemPequena2 ?>" alt="Detalhe de interior moderno"
                        data-fallback="<?= $imagemReserva ?>">
                    <span class="overlay"></span>
                </figure>

                <div class="left-texts">
                    <div class="headings-mask">
                        <h2 class="headings"><?= $heading3 ?><br><?= $heading4 ?></h2>
                    </div>
                    <p class="left-paragraph"><?= $paragrafo2 ?></p>
                </div>
            </aside>

            <figure class="main-images">
                <img src="<?= $imagemGrande2 ?>" alt="Sala minimalista com texturas naturais e luz suave"
                    data-fallback="<?= $imagemReserva ?>">
                <span class="overlay"></span>
            </figure>

            <div class="index"><?= $indice ?></div>

        </section>

        <script src="./js/home.js"></script>

        <!-- ================= BLOCO 3 ================= -->

        <section class="about-extras">

            <h1 class="title3">
                <span class="mask">
                    <span class="word word-1"><?= $titulo5 ?></span>
                </span>

                <span class="mask">
                    <span class="word word-2"><?= $titulo6 ?></span>
                </span>
            </h1>

            <div class="index"><?= $indice ?></div>

            <div class="carrossel">

                <!-- Seta esquerda -->
                <button class="seta esquerda" onclick="voltar()">
                    &#10094;
                </button>

                <!-- Conteúdo dos slides -->
                <div class="carrossel-conteudo">

                    <?php foreach ($slides as $index => $slide) { ?>

                        <div class="slide <?= $index == 0 ? 'ativo' : '' ?>">

                            <img src="<?= $slide['imagem'] ?>" alt="SmartParking">

                            <div class="texto">
                                <h2><?= $slide['titulo'] ?></h2>

                                <p><?= $slide['descricao'] ?></p>
                            </div>

                        </div>

                    <?php } ?>

                </div>

                <!-- Seta direita -->
                <button class="seta direita" onclick="avancar()">
                    &#10095;
                </button>

            </div>

        </section>

    </section><!-- fecha .sobre_nos -->

    <script src="./js/carrosel.js"></script>


</body>

</html>