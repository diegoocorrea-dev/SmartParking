<?php
require_once __DIR__ . '/init.php';
$nav_usuario = usuario_logado();
?>
<style>
  :root {
    --teal: #16c9a6;
    --navy: #12263f;
    --pad: 56px;
    --dur: .6s;
    --ease: cubic-bezier(.65, 0, .35, 1);
  }

  * {
    box-sizing: border-box;
    margin: 0;
  }

  body {
    font-family: "Segoe UI", system-ui, sans-serif;
    background: #eefafa;
    color: var(--navy);
  }

  html {
    scroll-padding-top: 80px;
    scroll-behavior: smooth;
  }

  .nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    height: 80px;
    background: #fff;
    box-shadow: 0 1px 0 rgba(0, 0, 0, .06);
    overflow: hidden;
  }

  /* LOGO - BOTÃO HOME */

  .logo {
    position: absolute;
    top: 50%;
    left: var(--pad);

    display: flex;
    align-items: center;
    gap: 10px;

    text-decoration: none;
    color: var(--navy);
    font-size: 32px;
    letter-spacing: -.5px;
    white-space: nowrap;

    transform: translate(0, -50%);

    transition:
      transform var(--dur) var(--ease),
      opacity .3s ease;

    cursor: pointer;
  }

  .logo:hover {
    opacity: .8;
  }

  .logo .badge {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--teal);
    color: #fff;

    display: grid;
    place-items: center;

    font-weight: 700;
    font-size: 24px;
    letter-spacing: 0;
  }

  .logo span b {
    font-weight: 800;
  }

  .nav.collapsed .logo {
    transform: translate(var(--shift, 0px), -50%);
  }

  /* LADO DIREITO */

  .right {
    position: absolute;
    top: 0;
    right: var(--pad);

    height: 100%;

    display: flex;
    align-items: center;
    gap: 36px;
  }

  .links {
    display: flex;
    gap: 36px;

    list-style: none;
    padding: 0;

    transition:
      opacity .35s ease,
      transform var(--dur) var(--ease);
  }

  .links a {
    position: relative;
    display: block;

    padding: 6px 0;

    font-size: 16px;
    font-weight: 500;

    color: var(--navy);
    text-decoration: none;

    cursor: pointer;
  }

  /* LINHA INFERIOR DOS LINKS */

  .links a::after {
    content: "";
    position: absolute;

    left: 0;
    bottom: -6px;

    width: 100%;
    height: 3px;

    border-radius: 2px;
    background: var(--teal);

    transform: scaleX(0);
    transform-origin: left;

    transition: transform .3s ease;
  }

  .links a:hover::after,
  .links a.active::after {
    transform: scaleX(1);
  }

  .links a.active {
    font-weight: 700;
  }

  /* ESCONDER LINKS AO RECOLHER */

  .links .nav-user {
    font-weight: 600;
    opacity: .7;
    white-space: nowrap;
  }

  .nav.collapsed .links {
    opacity: 0;
    transform: translateX(40px);
    pointer-events: none;
  }

  /* HAMBÚRGUER */

  .burger {
    width: 28px;
    height: 22px;

    background: none;
    border: 0;
    padding: 0;

    cursor: pointer;
    position: relative;
  }

  .burger i {
    position: absolute;
    left: 0;

    width: 100%;
    height: 2.5px;

    border-radius: 2px;
    background: var(--navy);

    transition:
      transform .4s var(--ease),
      opacity .2s ease,
      top .4s var(--ease);
  }

  .burger i:nth-child(1) {
    top: 2px;
  }

  .burger i:nth-child(2) {
    top: 10px;
  }

  .burger i:nth-child(3) {
    top: 18px;
  }

  /* MENU ABERTO = X */

  .nav:not(.collapsed) .burger i:nth-child(1) {
    top: 10px;
    transform: rotate(45deg);
  }

  .nav:not(.collapsed) .burger i:nth-child(2) {
    opacity: 0;
  }

  .nav:not(.collapsed) .burger i:nth-child(3) {
    top: 10px;
    transform: rotate(-45deg);
  }

  /* RESPONSIVIDADE */

  @media (max-width: 720px) {

    :root {
      --pad: 20px;
    }

    .logo {
      font-size: 24px;
    }

    .logo .badge {
      width: 36px;
      height: 36px;
      font-size: 19px;
    }

    .links {
      gap: 18px;
    }

    .links a {
      font-size: 14px;
    }

    .right {
      gap: 18px;
    }
  }
</style>

<header class="nav" id="nav">

  <!-- LOGO SMARTPARKING = HOME -->

  <a class="logo" id="logo" href="index.php" aria-label="Home">

    <div class="badge">P</div>

    <span>Smart<b>Parking</b></span>

  </a>

  <!-- MENU DIREITO -->

  <div class="right">

    <ul class="links" id="links">
      <li>
        <a href="index.php#home">Home</a>
      </li>

      <li>
        <a href="index.php#vagas-eletricas">Vagas Elétricas</a>
      </li>

      <li>
        <a href="index.php#sobre-nos">Sobre Nós</a>
      </li>

      <?php if ($nav_usuario && in_array($nav_usuario['perfil'], ['admin', 'operador'], true)): ?>
        <li><a href="adm.php">Adm</a></li>
      <?php endif; ?>

      <?php if ($nav_usuario): ?>
        <?php if (($nav_usuario['perfil'] ?? '') === 'cliente'): ?>
          <li><a href="pagamento.php">Minhas reservas</a></li>
        <?php endif; ?>
        <li><a href="suporte.php">Suporte</a></li>
        <li><span class="nav-user">Olá, <?= e(explode(' ', trim($nav_usuario['nome']))[0]) ?></span></li>
        <li><a href="logout.php">Sair</a></li>
      <?php else: ?>
        <li><a href="suporte.php">Suporte</a></li>
        <li><a href="login.php">Entrar/Cadastro</a></li>
      <?php endif; ?>

    </ul>

    <!-- BOTÃO HAMBÚRGUER -->

    <button class="burger" id="burger" aria-label="Menu" aria-expanded="true" type="button">
      <i></i>
      <i></i>
      <i></i>
    </button>

  </div>

</header>

<script>

  const nav = document.getElementById('nav');
  const logo = document.getElementById('logo');
  const burger = document.getElementById('burger');

  // CENTRALIZA A LOGO AO RECOLHER A NAVBAR

  function updateShift() {

    const pad = parseFloat(
      getComputedStyle(document.documentElement)
        .getPropertyValue('--pad')
    );

    const shift =
      (nav.clientWidth - logo.offsetWidth) / 2 - pad;

    nav.style.setProperty('--shift', shift + 'px');

  }

  updateShift();

  window.addEventListener('resize', updateShift);

  // RECOLHER OU EXPANDIR NAVBAR

  function setCollapsed(state) {

    nav.classList.toggle('collapsed', state);

    burger.setAttribute(
      'aria-expanded',
      String(!state)
    );

  }

  // CLIQUE NO HAMBÚRGUER

  burger.addEventListener('click', () => {

    setCollapsed(
      !nav.classList.contains('collapsed')
    );

  });

</script>