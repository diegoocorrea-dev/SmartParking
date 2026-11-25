<?php
require_once __DIR__ . '/init.php';
$usuario = usuario_logado();
$erro = '';
$sucesso = '';
$nome = $usuario['nome'] ?? '';
$email = $usuario['email'] ?? '';
$assuntos = ['duvida' => 'Dúvida', 'recarga_ev' => 'Recarga de veículo elétrico', 'pagamento' => 'Pagamento', 'outro' => 'Outro'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim(post_str('nome'));
    $email = trim(post_str('email'));
    $assunto = post_str('assunto');
    $mensagem = trim(post_str('mensagem'));
    if (!csrf_ok(post_str('csrf')))
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    elseif ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($assuntos[$assunto]) || $mensagem === '')
        $erro = 'Preencha todos os campos corretamente.';
    else {
        try {
            $st = db()->prepare("INSERT INTO chamados_suporte (usuario_id,nome,email,assunto,mensagem,status,criado_em) VALUES (:uid,:nome,:email,:assunto,:mensagem,'aberto',NOW())");
            $st->execute([':uid' => $usuario['id'] ?? null, ':nome' => $nome, ':email' => $email, ':assunto' => $assunto, ':mensagem' => $mensagem]);
            $sucesso = 'Mensagem enviada com sucesso! Nossa equipe entrará em contato.';
            if (!$usuario) {
                $nome = '';
                $email = '';
            }
            $mensagem = '';
        } catch (PDOException $ex) {
            error_log('SmartParking suporte: ' . $ex->getMessage());
            $erro = 'Não foi possível enviar sua mensagem agora. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Central de Ajuda | SmartParking</title>
    <link rel="stylesheet" href="./css/suporte.css">
</head>

<body>
    <?php require __DIR__ . '/nav.php'; ?>
    <main class="suporte-page">
        <h1 class="titulo">Central de Ajuda</h1>
        <h3 class="sub-titulo">Tire suas dúvidas ou solicite suporte para recargas EV</h3>
        <section class="Card">
            <div class="card-header"><img src="./img/fone_fundoVerde.jpg" alt="Suporte">
                <h3>Central de Ajuda</h3>
            </div>
            <?php if ($erro): ?>
                <p class="suporte-msg erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
            <?php if ($sucesso): ?>
                <p class="suporte-msg sucesso" role="status"><?= e($sucesso) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label for="nome">Nome completo</label>
                <input id="nome" name="nome" type="text" maxlength="120" value="<?= e($nome) ?>" required>
                <label for="email">Endereço de e-mail</label>
                <input id="email" name="email" type="email" maxlength="160" value="<?= e($email) ?>" required>
                <label for="assunto">Assunto</label>
                <select id="assunto" name="assunto" required>
                    <option value="">Selecione</option>
                    <?php foreach ($assuntos as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>"><?= e($rotulo) ?></option><?php endforeach; ?>
                </select>
                <label for="mensagem">Mensagem</label>
                <textarea id="mensagem" name="mensagem" rows="4" maxlength="5000"
                    required><?= e($mensagem ?? '') ?></textarea>
                <button type="submit">Enviar Mensagem</button>
            </form>
        </section>
    </main>
</body>

</html>