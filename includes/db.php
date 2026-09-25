<?php
// ─── CONEXÃO COM O BANCO ─────────────────────────────────────────────────────
// Valores padrão do XAMPP. Para usar outras credenciais na sua máquina, crie
// includes/conexao.php (ignorado pelo git) redefinindo estas variáveis.
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'gestao_oficina';

if (file_exists(__DIR__ . '/conexao.php')) {
    require __DIR__ . '/conexao.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $ex) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:#C0392B;padding:2rem">Erro de conexão com o banco de dados: '
        . htmlspecialchars($ex->getMessage()) . '<br><br>Verifique se o MySQL está rodando e se o banco '
        . '<code>gestao_oficina</code> foi importado (database/gestao_oficina.sql).</p>');
}
