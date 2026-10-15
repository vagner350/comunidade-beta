<?php
// Instalador: roda uma única vez, pelo navegador. Pede a senha do banco, cria as tabelas e grava api/config.php.
// Depois que api/config.php existe, esta página deixa de responder (404).
declare(strict_types=1);

$arquivo = __DIR__ . '/config.php';
if (is_file($arquivo)) { http_response_code(404); exit; }

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'u616319980_comunidade';
const DB_USER = 'u616319980_comunidade';
const ADMIN_EMAIL = 'vagner.pointview@gmail.com';
const SITE = 'https://comunidade.amazole.me';
const REMETENTE = 'noreply@amazole.me';

$sql = [
"CREATE TABLE IF NOT EXISTS afiliados (
  email VARCHAR(254) NOT NULL PRIMARY KEY,
  nome VARCHAR(120) NULL,
  whatsapp VARCHAR(30) NULL,
  status ENUM('pendente','aprovado','bloqueado') NOT NULL DEFAULT 'pendente',
  observacao VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admins (
  email VARCHAR(254) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admin_links (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admin_sessoes (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS limites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chave CHAR(64) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chave (chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS cursos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  titulo VARCHAR(200) NOT NULL,
  descricao VARCHAR(2000) NULL,
  capa_url VARCHAR(500) NULL,
  categoria VARCHAR(80) NOT NULL DEFAULT 'Cursos',
  destaque TINYINT(1) NOT NULL DEFAULT 0,
  ordem INT NOT NULL DEFAULT 0,
  publicado TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS aulas (
  id CHAR(36) NOT NULL PRIMARY KEY,
  curso_id CHAR(36) NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  descricao VARCHAR(2000) NULL,
  video_url VARCHAR(500) NULL,
  material_url VARCHAR(500) NULL,
  duracao VARCHAR(20) NULL,
  ordem INT NOT NULL DEFAULT 0,
  publicado TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_curso_ordem (curso_id, ordem),
  CONSTRAINT fk_aula_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$erro = '';
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $senha = $_POST['senha'] ?? '';
    if (!is_string($senha) || $senha === '' || strlen($senha) > 200) {
        $erro = 'Digite a senha do banco de dados.';
    } else {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, $senha,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($sql as $s) $pdo->exec($s);
            $st = $pdo->prepare('INSERT IGNORE INTO admins (email) VALUES (?)');
            $st->execute([ADMIN_EMAIL]);
            $cfg = [
                'db' => ['host' => DB_HOST, 'port' => DB_PORT, 'name' => DB_NAME, 'user' => DB_USER, 'pass' => $senha],
                'chave' => bin2hex(random_bytes(32)),
                'remetente' => REMETENTE,
                'site' => SITE,
            ];
            $conteudo = "<?php\n// Gerado pelo instalador. Não envie este arquivo para o Git.\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents($arquivo, $conteudo, LOCK_EX) === false) throw new RuntimeException('nao_gravou');
            @chmod($arquivo, 0600);
            $ok = true;
        } catch (Throwable $e) {
            sleep(2);
            $erro = 'Não foi possível concluir. Confira a senha do banco e tente de novo.';
        }
    }
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Instalação</title>
<style>body{font-family:system-ui,sans-serif;background:#0a0118;color:#f4effc;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}
.box{background:#1a0a35;border:1px solid #2e1a55;border-radius:20px;padding:30px;width:min(440px,100%)}
h1{font-size:1.3rem;margin:0 0 8px}p{color:#b4a6d1;font-size:.95rem}
input{width:100%;box-sizing:border-box;padding:13px;border-radius:12px;border:1px solid #2e1a55;background:#0a0118;color:#f4effc;font:inherit;margin:10px 0 14px}
button,a.b{display:inline-block;border:0;padding:12px 22px;border-radius:999px;background:#7c3aed;color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.e{color:#f87171}.s{color:#4ade80}</style></head><body><div class="box">
<?php if ($ok): ?>
<h1 class="s">Instalação concluída</h1>
<p>Tabelas criadas e administrador cadastrado. Já pode usar o site.</p>
<p><a class="b" href="/">Abrir o site</a></p>
<?php else: ?>
<h1>Instalação da Comunidade</h1>
<p>Digite a senha do banco de dados <strong><?= htmlspecialchars(DB_NAME, ENT_QUOTES) ?></strong>. Ela é gravada só no servidor.</p>
<form method="post" autocomplete="off">
<input type="password" name="senha" placeholder="Senha do banco de dados" required autofocus>
<?php if ($erro): ?><p class="e"><?= htmlspecialchars($erro, ENT_QUOTES) ?></p><?php endif; ?>
<button type="submit">Instalar</button>
</form>
<?php endif; ?>
</div></body></html>
