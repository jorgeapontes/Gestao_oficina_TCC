<?php
// Endpoint JSON do catálogo da Carpedia para os formulários de veículo. O navegador só
// fala com este arquivo; a chave da API e a própria Carpedia ficam do lado do servidor.
//   GET carpedia.php?acao=marcas
//   GET carpedia.php?acao=modelos&marca=<slug>
//   GET carpedia.php?acao=versoes&marca=<slug>&modelo=<slug>
// Resposta: {"ok":true,"itens":[...]} ou {"ok":false} (indisponível ou inválido).
require __DIR__ . '/../includes/app.php';
exigir_login(['CLIENTE', 'COLABORADOR', 'ADMIN']);

header('Content-Type: application/json; charset=utf-8');

function responder(?array $itens, int $http = 200): never {
    http_response_code($http);
    echo json_encode($itens === null ? ['ok' => false] : ['ok' => true, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') responder(null, 405);

// Só os campos que o formulário usa.
switch (get('acao')) {
    case 'marcas':
        responder(carpedia_marcas());
    case 'modelos':
        if (!carpedia_slug_ok(get('marca'))) responder(null, 400);
        responder(carpedia_modelos(get('marca')));
    case 'versoes':
        if (!carpedia_slug_ok(get('marca')) || !carpedia_slug_ok(get('modelo'))) responder(null, 400);
        responder(carpedia_versoes(get('marca'), get('modelo')));
    default:
        responder(null, 400);
}
