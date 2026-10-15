<?php
// Configura o envio de e-mail (SMTP). Roda uma vez, pelo navegador. Exige a senha do banco como autorização.
// Depois que o SMTP está gravado em api/config.php, esta página deixa de responder (404).
declare(strict_types=1);
require __DIR__ . '/smtp.php';

$arquivo = __DIR__ . '/config.php';
if (!is_file($arquivo)) { http_response_code(404); exit; }
$cfg = require $arquivo;
if (isset($cfg['smtp'])) { http_response_code(404); exit; }

const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_USER = 'comunidade@amazole.me';
const RESPONDER_PARA = 'sac@amazole.me';
const TESTE_PARA = 'vagner.pointview@gmail.com';

$erro = '';
$ok = false;
$teste = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $banco = $_POST['senha_banco'] ?? '';
    $mail = $_POST['senha_email'] ?? '';
    if (!is_string($banco) || !is_string($mail) || $banco === '' || $mail === '' || strlen($mail) > 200) {
        $erro = 'Preencha as duas senhas.';
    } elseif (!hash_equals((string) $cfg['db']['pass'], $banco)) {
        sleep(2);
        $erro = 'A senha do banco não confere.';
    } else {
        $smtp = ['host' => SMTP_HOST, 'port' => SMTP_PORT, 'user' => SMTP_USER, 'pass' => $mail, 'seguro' => true];
        if (!smtp_testar($smtp)) {
            sleep(2);
            $erro = 'Não foi possível entrar na caixa de e-mail. Confira a senha do e-mail.';
        } else {
            $cfg['smtp'] = $smtp;
            $cfg['responder_para'] = RESPONDER_PARA;
            $conteudo = "<?php\n// Gerado pelo instalador. Não envie este arquivo para o Git.\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents($arquivo, $conteudo, LOCK_EX) === false) {
                $erro = 'Não foi possível gravar a configuração.';
            } else {
                @chmod($arquivo, 0600);
                $ok = true;
                $teste = smtp_enviar($smtp, TESTE_PARA, 'Teste de e-mail - Amazolé',
                    "Este é um e-mail de teste. Se você recebeu, o envio de links de acesso está funcionando.\r\n", RESPONDER_PARA);
            }
        }
    }
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Configurar e-mail</title>
<style>body{font-family:system-ui,sans-serif;background:#0a0118;color:#f4effc;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}
.box{background:#1a0a35;border:1px solid #2e1a55;border-radius:20px;padding:30px;width:min(440px,100%)}
h1{font-size:1.3rem;margin:0 0 8px}p{color:#b4a6d1;font-size:.95rem}label{display:block;font-size:.85rem;color:#b4a6d1;margin-top:12px}
input{width:100%;box-sizing:border-box;padding:13px;border-radius:12px;border:1px solid #2e1a55;background:#0a0118;color:#f4effc;font:inherit;margin:6px 0 4px}
button,a.b{display:inline-block;border:0;margin-top:14px;padding:12px 22px;border-radius:999px;background:#7c3aed;color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.e{color:#f87171}.s{color:#4ade80}</style></head><body><div class="box">
<?php if ($ok): ?>
<h1 class="s">E-mail configurado</h1>
<?php if ($teste): ?>
<p>Enviamos um e-mail de teste para <strong><?= htmlspecialchars(TESTE_PARA, ENT_QUOTES) ?></strong>. Confira a caixa de entrada (e o spam).</p>
<?php else: ?>
<p class="e">A senha está correta e foi gravada, mas o e-mail de teste não saiu. Avise o suporte.</p>
<?php endif; ?>
<p><a class="b" href="/">Abrir o site</a></p>
<?php else: ?>
<h1>Configurar o envio de e-mail</h1>
<p>Caixa usada: <strong><?= htmlspecialchars(SMTP_USER, ENT_QUOTES) ?></strong>. As senhas são gravadas só no servidor.</p>
<form method="post" autocomplete="off">
<label for="sb">Senha do banco de dados (autorização)</label>
<input id="sb" type="password" name="senha_banco" required autofocus>
<label for="se">Senha da caixa de e-mail</label>
<input id="se" type="password" name="senha_email" required>
<?php if ($erro): ?><p class="e"><?= htmlspecialchars($erro, ENT_QUOTES) ?></p><?php endif; ?>
<button type="submit">Salvar e testar</button>
</form>
<?php endif; ?>
</div></body></html>
