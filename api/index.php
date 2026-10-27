<?php
// API da Comunidade Amazolé (PHP + MySQL). Credenciais ficam em api/config.php (fora do Git).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Outros endereços do mesmo site (mesmo banco): usam a configuração do site principal,
// trocando só o endereço (para os links de login e as fotos apontarem para o próprio domínio).
const CONFIG_PRINCIPAL = '/home/u616319980/domains/amazole.me/public_html/comunidade/api/config.php';
const DOMINIOS_ESPELHO = ['amazoleafiliados.com.br'];

if (is_file(__DIR__ . '/config.php')) {
    $CFG = require __DIR__ . '/config.php';
} else {
    $host = preg_replace('/^www\./', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!in_array($host, DOMINIOS_ESPELHO, true) || !is_file(CONFIG_PRINCIPAL)) {
        http_response_code(503);
        echo json_encode(['erro' => 'nao_instalado']);
        exit;
    }
    $CFG = require CONFIG_PRINCIPAL;
    $CFG['site'] = 'https://' . $host;
}

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
        try { garantirEsquema($pdo); } catch (Throwable $e) { error_log('[comunidade-api] esquema: ' . $e->getMessage()); }
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function entrada(int $max = 20000): array {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m !== 'POST') return [];
    // Protege contra CSRF: exige cabeçalho personalizado (navegadores bloqueiam isso entre sites).
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') resp(400, ['erro' => 'requisicao_invalida']);
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) resp(413, ['erro' => 'muito_grande']);
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


// ===== Tabelas novas (criadas sozinhas na primeira chamada depois da atualização) =====
function garantirEsquema(PDO $pdo): void {
    static $feito = false;
    if ($feito) return;
    $feito = true;
    $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('config','conteudo','webhook_log')")->fetchColumn();
    // coluna nova: vários links de afiliação por item (Produtos)
    try {
        $tem = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'conteudo' AND column_name = 'links'")->fetchColumn();
        $existe = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'conteudo'")->fetchColumn();
        if ($existe && !$tem) $pdo->exec('ALTER TABLE conteudo ADD COLUMN links TEXT NULL');
    } catch (Throwable $e) { /* sem permissão para alterar: segue sem a coluna */ }
    if ($n === 3) return;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS config (
            chave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor MEDIUMTEXT NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS conteudo (
            id CHAR(36) NOT NULL PRIMARY KEY,
            secao VARCHAR(40) NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            texto TEXT NULL,
            link VARCHAR(500) NULL,
            imagem_url VARCHAR(500) NULL,
            links TEXT NULL,
            ordem INT NOT NULL DEFAULT 0,
            publicado TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_secao (secao, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS webhook_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email VARCHAR(254) NULL,
            status_pagto VARCHAR(80) NULL,
            acao VARCHAR(30) NOT NULL,
            corpo TEXT NULL,
            KEY idx_data (recebido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($sqls as $sql) $pdo->exec($sql);
    $pdo->prepare('INSERT IGNORE INTO config (chave, valor) VALUES (?, ?)')->execute(['webhook_secret', bin2hex(random_bytes(24))]);
}

function cfg_get(string $k, ?string $def = null): ?string {
    $r = q('SELECT valor FROM config WHERE chave = ?', [$k])->fetch();
    return $r ? $r['valor'] : $def;
}

function cfg_set(string $k, string $v): void {
    q('INSERT INTO config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$k, $v]);
}

// Só deixa passar quem é admin ou afiliado aprovado (mesma regra dos cursos)
function exigirAfiliado(array $j): void {
    if (adminAtual() !== null) return;
    limitar('area:' . ip(), 200, 600);
    $email = email_ok($j['email'] ?? null);
    $ok = $email !== null && q("SELECT 1 FROM afiliados WHERE email = ? AND status = 'aprovado'", [$email])->fetch();
    if (!$ok) resp(403, ['erro' => 'sem_acesso']);
}

function secao_ok($v): string {
    if (!is_string($v) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v)) resp(400, ['erro' => 'secao_invalida']);
    return $v;
}

function conteudo_linha(array $c): array {
    return [
        'id' => $c['id'], 'secao' => $c['secao'], 'titulo' => $c['titulo'], 'texto' => $c['texto'],
        'link' => $c['link'], 'imagem_url' => $c['imagem_url'], 'ordem' => (int) $c['ordem'],
        'publicado' => (bool) $c['publicado'], 'links' => links_ler($c['links'] ?? null),
    ];
}

// Vários links de afiliação por item: lista de {rotulo, url}
function links_ler($raw): array {
    $a = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($a) ? array_values($a) : [];
}

function links_ok($v): ?string {
    if ($v === null) return null;
    if (!is_array($v) || count($v) > 20) resp(400, ['erro' => 'links_invalidos']);
    $out = [];
    foreach ($v as $l) {
        if (!is_array($l)) resp(400, ['erro' => 'links_invalidos']);
        $url = url_https($l['url'] ?? null);
        if ($url === null) continue;
        $out[] = ['rotulo' => texto($l['rotulo'] ?? null, 80) ?? '', 'url' => $url];
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function video_id_ok($v): ?string {
    $v = texto($v, 200);
    if ($v === null) return null;
    if (preg_match('/^[\w-]{11}$/', $v)) return $v;
    if (preg_match('#^https://(?:www\.|m\.)?youtube\.com/watch\?(?:[^\#]*&)?v=([\w-]{11})#i', $v, $m)) return $m[1];
    if (preg_match('#^https://youtu\.be/([\w-]{11})#i', $v, $m)) return $m[1];
    if (preg_match('#^https://(?:www\.)?youtube\.com/(?:embed|shorts|live)/([\w-]{11})#i', $v, $m)) return $m[1];
    resp(400, ['erro' => 'video_invalido']);
}

function depoimentos_ok($v): array {
    if (!is_array($v) || count($v) > 12) resp(400, ['erro' => 'depoimentos_invalidos']);
    $out = [];
    foreach ($v as $d) {
        if (!is_array($d)) continue;
        $t = texto($d['texto'] ?? null, 400);
        if ($t === null) continue;
        $out[] = ['texto' => $t, 'autor' => texto($d['autor'] ?? null, 80) ?? ''];
    }
    return $out;
}

function menu_ok($m): array {
    if (!is_array($m) || count($m) > 40) resp(400, ['erro' => 'menu_invalido']);
    $out = []; $ids = [];
    foreach ($m as $it) {
        if (!is_array($it)) resp(400, ['erro' => 'menu_invalido']);
        $id = secao_ok($it['id'] ?? null);
        if (strpos($id, 'admin') === 0 || isset($ids[$id])) resp(400, ['erro' => 'menu_invalido']);
        $ids[$id] = 1;
        $nome = texto($it['nome'] ?? null, 40);
        if ($nome === null) resp(400, ['erro' => 'nome_obrigatorio']);
        $tipo = ($it['tipo'] ?? 'cards') === 'posts' ? 'posts' : 'cards';
        $out[] = [
            'id' => $id, 'nome' => $nome, 'icone' => texto($it['icone'] ?? null, 8) ?? '•',
            'sub' => texto($it['sub'] ?? null, 150) ?? '', 'visivel' => !array_key_exists('visivel', $it) || !empty($it['visivel']),
            'tipo' => $tipo,
        ];
    }
    return $out;
}

// ===== Aprovação automática por pagamento (webhook) =====
function sem_acento(string $s): string {
    return strtr(mb_strtolower($s), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}

function coletar($d, string $caminho, array &$out, int $prof = 0): void {
    if ($prof > 6) return;
    if (is_array($d)) {
        foreach ($d as $k => $v) coletar($v, $caminho . '/' . sem_acento((string) $k), $out, $prof + 1);
    } elseif (is_string($d) || is_numeric($d)) {
        $out[] = [$caminho, (string) $d];
    }
}

function webhook_analisar(array $dados): array {
    $pares = [];
    coletar($dados, '', $pares);
    $email = null; $emailPref = null; $emailSolto = null; $nome = null; $status = [];
    foreach ($pares as [$cam, $val]) {
        $chave = substr($cam, (int) strrpos($cam, '/') + 1);
        $e = email_ok($val);
        if ($e !== null) {
            if (preg_match('/mail/', $chave)) {
                if ($emailPref === null && preg_match('#customer|buyer|cliente|comprador|payer|lead|client|subscriber#', $cam)) $emailPref = $e;
                if ($email === null) $email = $e;
            } elseif ($emailSolto === null) {
                $emailSolto = $e; // e-mail em campo com outro nome (ex.: contato)
            }
        }
        if ($nome === null && preg_match('/^(name|nome|full_name|customer_name|buyer_name|nome_completo)$/', $chave) && mb_strlen($val) >= 2 && mb_strlen($val) <= 120) $nome = $val;
        if (preg_match('/^(status|situacao|event|evento|type|tipo|payment_status|transaction_status|order_status|payment_state|webhook_event_type)$/', $chave)
            || (preg_match('/(status|situacao|evento|event)/', $chave) && mb_strlen($val) <= 40)) $status[] = sem_acento($val);
    }
    return ['email' => $emailPref ?? $email ?? $emailSolto, 'nome' => $nome, 'status' => $status];
}

function webhook_decidir(array $status): string {
    $pagos = ['paid','approved','aprovado','aprovada','pago','paga','completed','complete','concluido','concluida','confirmed','confirmado','confirmada','authorized','autorizado','succeeded','success','sucesso','captured','capturado'];
    $reemb = ['refunded','refund','reembolsado','reembolsada','reembolso','estornado','estornada','estorno','chargeback','canceled','cancelled','cancelado','cancelada'];
    $neg = ['pending','waiting','pendente','aguardando','unpaid','not','nao','failed','falhou','recusado','recusada','refused','denied','declined','expired','expirado','abandoned','abandonado'];
    $tp = $tr = $tn = false;
    foreach ($status as $st) {
        foreach (preg_split('/[^a-z]+/', $st, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, $reemb, true)) $tr = true;
            elseif (in_array($t, $neg, true)) $tn = true;
            elseif (in_array($t, $pagos, true)) $tp = true;
        }
    }
    if ($tr) return 'revogar';
    if ($tp && !$tn) return 'aprovar';
    return 'ignorar';
}


// ===== Fotos enviadas pelo admin (pasta /uploads, sem execução de código) =====
function pasta_uploads(): string { return dirname(__DIR__) . '/uploads'; }

function url_upload(string $nome): string {
    global $CFG;
    return rtrim($CFG['site'], '/') . '/uploads/' . $nome;
}

function nome_upload_da_url(?string $url): ?string {
    global $CFG;
    if ($url === null) return null;
    $pref = rtrim($CFG['site'], '/') . '/uploads/';
    if (strpos($url, $pref) !== 0) return null;
    $n = substr($url, strlen($pref));
    return preg_match('/^[a-f0-9]{24}\.(webp|jpg|png)$/', $n) ? $n : null;
}

// Apaga a foto enviada quando nenhum item usa mais
function limpar_upload(?string $url): void {
    $n = nome_upload_da_url($url);
    if ($n === null) return;
    if (q('SELECT 1 FROM conteudo WHERE imagem_url = ? LIMIT 1', [$url])->fetch()) return;
    if (q('SELECT 1 FROM cursos WHERE capa_url = ? LIMIT 1', [$url])->fetch()) return;
    $f = pasta_uploads() . '/' . $n;
    if (is_file($f)) @unlink($f);
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
            // Página só de login (entrar.html): não cria cadastro novo, só entra quem já é afiliado
            if (!$r && !empty($j['somente_existente'])) resp(404, ['erro' => 'nao_cadastrado']);
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

        // Importa uma lista (CSV lido no navegador): não cria duplicados, não desbloqueia ninguém e não rebaixa quem já é aprovado
        case 'afiliados_importar': {
            exigirPost();
            $j = entrada(900000);
            exigirAdmin();
            $status = status_ok($j['status'] ?? 'aprovado');
            $itens = $j['itens'] ?? null;
            if (!is_array($itens) || count($itens) > 5000) resp(400, ['erro' => 'lista_invalida']);
            $novos = $atualizados = $invalidos = $mantidos = 0;
            $vistos = [];
            $obs = $status === 'aprovado' ? 'Importado do amazole.me' : 'Importado (aguardando aprovação)';
            db()->beginTransaction();
            try {
                foreach ($itens as $it) {
                    if (!is_array($it)) { $invalidos++; continue; }
                    $email = email_ok($it['email'] ?? null);
                    if ($email === null || isset($vistos[$email])) { $invalidos++; continue; }
                    $vistos[$email] = 1;
                    $nome = isset($it['nome']) && is_string($it['nome']) ? (mb_substr(trim($it['nome']), 0, 120) ?: null) : null;
                    $whats = isset($it['whatsapp']) && is_string($it['whatsapp']) ? (mb_substr(trim($it['whatsapp']), 0, 30) ?: null) : null;
                    $ex = q('SELECT status FROM afiliados WHERE email = ?', [$email])->fetch();
                    if (!$ex) {
                        q('INSERT INTO afiliados (email, nome, whatsapp, status, observacao) VALUES (?, ?, ?, ?, ?)', [$email, $nome, $whats, $status, $obs]);
                        $novos++;
                    } elseif ($ex['status'] === 'bloqueado') {
                        $mantidos++;
                    } else {
                        $novoStatus = ($ex['status'] === 'aprovado' || $status === 'aprovado') ? 'aprovado' : 'pendente';
                        q('UPDATE afiliados SET nome = COALESCE(nome, ?), whatsapp = COALESCE(whatsapp, ?), status = ? WHERE email = ?', [$nome, $whats, $novoStatus, $email]);
                        $atualizados++;
                    }
                }
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            resp(200, ['ok' => true, 'novos' => $novos, 'atualizados' => $atualizados, 'invalidos' => $invalidos, 'bloqueados_mantidos' => $mantidos]);
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
                $antes = q('SELECT capa_url FROM cursos WHERE id = ?', [$id])->fetch();
                q('UPDATE cursos SET titulo = ?, descricao = ?, capa_url = ?, categoria = ?, destaque = ?, ordem = ?, publicado = ? WHERE id = ?', array_merge($d, [$id]));
                if ($antes && $antes['capa_url'] !== $d[2]) limpar_upload($antes['capa_url']);
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
            $idc = id_ok($j['id'] ?? null);
            $antes = q('SELECT capa_url FROM cursos WHERE id = ?', [$idc])->fetch();
            q('DELETE FROM cursos WHERE id = ?', [$idc]);
            if ($antes) limpar_upload($antes['capa_url']);
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

        // ===== Dados públicos da página inicial (link de pagamento, vídeo, depoimentos) =====
        case 'site_publico': {
            header('Cache-Control: public, max-age=60');
            $dep = json_decode(cfg_get('depoimentos', '[]') ?? '[]', true);
            resp(200, [
                'checkout_url' => cfg_get('checkout_url', '') ?? '',
                'video_id' => cfg_get('video_id', '') ?? '',
                'depoimentos' => is_array($dep) ? $dep : [],
            ]);
        }

        // ===== Área do afiliado: menu e conteúdo das seções =====
        case 'app_ler': {
            exigirPost();
            $j = entrada();
            exigirAfiliado($j);
            $menu = json_decode(cfg_get('menu', 'null') ?? 'null', true);
            $itens = [];
            foreach (q('SELECT * FROM conteudo WHERE publicado = 1 ORDER BY secao, ordem, created_at')->fetchAll() as $c) {
                $itens[$c['secao']][] = conteudo_linha($c);
            }
            resp(200, ['menu' => is_array($menu) ? $menu : null, 'conteudo' => (object) $itens]);
        }

        // ===== Admin: conteúdo das seções =====
        case 'conteudo_admin': {
            exigirAdmin();
            $rows = q('SELECT * FROM conteudo ORDER BY secao, ordem, created_at')->fetchAll();
            resp(200, ['itens' => array_map('conteudo_linha', $rows)]);
        }

        case 'conteudo_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $titulo = texto($j['titulo'] ?? null, 200);
            if ($titulo === null) resp(400, ['erro' => 'titulo_obrigatorio']);
            $d = [
                secao_ok($j['secao'] ?? null), $titulo, texto($j['texto'] ?? null, 5000),
                url_https($j['link'] ?? null), url_https($j['imagem_url'] ?? null),
                (int) ($j['ordem'] ?? 0), array_key_exists('publicado', $j) && !$j['publicado'] ? 0 : 1,
            ];
            $temLinks = array_key_exists('links', $j);
            $links = $temLinks ? links_ok($j['links']) : null;
            if (!empty($j['id'])) {
                $id = id_ok($j['id']);
                $antes = q('SELECT imagem_url FROM conteudo WHERE id = ?', [$id])->fetch();
                q('UPDATE conteudo SET secao = ?, titulo = ?, texto = ?, link = ?, imagem_url = ?, ordem = ?, publicado = ? WHERE id = ?', array_merge($d, [$id]));
                if ($temLinks) q('UPDATE conteudo SET links = ? WHERE id = ?', [$links, $id]);
                if ($antes && $antes['imagem_url'] !== $d[4]) limpar_upload($antes['imagem_url']);
            } else {
                $id = uuid();
                q('INSERT INTO conteudo (secao, titulo, texto, link, imagem_url, ordem, publicado, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($d, [$id]));
                if ($temLinks && $links !== null) q('UPDATE conteudo SET links = ? WHERE id = ?', [$links, $id]);
            }
            resp(200, ['ok' => true, 'id' => $id]);
        }

        case 'conteudo_remover': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            $idc = id_ok($j['id'] ?? null);
            $antes = q('SELECT imagem_url FROM conteudo WHERE id = ?', [$idc])->fetch();
            q('DELETE FROM conteudo WHERE id = ?', [$idc]);
            if ($antes) limpar_upload($antes['imagem_url']);
            resp(200, ['ok' => true]);
        }

        // ===== Admin: enviar foto (multipart). Reencoda a imagem para tirar qualquer conteúdo escondido =====
        case 'imagem_upload': {
            exigirPost();
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') resp(400, ['erro' => 'requisicao_invalida']);
            exigirAdmin();
            limitar('upload:' . ip(), 60, 3600);
            $f = $_FILES['arquivo'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) resp(400, ['erro' => 'arquivo_invalido']);
            if (($f['size'] ?? 0) > 4 * 1024 * 1024) resp(413, ['erro' => 'arquivo_grande']);
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) resp(400, ['erro' => 'formato_invalido']);
            if ($info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) resp(400, ['erro' => 'dimensao_invalida']);

            $dir = pasta_uploads();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) resp(500, ['erro' => 'pasta_indisponivel']);
            $base = bin2hex(random_bytes(12));
            $dados = file_get_contents($f['tmp_name']);
            $nome = null;
            if (function_exists('imagecreatefromstring')) {
                $im = @imagecreatefromstring($dados);
                if ($im) {
                    $w = imagesx($im); $h = imagesy($im);
                    $max = 1000;
                    if ($w > $max || $h > $max) {
                        $k = $max / max($w, $h);
                        $novo = imagecreatetruecolor((int) round($w * $k), (int) round($h * $k));
                        imagealphablending($novo, false); imagesavealpha($novo, true);
                        imagecopyresampled($novo, $im, 0, 0, 0, 0, imagesx($novo), imagesy($novo), $w, $h);
                        imagedestroy($im); $im = $novo;
                    }
                    if (function_exists('imagewebp')) {
                        imagealphablending($im, false); imagesavealpha($im, true);
                        if (@imagewebp($im, "$dir/$base.webp", 84)) $nome = "$base.webp";
                    }
                    if ($nome === null) {
                        $fundo = imagecreatetruecolor(imagesx($im), imagesy($im));
                        imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
                        imagecopy($fundo, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
                        if (@imagejpeg($fundo, "$dir/$base.jpg", 85)) $nome = "$base.jpg";
                        imagedestroy($fundo);
                    }
                    imagedestroy($im);
                }
            }
            if ($nome === null) {
                // Sem biblioteca de imagem no servidor: guarda o arquivo já validado
                $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
                if (!@move_uploaded_file($f['tmp_name'], "$dir/$base.$ext")) resp(500, ['erro' => 'falha_ao_salvar']);
                $nome = "$base.$ext";
            }
            @chmod("$dir/$nome", 0644);
            resp(200, ['ok' => true, 'url' => url_upload($nome)]);
        }

        // ===== Admin: menu =====
        case 'menu_ler': {
            exigirAdmin();
            $menu = json_decode(cfg_get('menu', 'null') ?? 'null', true);
            resp(200, ['menu' => is_array($menu) ? $menu : null]);
        }

        case 'menu_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            if (array_key_exists('menu', $j) && $j['menu'] === null) {
                q("DELETE FROM config WHERE chave = 'menu'");
                resp(200, ['ok' => true]);
            }
            cfg_set('menu', json_encode(menu_ok($j['menu'] ?? null), JSON_UNESCAPED_UNICODE));
            resp(200, ['ok' => true]);
        }

        // ===== Admin: configurações do site =====
        case 'config_ler': {
            exigirAdmin();
            $dep = json_decode(cfg_get('depoimentos', '[]') ?? '[]', true);
            $segredo = cfg_get('webhook_secret', '') ?? '';
            $ev = q('SELECT id, recebido_em, email, status_pagto, acao, corpo FROM webhook_log ORDER BY id DESC LIMIT 20')->fetchAll();
            foreach ($ev as &$e) { $e['recebido_em'] = iso($e['recebido_em']); $e['corpo'] = mb_substr((string) $e['corpo'], 0, 1500); }
            resp(200, [
                'checkout_url' => cfg_get('checkout_url', '') ?? '',
                'video_id' => cfg_get('video_id', '') ?? '',
                'depoimentos' => is_array($dep) ? $dep : [],
                'webhook_url' => rtrim($CFG['site'], '/') . '/api/?a=webhook&k=' . $segredo,
                'eventos' => $ev,
            ]);
        }

        case 'config_salvar': {
            exigirPost();
            $j = entrada();
            exigirAdmin();
            cfg_set('checkout_url', url_https($j['checkout_url'] ?? null) ?? '');
            cfg_set('video_id', video_id_ok($j['video_id'] ?? null) ?? '');
            cfg_set('depoimentos', json_encode(depoimentos_ok($j['depoimentos'] ?? []), JSON_UNESCAPED_UNICODE));
            resp(200, ['ok' => true]);
        }

        case 'webhook_regenerar': {
            exigirPost();
            entrada();
            exigirAdmin();
            cfg_set('webhook_secret', bin2hex(random_bytes(24)));
            resp(200, ['ok' => true]);
        }

        // ===== Admin: números =====
        case 'stats': {
            exigirAdmin();
            $tot = ['pendente' => 0, 'aprovado' => 0, 'bloqueado' => 0];
            foreach (q('SELECT status, COUNT(*) c FROM afiliados GROUP BY status')->fetchAll() as $r) $tot[$r['status']] = (int) $r['c'];
            $dias = [];
            for ($i = 29; $i >= 0; $i--) $dias[gmdate('Y-m-d', time() - $i * 86400)] = 0;
            foreach (q('SELECT DATE(created_at) d, COUNT(*) c FROM afiliados WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY DATE(created_at)')->fetchAll() as $r) {
                if (isset($dias[$r['d']])) $dias[$r['d']] = (int) $r['c'];
            }
            $por_dia = [];
            foreach ($dias as $d => $c) $por_dia[] = ['dia' => $d, 'novos' => $c];
            resp(200, [
                'total' => array_sum($tot), 'status' => $tot, 'por_dia' => $por_dia,
                'novos_hoje' => (int) q('SELECT COUNT(*) c FROM afiliados WHERE created_at >= UTC_DATE()')->fetch()['c'],
                'ativos_7d' => (int) q('SELECT COUNT(*) c FROM afiliados WHERE last_login_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)')->fetch()['c'],
                'aprovados_por_pagamento_30d' => (int) q("SELECT COUNT(*) c FROM webhook_log WHERE acao = 'aprovado' AND recebido_em >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)")->fetch()['c'],
                'conteudos' => (int) q('SELECT COUNT(*) c FROM conteudo')->fetch()['c'],
                'cursos' => (int) q('SELECT COUNT(*) c FROM cursos')->fetch()['c'],
            ]);
        }

        // ===== Webhook do gateway de pagamento (aprova o afiliado quando o pagamento é confirmado) =====
        case 'webhook': {
            $k = $_GET['k'] ?? '';
            $segredo = cfg_get('webhook_secret', '') ?? '';
            if (!is_string($k) || $segredo === '' || !hash_equals($segredo, $k)) {
                limitar('whk-falha:' . ip(), 20, 600);
                // Registra a tentativa (sem a chave) para o admin ver que o aviso chegou, mas com a chave errada
                try {
                    $rawf = (string) file_get_contents('php://input', false, null, 0, 1500);
                    q('INSERT INTO webhook_log (email, status_pagto, acao, corpo) VALUES (NULL, ?, ?, ?)', [
                        mb_substr(($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['CONTENT_TYPE'] ?? ''), 0, 80), 'chave_invalida', mb_substr($rawf, 0, 1500),
                    ]);
                } catch (Throwable $e) { /* ignora */ }
                resp(403, ['erro' => 'nao_autorizado']);
            }
            limitar('whk:' . ip(), 300, 600);
            $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
            if (strlen($raw) > 65536) resp(413, ['erro' => 'muito_grande']);
            $dados = json_decode($raw, true);
            if (!is_array($dados)) { $dados = []; if ($raw !== '') parse_str($raw, $dados); }
            if (!$dados && !empty($_POST)) $dados = $_POST;
            $an = webhook_analisar(is_array($dados) ? $dados : []);
            $email = $an['email'];
            $decisao = webhook_decidir($an['status']);
            if ($email === null) {
                $acao_log = 'sem_email';
            } elseif ($decisao === 'aprovar') {
                $antes = q('SELECT status FROM afiliados WHERE email = ?', [$email])->fetch();
                if ($antes && $antes['status'] === 'bloqueado') {
                    $acao_log = 'bloqueado_mantido';
                } else {
                    q("INSERT INTO afiliados (email, nome, status, observacao) VALUES (?, ?, 'aprovado', 'Aprovado automaticamente pelo pagamento')
                       ON DUPLICATE KEY UPDATE status = 'aprovado', nome = COALESCE(nome, VALUES(nome))", [$email, $an['nome']]);
                    $acao_log = 'aprovado';
                }
            } elseif ($decisao === 'revogar') {
                $st = q("UPDATE afiliados SET status = 'pendente', observacao = 'Pagamento reembolsado ou cancelado' WHERE email = ? AND status = 'aprovado'", [$email]);
                $acao_log = $st->rowCount() ? 'revogado' : 'ignorado';
            } else {
                // Chegou um e-mail, mas o status não é de pagamento aprovado: deixa na lista como pendente
                // (sem mexer em quem já é aprovado ou bloqueado), para o admin ver e decidir.
                q("INSERT INTO afiliados (email, nome, status, observacao) VALUES (?, ?, 'pendente', ?)
                   ON DUPLICATE KEY UPDATE nome = COALESCE(nome, VALUES(nome))", [$email, $an['nome'], mb_substr('Aviso do gateway sem pagamento aprovado: ' . implode(',', array_unique($an['status'])), 0, 480)]);
                $acao_log = 'ignorado';
            }
            q('INSERT INTO webhook_log (email, status_pagto, acao, corpo) VALUES (?, ?, ?, ?)', [
                $email, mb_substr(implode(',', array_unique($an['status'])), 0, 80), $acao_log, mb_substr($raw !== '' ? $raw : json_encode($dados), 0, 3000),
            ]);
            if (random_int(1, 40) === 1) q('DELETE FROM webhook_log WHERE recebido_em < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)');
            resp(200, ['ok' => true, 'acao' => $acao_log]);
        }

        default:
            resp(404, ['erro' => 'nao_encontrado']);
    }
} catch (Throwable $e) {
    error_log('[comunidade-api] ' . $e->getMessage());
    resp(500, ['erro' => 'erro_interno']);
}
