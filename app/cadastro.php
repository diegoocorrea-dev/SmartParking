<img class="fundo" src="img/fundo_cadastro.jpg" alt="">
<?php
require_once 'init.php';

// Quem já está logado não precisa se cadastrar
if (usuario_logado()) {
  redirecionar('index.php');
}

// CPF: 11 dígitos + dígitos verificadores
function cpf_valido(string $cpf): bool
{
  if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
    return false;
  }
  for ($t = 9; $t < 11; $t++) {
    $soma = 0;
    for ($i = 0; $i < $t; $i++) {
      $soma += (int) $cpf[$i] * (($t + 1) - $i);
    }
    $digito = ((10 * $soma) % 11) % 10;
    if ((int) $cpf[$t] !== $digito) {
      return false;
    }
  }
  return true;
}

// Mensagem de erro logo abaixo do campo
function campo_erro(array $erros, string $campo): void
{
  if (isset($erros[$campo])) {
    echo '<small class="erro">' . e($erros[$campo]) . '</small>';
  }
}

$erros = [];
$dados = [
  'nome' => '',
  'email' => '',
  'cpf' => '',
  'telefone' => '',
  'modelo' => '',
  'nome_veiculo' => '',
  'placa' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $dados['nome'] = trim(preg_replace('/\s+/', ' ', post_str('nome')));
  $dados['email'] = mb_strtolower(trim(post_str('email')));
  $dados['cpf'] = preg_replace('/\D/', '', post_str('cpf'));
  $dados['telefone'] = preg_replace('/\D/', '', post_str('telefone'));
  $dados['modelo'] = post_str('modelo');
  $dados['nome_veiculo'] = trim(preg_replace('/\s+/', ' ', post_str('nome_veiculo')));
  $dados['placa'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post_str('placa')));
  $senha = post_str('senha');     // senha NUNCA leva trim()
  $senha2 = post_str('senha2');

  if (!csrf_ok(post_str('csrf'))) {
    $erros['geral'] = 'Sessão expirada. Recarregue a página e tente novamente.';
  } else {
    // ----- Validação no servidor -----
    $tam = mb_strlen($dados['nome']);
    if ($tam < 3 || $tam > 120) {
      $erros['nome'] = 'Informe seu nome completo.';
    }
    if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($dados['email']) > 160) {
      $erros['email'] = 'Informe um e-mail válido.';
    }
    if (!cpf_valido($dados['cpf'])) {
      $erros['cpf'] = 'CPF inválido.';
    }
    if (!preg_match('/^\d{10,11}$/', $dados['telefone'])) {
      $erros['telefone'] = 'Telefone inválido. Use DDD + número.';
    }
    if (strlen($senha) < 8) {
      $erros['senha'] = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif (strlen($senha) > 72) {
      $erros['senha'] = 'A senha deve ter no máximo 72 caracteres.';
    } elseif ($senha !== $senha2) {
      $erros['senha2'] = 'As senhas não conferem.';
    }
    if (!in_array($dados['modelo'], ['eletrico', 'combustao', 'hibrido'], true)) {
      $erros['modelo'] = 'Selecione o tipo do veículo.';
    }
    $tamv = mb_strlen($dados['nome_veiculo']);
    if ($tamv < 2 || $tamv > 80) {
      $erros['nome_veiculo'] = 'Informe o nome do veículo.';
    }
    // Aceita ABC1234 (antiga) e ABC1D23 (Mercosul), com ou sem hífen
    if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $dados['placa'])) {
      $erros['placa'] = 'Placa inválida. Ex.: ABC1D23 ou ABC-1234.';
    }

    // ----- Grava no banco (usuário + veículo numa transação) -----
    if (empty($erros)) {
      try {
        $stmt = db()->prepare(
          'CALL sp_cadastrar_usuario(:nome, :email, :cpf, :telefone, :senha_hash, :tipo, :veiculo, :placa, @novo_id)'
        );
        $stmt->execute([
          ':nome' => $dados['nome'],
          ':email' => $dados['email'],
          ':cpf' => $dados['cpf'],
          ':telefone' => $dados['telefone'],
          ':senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
          ':tipo' => $dados['modelo'],
          ':veiculo' => $dados['nome_veiculo'],
          ':placa' => $dados['placa'],
        ]);
        $stmt->closeCursor();

        $_SESSION['flash_ok'] = 'Cadastro realizado com sucesso! Faça login para continuar.';
        redirecionar('login.php');   // evita reenvio do formulário ao atualizar a página
      } catch (PDOException $ex) {
        $codigo = isset($ex->errorInfo[1]) ? (int) $ex->errorInfo[1] : 0;
        if ($codigo === 1062) {      // valor duplicado (UNIQUE)
          $chave = preg_match("/for key '([^']+)'/", $ex->getMessage(), $m) ? $m[1] : '';
          if (strpos($chave, 'email') !== false) {
            $erros['email'] = 'Este e-mail já está cadastrado.';
          } elseif (strpos($chave, 'cpf') !== false) {
            $erros['cpf'] = 'Este CPF já está cadastrado.';
          } elseif (strpos($chave, 'placa') !== false) {
            $erros['placa'] = 'Esta placa já está cadastrada.';
          } else {
            $erros['geral'] = 'Já existe um cadastro com esses dados.';
          }
        } else {
          error_log('SmartParking - erro no cadastro: ' . $ex->getMessage());
          $erros['geral'] = 'Não foi possível concluir o cadastro. Tente novamente em instantes.';
        }
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
  <title>Cadastro | SmartParking</title>
  <!-- CSS embutido: não depende de caminho nem de cache -->
  <style>
    /* =========================================================
   SmartParking, página de cadastro
   Mesmas classes do HTML atual; só o CSS foi melhorado.
   ========================================================= */

    :root {
      --bg-card: #0c2038;
      --bg-campo: #13314b;
      --bg-campo-hover: #163a58;
      --texto: #ebf4fb;
      --texto-suave: #8fb0c9;
      /* antes #62829c: contraste baixo sobre o campo */
      --turquesa: #02dbb5;
      --turquesa-hover: #15e1b9;
      --borda: rgba(255, 255, 255, .12);
      --erro: #ff8a8a;
      --raio: 10px;
      --fonte: 'Segoe UI', system-ui, -apple-system, Tahoma, Verdana, sans-serif;
      color-scheme: dark;
    }

    /* ---------- Reset ---------- */
    *,
    *::before,
    *::after {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    /* A fonte vai no body e é herdada; o "* { font-family }" antigo quebrava ícones de fonte */
   body {
  font-family: var(--fonte);
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.5rem;
  padding-bottom: 2rem;
  color: var(--texto);
}

.fundo {
  position: fixed;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  z-index: -1;
}

/* película escura por cima da imagem (opcional) */
body::before {
  content: "";
  position: fixed;
  inset: 0;
  background: rgba(12, 32, 56, .38);
  z-index: -1;
}

    .nav {
      width: 100%;
    }

    /* ---------- Card ---------- */
    .card {
      position: relative;
      width: min(100% - 2rem, 450px);
      margin: auto;
      /* centraliza no espaço que sobra abaixo da nav */
      padding: 2.25rem 2rem;
      display: flex;
      flex-direction: column;
      background: var(--bg-card);
      border: 1px solid var(--borda);
      border-radius: 16px;
      box-shadow: 0 1px 0 rgba(255, 255, 255, .06) inset,
        0 18px 40px -12px rgba(8, 28, 48, .45);
      overflow: hidden;
    }

    /* Filete turquesa no topo do card */
    .card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 3px;
      background: linear-gradient(90deg, var(--turquesa), #3a8dde);
    }

    /* ---------- Cabeçalho ---------- */
    .header-cadastro {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 1.5rem;
    }

    .icon-main {
      flex: none;
      width: 46px;
      height: 46px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--turquesa);
      color: var(--bg-card);
      box-shadow: 0 0 0 5px rgba(2, 219, 181, .15);
    }

    .titulolog {
      font-size: 1.6rem;
      font-weight: 650;
      line-height: 1.2;
      letter-spacing: -.01em;
      color: var(--texto);
    }

    /* opcional: <p class="subtitulo"> abaixo do título */
    .subtitulo {
      margin-top: 2px;
      font-size: .9rem;
      color: var(--texto-suave);
    }

    /* ---------- Formulário ---------- */
    .formulario {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .form-row {
      display: flex;
      gap: 12px;
      width: 100%;
    }

    .form-row .input-group {
      flex: 1;
      min-width: 0;
      /* evita que um campo estoure o flex */
    }

    /* Campo */
    .input-group {
      display: flex;
      align-items: center;
      height: 50px;
      padding: 0 14px;
      background: var(--bg-campo);
      border: 1px solid var(--borda);
      border-radius: var(--raio);
      transition: border-color .2s, box-shadow .2s, background-color .2s;
    }

    .input-group:hover {
      background: var(--bg-campo-hover);
    }

    .input-group:focus-within {
      border-color: var(--turquesa);
      box-shadow: 0 0 0 3px rgba(2, 219, 181, .22);
    }

    /* Estado inválido (só depois que a pessoa digitou algo) */
    .input-group:has(input:invalid:not(:placeholder-shown):not(:focus)) {
      border-color: var(--erro);
    }

    .input-icon {
      display: flex;
      align-items: center;
      margin-right: 10px;
      color: var(--texto-suave);
      transition: color .2s;
    }

    .input-group:focus-within .input-icon {
      color: var(--turquesa);
    }

    .formulario label {
      margin-right: 8px;
      font-size: .95rem;
      font-weight: 500;
      color: var(--texto-suave);
      white-space: nowrap;
    }

    /* Inputs nativos */
    .formulario input {
      flex: 1;
      width: 100%;
      height: 100%;
      padding: 0;
      background: transparent;
      border: none;
      outline: none;
      color: var(--texto);
      font: inherit;
      font-size: 1rem;
      /* 16px: evita o zoom automático no iPhone */
    }

    /* Select (ex.: Modelo): sem o visual nativo, com seta própria */
    select {
      flex: 1;
      width: 100%;
      height: 100%;
      padding: 0 28px 0 0;
      appearance: none !important;
      -webkit-appearance: none !important;
      background: transparent url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2302dbb5' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 2px center / 16px !important;
      border: 0;
      outline: 0;
      color: var(--texto);
      font: inherit;
      font-size: 1rem;
      /* 16px: evita o zoom automático no iPhone */
      cursor: pointer;
    }

    /* "Selecione um modelo" fica apagado até a pessoa escolher */
    select:required:invalid {
      color: #6f93ad;
    }

    /* Lista que abre ao clicar */
    select option {
      background: var(--bg-campo);
      color: var(--texto);
    }

    select option[disabled] {
      color: #6f93ad;
    }

    .formulario input::placeholder {
      color: #6f93ad;
      opacity: 1;
    }

    /* Preenchimento automático (Chrome, Edge e Safari) sem o fundo branco */
    .formulario input:-webkit-autofill,
    .formulario input:-webkit-autofill:hover,
    .formulario input:-webkit-autofill:focus {
      -webkit-text-fill-color: var(--texto) !important;
      -webkit-box-shadow: 0 0 0 1000px var(--bg-campo) inset !important;
      caret-color: var(--texto);
      transition: background-color 5000s ease-in-out 0s;
    }

    /* Mensagem de erro opcional: <small class="erro">texto</small> */
    .erro {
      margin-top: -4px;
      font-size: .8rem;
      color: var(--erro);
    }

    /* ---------- Subseção Veículo ---------- */
    .subsecao-titulo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 1rem 0 .25rem;
      padding-top: 1rem;
      border-top: 1px solid var(--borda);
      /* separa dados pessoais e veículo */
    }

    .car-icon {
      color: var(--turquesa);
      font-size: 1.2rem;
    }

    .subsecao-titulo h3 {
      font-size: 1.15rem;
      font-weight: 600;
      color: var(--texto);
    }

    /* ---------- Link de login ---------- */
    .login-link {
      margin: 4px 0;
      font-size: .9rem;
      color: var(--texto-suave);
    }

    .login-link a {
      margin-left: 6px;
      color: var(--turquesa);
      font-weight: 600;
      text-decoration: none;
    }

    .login-link a:hover {
      text-decoration: underline;
      text-underline-offset: 3px;
    }

    /* ---------- Botão ---------- */
    .submit {
      width: 100%;
      margin-top: 8px;
      padding: 14px;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
      background: var(--turquesa);
      color: var(--bg-card);
      border: none;
      border-radius: var(--raio);
      font: inherit;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background-color .2s, transform .15s, box-shadow .2s;
    }

    .submit:hover {
      background: var(--turquesa-hover);
      box-shadow: 0 8px 20px -8px rgba(2, 219, 181, .6);
      transform: translateY(-1px);
    }

    .submit:active {
      transform: scale(.99);
    }

    .submit:disabled {
      background: #2c4a5f;
      color: #7f9db3;
      cursor: not-allowed;
      box-shadow: none;
      transform: none;
    }

    /* ---------- Foco visível (teclado) ---------- */
    .submit:focus-visible,
    .login-link a:focus-visible {
      outline: 3px solid #fff;
      outline-offset: 3px;
      border-radius: 6px;
    }

    /* ---------- Responsivo ---------- */
    @media (max-width: 480px) {
      .card {
        padding: 1.75rem 1.25rem;
      }

      .form-row {
        flex-direction: column;
      }

      /* campos lado a lado viram coluna */
      .titulolog {
        font-size: 1.4rem;
      }
    }

    /* ---------- Menos movimento ---------- */
    @media (prefers-reduced-motion: reduce) {

      *,
      *::before,
      *::after {
        transition: none !important;
      }
    }

    /* ---------- Aviso de erro geral ---------- */
    .alerta {
      padding: 10px 14px;
      border-radius: var(--raio);
      font-size: .9rem;
    }

    .alerta-erro {
      background: rgba(255, 138, 138, .12);
      border: 1px solid var(--erro);
      color: var(--erro);
    }
  </style>
</head>

<body>
  <?php require_once 'nav.php'; ?>

  <!-- CARD CENTRALIZADO -->
  <div class="card">

    <!-- CABEÇALHO -->
    <div class="header-cadastro">
      <div class="icon-main">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>
      </div>
      <h2 class="titulolog">Cadastro</h2>
    </div>

    <!-- FORMULÁRIO (action vazio = envia para esta mesma página) -->
    <form class="formulario" action="" method="post">

      <?php if (!empty($erros['geral'])): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erros['geral']) ?></div>
      <?php endif; ?>

      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="input-group">
        <label for="nome">Nome:</label>
        <input type="text" id="nome" name="nome" autocomplete="name" maxlength="120" value="<?= e($dados['nome']) ?>"
          required>
      </div>
      <?php campo_erro($erros, 'nome'); ?>

      <div class="input-group">
        <label for="email">Email:</label>
        <input type="email" id="email" name="email" autocomplete="email" maxlength="160"
          value="<?= e($dados['email']) ?>" required>
      </div>
      <?php campo_erro($erros, 'email'); ?>

      <div class="input-group">
        <label for="cpf">CPF:</label>
        <input type="text" id="cpf" name="cpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00"
          value="<?= e($dados['cpf']) ?>" required>
      </div>
      <?php campo_erro($erros, 'cpf'); ?>

      <!-- Senha e Telefone lado a lado -->
      <div class="form-row">
        <div class="input-group">
          <label for="senha">Senha:</label>
          <input type="password" id="senha" name="senha" autocomplete="new-password" minlength="8" maxlength="72"
            required>
        </div>

        <div class="input-group">
          <label for="telefone">Telefone:</label>
          <input type="tel" id="telefone" name="telefone" autocomplete="tel" maxlength="15"
            placeholder="(11) 90000-0000" value="<?= e($dados['telefone']) ?>" required>
        </div>
      </div>
      <?php campo_erro($erros, 'senha'); ?>
      <?php campo_erro($erros, 'telefone'); ?>

      <div class="input-group">
        <label for="senha2">Confirmar senha:</label>
        <input type="password" id="senha2" name="senha2" autocomplete="new-password" minlength="8" maxlength="72"
          required>
      </div>
      <?php campo_erro($erros, 'senha2'); ?>

      <!-- SUBSEÇÃO: Veículo -->
      <div class="subsecao-titulo">
        <h3>Veículo</h3>
      </div>

      <div class="input-group">
        <label for="modelo">Modelo:</label>
        <select id="modelo" name="modelo" required
          style="-webkit-appearance:none;appearance:none;background-color:transparent;border:0">
          <option value="" disabled <?= $dados['modelo'] === '' ? 'selected' : '' ?>>Selecione um modelo</option>
          <option value="eletrico" <?= $dados['modelo'] === 'eletrico' ? 'selected' : '' ?>>Elétrico</option>
          <option value="combustao" <?= $dados['modelo'] === 'combustao' ? 'selected' : '' ?>>Combustão</option>
          <option value="hibrido" <?= $dados['modelo'] === 'hibrido' ? 'selected' : '' ?>>Híbrido</option>
        </select>
      </div>
      <?php campo_erro($erros, 'modelo'); ?>

      <div class="input-group">
        <label for="nome-veiculo">Nome do veículo:</label>
        <input type="text" id="nome-veiculo" name="nome_veiculo" maxlength="80" value="<?= e($dados['nome_veiculo']) ?>"
          required>
      </div>
      <?php campo_erro($erros, 'nome_veiculo'); ?>

      <div class="input-group">
        <label for="placa">Placa:</label>
        <input type="text" id="placa" name="placa" maxlength="8" placeholder="ABC1D23" autocomplete="off"
          style="text-transform:uppercase" value="<?= e($dados['placa']) ?>" required>
      </div>
      <?php campo_erro($erros, 'placa'); ?>

      <div class="login-link">
        Já possui cadastro? <a href="login.php">Faça login</a>
      </div>

      <button type="submit" class="submit">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <line x1="22" y1="2" x2="11" y2="13"></line>
          <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
        </svg>
        Cadastrar
      </button>

    </form>
  </div>
</body>

</html>