<?php
// Roteador para o servidor embutido do PHP, que ignora os arquivos .htaccess:
//   php -S localhost:8080 router.php
// Aplica as mesmas restrições do .htaccess: pastas internas, arquivos ocultos,
// dumps de banco, documentação e credenciais não são servidos.
$caminho = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$caminho = str_replace('\\', '/', $caminho);

$bloqueado = preg_match('#(^|/)\.#', $caminho)                                  // .git, .htaccess, .env...
    || preg_match('#(^|/)(includes|database|uploads)(/|$)#i', $caminho)         // pastas internas
    || preg_match('#\.(sql|md|txt|log|ini|conf|bak|old|orig|swp|tmp|dist|json|lock|ya?ml|env|sh|ps1|bat)$#i', $caminho)
    || preg_match('#(^|/)router\.php$#i', $caminho);

if ($bloqueado) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Não encontrado.');
}
header('X-Content-Type-Options: nosniff');
return false;   // deixa o servidor embutido entregar o arquivo normalmente
