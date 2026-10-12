<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
security_headers(true);
$status = 404;
$message = 'Este link não está disponível.';
try {
    $code = $_GET['c'] ?? '';
    if (is_string($code) && preg_match('/^[A-Za-z0-9_-]{12}$/D', $code)) {
        $q = db()->prepare('SELECT destination, active FROM rq_links WHERE code = ? LIMIT 1');
        $q->execute([$code]); $link = $q->fetch();
        if ($link && (int)$link['active']) {
            // Always a temporary, non-cacheable redirect. The printed QR encodes this router, never the destination.
            $destination = validate_destination($link['destination']);
            header('Location: ' . $destination, true, 302);
            exit;
        }
        if ($link) { $status = 410; $message = 'O responsável pausou este QR Code. Tente novamente mais tarde.'; }
    }
} catch (Throwable $error) {
    $status = 503; $message = 'Estamos com uma indisponibilidade temporária. Tente novamente em instantes.';
    error_log('RotaQR redirect: ' . get_class($error));
    header('Retry-After: 60');
}
http_response_code($status);
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Link indisponível · RotaQR</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"></head><body class="message-page"><main class="message-card"><div class="brand-mark" aria-hidden="true">R</div><p class="eyebrow">ROTAQR</p><h1>Não foi possível continuar.</h1><p><?=e($message)?></p></main></body></html>
