<?php
// Abre uma notificação (marca como lida e segue para o link) ou marca todas como lidas.
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE', 'COLABORADOR', 'ADMIN']);
$coluna = coluna_notificacao($u);
$home = '../' . home_do_perfil($u['perfil']);

if (acao() === 'ler_todas') {
    db_exec("UPDATE notificacao SET lida = 1 WHERE $coluna = ?", [$u['id']]);
    $volta = $_SERVER['HTTP_REFERER'] ?? '';
    $host  = parse_url($volta, PHP_URL_HOST) . (parse_url($volta, PHP_URL_PORT) ? ':' . parse_url($volta, PHP_URL_PORT) : '');
    // Só volta para páginas do próprio sistema.
    redirecionar($volta !== '' && $host === ($_SERVER['HTTP_HOST'] ?? '') ? $volta : $home);
}

$n = db_one("SELECT id, link FROM notificacao WHERE id = ? AND $coluna = ?", [(int)($_GET['id'] ?? 0), $u['id']]);
if (!$n) redirecionar($home);

db_exec('UPDATE notificacao SET lida = 1 WHERE id = ?', [$n['id']]);
redirecionar($n['link'] ? '../' . $n['link'] : $home);
