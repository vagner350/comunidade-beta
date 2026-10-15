<?php
// Cliente SMTP mínimo (SSL implícito, AUTH LOGIN). Nunca registra senhas nos logs.
declare(strict_types=1);

function smtp_ler($fp): array {
    $linhas = [];
    $codigo = 0;
    while (($l = fgets($fp, 1024)) !== false) {
        $linhas[] = rtrim($l);
        if (strlen($l) >= 4 && $l[3] === ' ') { $codigo = (int) substr($l, 0, 3); break; }
        if (strlen($l) < 4) break;
    }
    return [$codigo, implode(' | ', $linhas)];
}

function smtp_cmd($fp, string $cmd, array $esperado): array {
    fwrite($fp, $cmd . "\r\n");
    $r = smtp_ler($fp);
    if (!in_array($r[0], $esperado, true)) throw new RuntimeException('resposta ' . $r[0]);
    return $r;
}

function smtp_abrir(array $c) {
    $esq = ($c['seguro'] ?? true) ? 'ssl' : 'tcp';
    $fp = @stream_socket_client($esq . '://' . $c['host'] . ':' . $c['port'], $en, $es, 15);
    if (!$fp) throw new RuntimeException('sem conexao');
    stream_set_timeout($fp, 20);
    $r = smtp_ler($fp);
    if ($r[0] !== 220) throw new RuntimeException('saudacao ' . $r[0]);
    smtp_cmd($fp, 'EHLO comunidade.amazole.me', [250]);
    smtp_cmd($fp, 'AUTH LOGIN', [334]);
    smtp_cmd($fp, base64_encode($c['user']), [334]);
    try {
        smtp_cmd($fp, base64_encode($c['pass']), [235]);
    } catch (RuntimeException $e) {
        throw new RuntimeException('login recusado');
    }
    return $fp;
}

function smtp_testar(array $c): bool {
    try {
        $fp = smtp_abrir($c);
        @smtp_cmd($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('[smtp] teste falhou: ' . $e->getMessage());
        return false;
    }
}

function smtp_enviar(array $c, string $para, string $assunto, string $texto, ?string $responderPara = null): bool {
    $fp = null;
    try {
        if (preg_match('/[\r\n<>]/', $para)) throw new RuntimeException('destino invalido');
        $fp = smtp_abrir($c);
        smtp_cmd($fp, 'MAIL FROM:<' . $c['user'] . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $para . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        $dominio = substr(strrchr($c['user'], '@'), 1);
        $h = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode('Amazolé') . '?= <' . $c['user'] . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($responderPara && !preg_match('/[\r\n<>]/', $responderPara)) $h[] = 'Reply-To: <' . $responderPara . '>';
        $corpo = chunk_split(base64_encode($texto), 76, "\r\n");
        fwrite($fp, implode("\r\n", $h) . "\r\n\r\n" . $corpo . ".\r\n");
        $r = smtp_ler($fp);
        if ($r[0] !== 250) throw new RuntimeException('envio recusado ' . $r[0]);
        @smtp_cmd($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('[smtp] envio falhou: ' . $e->getMessage());
        if (is_resource($fp)) @fclose($fp);
        return false;
    }
}
