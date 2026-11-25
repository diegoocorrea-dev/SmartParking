<?php
require_once 'init.php';

// Quem já está logado não precisa ver o login
if (usuario_logado()) {
    $pendente = (string) ($_SESSION['redirect_after_login'] ?? '');
    unset($_SESSION['redirect_after_login']);
    redirecionar(destino_local_seguro($pendente) ? $pendente : 'index.php');
}

$mensagem_erro = '';
$email = '';

// Mensagem vinda do cadastro (aparece uma única vez)
$mensagem_ok = $_SESSION['flash_ok'] ?? '';
unset($_SESSION['flash_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim(post_str('email')));
    $senha = post_str('senha');            // senha NUNCA leva trim()

    if (!csrf_ok(post_str('csrf'))) {
        $mensagem_erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif (($_SESSION['login_bloqueado_ate'] ?? 0) > time()) {
        $mensagem_erro = 'Muitas tentativas. Aguarde ' . ($_SESSION['login_bloqueado_ate'] - time()) . ' segundos.';
    } elseif ($email === '' || $senha === '') {
        $mensagem_erro = 'Por favor, preencha todos os campos.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, nome, email, senha_hash, perfil, ativo FROM usuarios WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $u = $stmt->fetch();

        // Verifica sempre um hash, mesmo sem usuário, para não revelar se o e-mail existe
        $hash = $u ? $u['senha_hash'] : password_hash('senha-falsa', PASSWORD_DEFAULT);
        $confere = password_verify($senha, $hash);

        if ($u && $confere) {
            if (!$u['ativo']) {
                $mensagem_erro = 'Conta desativada. Entre em contato com o suporte.';
            } else {
                session_regenerate_id(true);   // evita fixação de sessão
                unset($_SESSION['login_falhas'], $_SESSION['login_bloqueado_ate']);

                $_SESSION['usuario_id'] = (int) $u['id'];
                $_SESSION['usuario_nome'] = $u['nome'];
                $_SESSION['usuario_email'] = $u['email'];
                $_SESSION['usuario_perfil'] = $u['perfil'];
                $_SESSION['usuario'] = $u['email'];   // compatibilidade com o código antigo

                // Atualiza o hash se o algoritmo padrão do PHP mudou
                if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE usuarios SET senha_hash = :h WHERE id = :id')
                        ->execute([':h' => password_hash($senha, PASSWORD_DEFAULT), ':id' => $u['id']]);
                }
                db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
                    ->execute([':id' => $u['id']]);

                $next = isset($_POST['next']) && is_string($_POST['next']) ? $_POST['next'] : '';
                if ($next === '')
                    $next = (string) ($_SESSION['redirect_after_login'] ?? ($_GET['next'] ?? ''));
                unset($_SESSION['redirect_after_login']);
                $destino = destino_local_seguro($next) ? $next :
                    (in_array($u['perfil'], ['admin', 'operador'], true) ? 'adm.php' : 'index.php');
                redirecionar($destino);
            }
        } else {
            $mensagem_erro = 'E-mail ou senha incorretos!';
            $_SESSION['login_falhas'] = ($_SESSION['login_falhas'] ?? 0) + 1;
            if ($_SESSION['login_falhas'] >= 5) {          // 5 erros = 60 s de espera
                $_SESSION['login_bloqueado_ate'] = time() + 60;
                $_SESSION['login_falhas'] = 0;
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
    <title>Login</title>
    <link rel="stylesheet" href="./css/login.css">
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <main class="login-container">
        <div class="card">
            <span>
                <img src="./img/login_semFundo.png" alt="Logo">
                <h1 class="titulolog">Login</h1>
            </span>

            <?php if ($mensagem_ok !== ''): ?>
                <p style="color: #0a9b7e; text-align: center; margin-bottom: 15px;" role="status">
                    <?= e($mensagem_ok) ?>
                </p>
            <?php endif; ?>

            <?php if ($mensagem_erro !== ''): ?>
                <p style="color: red; text-align: center; margin-bottom: 15px;" role="alert">
                    <?= e($mensagem_erro) ?>
                </p>
            <?php endif; ?>

            <form action="" method="post" class="formulario">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="next" value="<?= e($next ?? ($_GET['next'] ?? '')) ?>">

                <label for="email">Seu e-mail:</label>
                <input name="email" id="email" type="email" placeholder="seu@email.com" value="<?= e($email) ?>"
                    autocomplete="username" required>

                <label for="senha">Sua senha:</label>
                <input type="password" id="senha" name="senha" placeholder="••••••••" autocomplete="current-password"
                    required>

                <button type="submit" class="submit">Enviar</button>

                <div class="cadastro-link">
                    Não possui cadastro? <a href="cadastro.php">Cadastre-se</a>
                </div>
            </form>
        </div>
    </main>
</body>

</html>