<?php
// API da Comunidade Amazolé (PHP + MySQL). Credenciais ficam em api/config.php (fora do Git).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    echo json_encode(['erro' => 'nao_instalado']);
    exit;
}
$CFG = require __DIR__ . '/config.php';

function resp(int $code, array $data = []): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $d = $CFG['db'];
        $pdo = new PDO(
            "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
            $d['user'], $d['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function entrada(): array {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m !== 'POST') return [];
    // Protege contra CSRF: exige cabeçalho personalizado (navegadores bloqueiam isso entre sites).
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') resp(400, ['erro' => 'requisicao_invalida']);
    $raw = file_get_contents('php://input', false, null, 0, 20001);
    if ($raw === false || strlen($raw) > 20000) resp(413, ['erro' => 'muito_grande']);
    $j = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($j)) resp(400, ['erro' => 'json_invalido']);
    return $j;
}

function exigirPost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') resp(405, ['erro' => 'use_post']);
}

function email_ok($v): ?string {
    if (!is_string($v)) return null;
    $v = strtolower(trim($v));
    if ($v === '' || strlen($v) > 254) return null;
    if (preg_match('/[\r\n]/', $v)) return null;
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
}

function texto($v, int $max): ?string {
    if ($v === null) return null;
    if (!is_string($v)) resp(400, ['erro' => 'campo_invalido']);
    $v = trim($v);
    if ($v === '') return null;
    if (mb_strlen($v) > $max) resp(400, ['erro' => 'campo_longo']);
    return $v;
}

function url_https($v): ?string {
    $v = texto($v, 500);
    if ($v === null) return null;
    if (!preg_match('#^https://[^\s]+$#i', $v)) resp(400, ['erro' => 'url_invalida']);
    return $v;
}

function limitar(string $chave, int $max, int $janelaSeg): void {
    global $CFG;
    $h = hash_hmac('sha256', $chave, $CFG['chave']);
    $n = (int) q('SELECT COUNT(*) c FROM limites WHERE chave = ? AND criado_em > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)', [$h, $janelaSeg])->fetch()['c'];
    if ($n >= $max) resp(429, ['erro' => 'muitas_tentativas']);
    q('INSERT INTO limites (chave) VALUES (?)', [$h]);
    if (random_int(1, 50) === 1) q('DELETE FROM limites WHERE criado_em < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
}

function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0'; }

function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function iso(?string $d): ?string {
    return $d ? str_replace(' ', 'T', $d) . 'Z' : null;
}

function adminAtual(): ?string {
    $t = $_COOKIE['adm'] ?? '';
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    $r = q('SELECT s.email FROM admin_sessoes s JOIN admins a ON a.email = s.email WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP()', [hash('sha256', $t)])->fetch();
    return $r ? $r['email'] : null;
}

function exigirAdmin(): string {
    $e = adminAtual();
    if ($e === null) resp(401, ['erro' => 'nao_autorizado']);
    return $e;
}

function enviarLink(string $para, string $link): bool {
    global $CFG;
    $assunto = 'Seu link de acesso - Amazolé';
    $corpo = "Olá!\r\n\r\nClique no link abaixo para entrar no painel. Ele vale por 15 minutos e só funciona uma vez:\r\n\r\n$link\r\n\r\nSe não foi você, ignore este e-mail.\r\n";

    // Preferência: SMTP com a caixa de e-mail do domínio (chega na caixa de entrada)
    if (isset($CFG['smtp']) && is_array($CFG['smtp'])) {
        require_once __DIR__ . '/smtp.php';
        if (smtp_enviar($CFG['smtp'], $para, $assunto, $corpo, $CFG['responder_para'] ?? null)) return true;
        error_log('[comunidade-api] SMTP falhou, tentando mail()');
    }

    $de = $CFG['remetente'];
    $h = "From: Amazolé <$de>\r\nReply-To: $de\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    return @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $h, '-f' . $de);
}

function curso_linha(array $c): array {
    return [
        'id' => $c['id'], 'titulo' => $c['titulo'], 'descricao' => $c['descricao'],
        'capa_url' => $c['capa_url'], 'categoria' => $c['categoria'],
        'destaque' => (bool) $c['destaque'], 'ordem' => (int) $c['ordem'],
        'publicado' => (bool) $c['publicado'], 'aulas' => [],
    ];
}

function aula_linha(array $a): array {
    return [
        'id' => $a['id'], 'curso_id' => $a['curso_id'], 'titulo' => $a['titulo'],
        'descricao' => $a['descricao'], 'video_url' => $a['video_url'],
        'material_url' => $a['material_url'], 'duracao' => $a['duracao'],
        'ordem' => (int) $a['ordem'], 'publicado' => (bool) $a['publicado'],
    ];
}

function catalogo(bool $todos): array {
    $cs = q('SELECT * FROM cursos' . ($todos ? '' : ' WHERE publicado = 1') . ' ORDER BY ordem, created_at')->fetchAll();
    $as = q('SELECT * FROM aulas' . ($todos ? '' : ' WHERE publicado = 1') . ' ORDER BY ordem, created_at')->fetchAll();
    $out = [];
    foreach ($cs as $c) $out[$c['id']] = curso_linha($c);
    foreach ($as as $a) if (isset($out[$a['curso_id']])) $out[$a['curso_id']]['aulas'][] = aula_linha($a);
    return array_values($out);
}

function status_ok($v): string {
    if (!in_array($v, ['pendente', 'aprovado', 'bloqueado'], true)) resp(400, ['erro' => 'status_invalido']);
    return $v;
}

function id_ok($v): string {
    if (!is_string($v) || !preg_match('/^[a-f0-9-]{36}$/', $v)) resp(400, ['erro' => 'id_invalido']);
    return $v;
}

try {
    $acao = $_GET['a'] ?? '';
    if (!is_string($acao)) resp(400, ['erro' => 'acao_invalida']);

    switch ($acao) {

        // ===== Afiliado: entrada só com e-mail =====
        case 'entrar': {
            exigirPost();
            $j = entrada();
            limitar('entrar:' . ip(), 40, 600);
            $email = email_ok($j['email'] ?? null);
            if ($email === null) resp(400, ['erro' => 'email_invalido']);

            if (q('SELECT 1 FROM admins WHERE email = ?', [$email])->fetch()) {
                limitar('link-ip:' . ip(), 10, 3600);
                limitar('link-email:' . $email, 3, 1800);
                $token = bin2hex(random_bytes(32));
                q('INSERT INTO admin_links (token_hash, email, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))', [hash('sha256', $token), $email]);
                $link = rtrim($CFG['site'], '/') . '/?entrar=' . $token;
                if (!enviarLink($email, $link)) resp(500, ['erro' => 'envio_falhou']);
                resp(200, ['admin' => true, 'link_enviado' => true]);
            }

            $r = q('SELECT status FROM afiliados WHERE email = ?', [$email])->fetch();
            if ($r && $r['status'] === 'bloqueado') resp(403, ['erro' => 'bloqueado']);
            q('INSERT INTO afiliados (email) VALUES (?) ON DUPLICATE KEY UPDATE last_login_at = UTC_TIMESTAMP()', [$email]);
            $s = q('SELECT status FROM afiliados WHERE email = ?', [$email])->fetch();
            resp(200, ['ok' => true, 'status' => $s['status']]);
        }

        // ===== Admin: confirmar link do e-mail =====
        case 'admin_verificar': {
            exigirPost();
            $j = entrada();
            limitar('verif:' . ip(), 20, 600);
            $t = $j['token'] ?? '';
            if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) resp(400, ['erro' => 'token_invalido']);
            $h = hash('sha256', $t);
            $pdo = db();
            $pdo->beginTransaction();
            $l = q('SELECT email FROM admin_links WHERE token_hash = ? AND used = 0 AND expires_at > UTC_TIMESTAMP() FOR UPDATE', [$h])->fetch();
            if (!$l) { $pdo->rollBack(); resp(400, ['erro' => 'link_expirado']); }
            q('UPDATE admin_links SET used = 1 WHERE token_hash = ?', [$h]);
            if (!q('SELECT 1 FROM admins WHERE email = ?', [$l['email']])->fetch()) { $pdo->rollBack(); resp(403, ['erro' => 'nao_autorizado']); }
            $sessao = bin2hex(random_bytes(32));
            q('INSERT INTO admin_sessoes (token_hash, email, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))', [hash('sha256', $sessao), $l['email']]);
            $pdo->commit();
            setcookie('adm', $sessao, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            resp(200, ['ok' => true, 'email' => $l['email']]);
        }

        case 'admin_eu': {
            $e = adminAtual();
            if ($e === null) resp(401, ['admin' => false]);
            resp(200, ['admin' => true, 'email' => $e]);
        }

        case 'admin_sair': {
            exigirPost();
            entrada();
            $t = $_COOKIE['adm'] ?? '';
            if (is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t)) q('DELETE FROM admin_sessoes WHERE token_hash = ?', [hash('sha256', $t)]);
            setcookie('adm', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            resp(200, ['ok' => true]);
        }

        // ===== Admin: afiliados =====
        case 'afiliados_listar': {
            exigirAdmin();
            $rows = q('SELECT email, nome, whatsapp, status, observacao, created_at, last_login_at FROM afiliados ORDER BY created_at DESC LIMIT 5000')->fetchAll();
            foreach ($rows as &$r) { $r['created_at'] = iso($r['created_at']); $r['last_login_at'] = iso($r['last_login_at']); }
            resp(200, ['afiliados' => $rows]);
        }

        case 'afiliado_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $email = email_ok($j['email'] ?? null);
            if ($email === null) resp(400, ['erro' => 'email_invalido']);
            $nome = texto($j['nome'] ?? null, 120);
            $whats = texto($j['whatsapp'] ?? null, 30);
            $obs = texto($j['observacao'] ?? null, 500);
            $status = status_ok($j['status'] ?? 'pendente');
            $orig = isset($j['original']) && $j['original'] !== '' ? email_ok($j['original']) : null;
            if (isset($j['original']) && $j['original'] !== '' && $orig === null) resp(400, ['erro' => 'email_invalido']);
            try {
                if ($orig !== null) {
                    $st = q('UPDATE afiliados SET email = ?, nome = ?, whatsapp = ?, status = ?, observacao = ? WHERE email = ?', [$email, $nome, $whats, $status, $obs, $orig]);
                    if ($st->rowCount() === 0 && !q('SELECT 1 FROM afiliados WHERE email = ?', [$email])->fetch()) resp(404, ['erro' => 'nao_encontrado']);
                } else {
                    q('INSERT INTO afiliados (email, nome, whatsapp, status, observacao) VALUES (?, ?, ?, ?, ?)', [$email, $nome, $whats, $status, $obs]);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') resp(409, ['erro' => 'ja_cadastrado']);
                throw $e;
            }
            resp(200, ['ok' => true]);
        }

        case 'afiliado_status': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $email = email_ok($j['email'] ?? null);
            if ($email === null) resp(400, ['erro' => 'email_invalido']);
            q('UPDATE afiliados SET status = ? WHERE email = ?', [status_ok($j['status'] ?? null), $email]);
            resp(200, ['ok' => true]);
        }

        case 'afiliado_remover': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $email = email_ok($j['email'] ?? null);
            if ($email === null) resp(400, ['erro' => 'email_invalido']);
            q('DELETE FROM afiliados WHERE email = ?', [$email]);
            resp(200, ['ok' => true]);
        }

        // ===== Cursos =====
        case 'cursos_ler': {
            exigirPost();
            $j = entrada();
            if (adminAtual() === null) {
                limitar('cursos:' . ip(), 120, 600);
                $email = email_ok($j['email'] ?? null);
                $ok = $email !== null && q("SELECT 1 FROM afiliados WHERE email = ? AND status = 'aprovado'", [$email])->fetch();
                if (!$ok) resp(403, ['erro' => 'sem_acesso']);
            }
            resp(200, ['cursos' => catalogo(false)]);
        }

        case 'cursos_admin': {
            exigirAdmin();
            resp(200, ['cursos' => catalogo(true)]);
        }

        case 'curso_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $titulo = texto($j['titulo'] ?? null, 200);
            if ($titulo === null) resp(400, ['erro' => 'titulo_obrigatorio']);
            $d = [
                $titulo, texto($j['descricao'] ?? null, 2000), url_https($j['capa_url'] ?? null),
                texto($j['categoria'] ?? null, 80) ?? 'Cursos',
                !empty($j['destaque']) ? 1 : 0, (int) ($j['ordem'] ?? 0), array_key_exists('publicado', $j) && !$j['publicado'] ? 0 : 1,
            ];
            if (!empty($j['id'])) {
                $id = id_ok($j['id']);
                q('UPDATE cursos SET titulo = ?, descricao = ?, capa_url = ?, categoria = ?, destaque = ?, ordem = ?, publicado = ? WHERE id = ?', array_merge($d, [$id]));
            } else {
                $id = uuid();
                q('INSERT INTO cursos (titulo, descricao, capa_url, categoria, destaque, ordem, publicado, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($d, [$id]));
            }
            resp(200, ['ok' => true, 'id' => $id]);
        }

        case 'curso_remover': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            q('DELETE FROM cursos WHERE id = ?', [id_ok($j['id'] ?? null)]);
            resp(200, ['ok' => true]);
        }

        case 'aula_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $titulo = texto($j['titulo'] ?? null, 200);
            if ($titulo === null) resp(400, ['erro' => 'titulo_obrigatorio']);
            $curso = id_ok($j['curso_id'] ?? null);
            if (!q('SELECT 1 FROM cursos WHERE id = ?', [$curso])->fetch()) resp(404, ['erro' => 'curso_nao_encontrado']);
            $d = [
                $curso, $titulo, texto($j['descricao'] ?? null, 2000), url_https($j['video_url'] ?? null),
                url_https($j['material_url'] ?? null), texto($j['duracao'] ?? null, 20),
                (int) ($j['ordem'] ?? 0), array_key_exists('publicado', $j) && !$j['publicado'] ? 0 : 1,
            ];
            if (!empty($j['id'])) {
                $id = id_ok($j['id']);
                q('UPDATE aulas SET curso_id = ?, titulo = ?, descricao = ?, video_url = ?, material_url = ?, duracao = ?, ordem = ?, publicado = ? WHERE id = ?', array_merge($d, [$id]));
            } else {
                $id = uuid();
                q('INSERT INTO aulas (curso_id, titulo, descricao, video_url, material_url, duracao, ordem, publicado, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($d, [$id]));
            }
            resp(200, ['ok' => true, 'id' => $id]);
        }

        case 'aula_remover': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            q('DELETE FROM aulas WHERE id = ?', [id_ok($j['id'] ?? null)]);
            resp(200, ['ok' => true]);
        }

        default:
            resp(404, ['erro' => 'nao_encontrado']);
    }
} catch (Throwable $e) {
    error_log('[comunidade-api] ' . $e->getMessage());
    resp(500, ['erro' => 'erro_interno']);
}
