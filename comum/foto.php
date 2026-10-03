<?php
// Entrega uma foto de atendimento somente para quem pode vê-la:
// colaboradores/administradores (todas) e o cliente dono do veículo.
// O acesso direto a uploads/fotos/ é bloqueado pelo .htaccess da pasta.
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE', 'COLABORADOR', 'ADMIN']);

function nao_encontrada(): never {
    http_response_code(404);
    exit('Foto não encontrada.');
}

// Nome gerado pelo sistema: UUID + extensão de imagem. Qualquer outra coisa
// (ex.: "../includes/db.php") é recusada antes de tocar no disco.
$arquivo = get('f');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$/', $arquivo, $m)) {
    nao_encontrada();
}

$foto = db_one('SELECT f.arquivo, v.idCliente FROM foto f
                JOIN protocolo p ON p.numero = f.numeroProtocolo
                JOIN veiculo v   ON v.id = p.idVeiculo
                WHERE f.arquivo = ?', [$arquivo]);
if (!$foto || ($u['perfil'] === 'CLIENTE' && $foto['idCliente'] !== $u['id'])) nao_encontrada();

$caminho = __DIR__ . '/../' . PASTA_FOTOS . $foto['arquivo'];
if (!is_file($caminho)) nao_encontrada();

$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . $tipos[$m[1]]);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: inline; filename="' . $foto['arquivo'] . '"');
// Pode ficar em cache só no navegador do próprio usuário (nunca em proxies).
header('Cache-Control: private, max-age=86400');
header_remove('Pragma');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($caminho);
