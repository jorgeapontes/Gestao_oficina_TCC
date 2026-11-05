<?php
// ─── CONEXÃO COM O BANCO ─────────────────────────────────────────────────────
// Valores padrão do XAMPP. Para usar outras credenciais na sua máquina, crie
// includes/conexao.php (ignorado pelo git) redefinindo estas variáveis.
// Em produção, use um usuário próprio do MySQL com senha e apenas os privilégios
// SELECT, INSERT, UPDATE e DELETE no banco gestao_oficina (nunca o "root").
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'gestao_oficina';

// Modo de depuração: quando true, erros detalhados aparecem na tela.
// Deixe false fora do ambiente de desenvolvimento (pode ser redefinido em conexao.php).
$DEBUG = false;

if (file_exists(__DIR__ . '/conexao.php')) {
    require __DIR__ . '/conexao.php';
}

if (!defined('MODO_DEBUG')) define('MODO_DEBUG', (bool)$DEBUG);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $ex) {
    // O detalhe técnico vai para o log do servidor, nunca para o navegador.
    error_log('[IMM] Falha na conexão com o banco: ' . $ex->getMessage());
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:#C0392B;padding:2rem">Serviço temporariamente indisponível. '
        . 'Tente novamente em instantes.'
        . (MODO_DEBUG ? '<br><br>' . htmlspecialchars($ex->getMessage()) . '<br>Verifique se o MySQL está rodando e se o banco '
                        . '<code>gestao_oficina</code> foi importado (database/gestao_oficina.sql).' : '')
        . '</p>');
}
// As credenciais não precisam mais ficar em memória depois de conectar.
unset($DB_PASS);
