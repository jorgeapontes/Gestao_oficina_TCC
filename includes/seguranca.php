<?php
// ─── SEGURANÇA ───────────────────────────────────────────────────────────────
// Tratamento de erros, cabeçalhos HTTP, sessão, revalidação do usuário logado,
// limite de tentativas (força bruta) e validação de senha.
// Incluído por includes/app.php logo após a conexão (db.php).

// ─── ERROS: detalhes só no log, nunca na tela (exceto em MODO_DEBUG) ─────────
error_reporting(E_ALL);
ini_set('display_errors', MODO_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $ex): void {
    error_log('[IMM] Exceção não tratada: ' . $ex);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<p style="font-family:sans-serif;color:#C0392B;padding:2rem">Ocorreu um erro inesperado. '
       . 'Volte e tente novamente.</p>';
    if (MODO_DEBUG) echo '<pre style="padding:0 2rem">' . htmlspecialchars((string)$ex) . '</pre>';
});

// ─── CABEÇALHOS HTTP DE SEGURANÇA ────────────────────────────────────────────
function conexao_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function enviar_cabecalhos_seguranca(): void {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    // CSP: só carrega recursos do próprio sistema e dos serviços já usados
    // (Google Fonts, Chart.js no cdnjs e a consulta de CEP no ViaCEP).
    // 'unsafe-inline' é necessário porque as páginas usam scripts e onclick embutidos.
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "img-src 'self' data: blob:; "
         . "connect-src 'self' https://viacep.com.br; "
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');                         // impede clickjacking (navegadores antigos)
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (conexao_https()) header('Strict-Transport-Security: max-age=31536000');
}
enviar_cabecalhos_seguranca();

// ─── SESSÃO ──────────────────────────────────────────────────────────────────
const SESSAO_INATIVIDADE = 2 * 60 * 60;    // encerra após 2 h sem uso
const SESSAO_MAXIMA      = 12 * 60 * 60;   // e, de qualquer forma, após 12 h

ini_set('session.use_strict_mode', '1');   // rejeita IDs de sessão não criados pelo servidor
ini_set('session.use_only_cookies', '1');  // nunca aceita o ID pela URL
ini_set('session.use_trans_sid', '0');
if (PHP_VERSION_ID < 80400) {            // no PHP 8.4+ o padrão já é forte (e estas opções foram descontinuadas)
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
}
session_name('IMMSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => conexao_https(),
    'httponly' => true,                    // JavaScript não lê o cookie (mitiga roubo via XSS)
    'samesite' => 'Lax',                   // não é enviado em POSTs de outros sites
]);
session_start();

// Apaga os dados da sessão e o cookie (logout, conta removida etc.).
function encerrar_sessao(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

// Sai da conta mas mantém uma sessão nova (para mostrar o aviso na tela de login).
function deslogar_com_aviso(string $aviso): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['_auth_flash'] = ['aba' => 'login', 'erro' => $aviso, 'old' => []];
}

// Expiração por inatividade e por tempo máximo.
if (isset($_SESSION['user'])) {
    $agora = time();
    if ($agora - ($_SESSION['_ultimo'] ?? 0) > SESSAO_INATIVIDADE
        || $agora - ($_SESSION['_inicio'] ?? 0) > SESSAO_MAXIMA) {
        deslogar_com_aviso('Sua sessão expirou. Entre novamente.');
    } else {
        $_SESSION['_ultimo'] = $agora;
    }
}

// Impressão digital da senha atual: se a senha mudar (em outro dispositivo ou
// pelo administrador), as demais sessões abertas daquela conta deixam de valer.
function digital_senha(string $hash): string { return hash('sha256', $hash); }

// Inicia a sessão de um usuário autenticado. $u precisa conter o hash da senha.
function iniciar_sessao_usuario(array $u): void {
    session_regenerate_id(true);           // evita fixação de sessão
    $digital = digital_senha($u['senha']);
    unset($u['senha']);
    $_SESSION = [];
    $_SESSION['user']   = $u;
    $_SESSION['_fp']    = $digital;
    $_SESSION['_inicio'] = $_SESSION['_ultimo'] = time();
}

// Atualiza a digital após o próprio usuário trocar a senha (a sessão atual continua válida).
function atualizar_digital_senha(string $novoHash): void { $_SESSION['_fp'] = digital_senha($novoHash); }

// Recarrega o usuário do banco: conta removida, perfil alterado ou senha trocada
// têm efeito imediato, sem depender do que ficou gravado na sessão.
function revalidar_usuario(): ?array {
    $u = $_SESSION['user'] ?? null;
    if (!$u) return null;
    $atual = $u['perfil'] === 'CLIENTE'
        ? db_one("SELECT id, nome, email, senha, '' AS cargo, 'CLIENTE' AS perfil FROM cliente WHERE id = ?", [$u['id']])
        : db_one('SELECT id, nome, email, senha, cargo, perfil FROM colaborador WHERE id = ?', [$u['id']]);
    if (!$atual || !hash_equals($_SESSION['_fp'] ?? '', digital_senha($atual['senha']))) {
        deslogar_com_aviso('Sua sessão foi encerrada. Entre novamente.');
        return null;
    }
    unset($atual['senha']);
    $_SESSION['user'] = $atual;
    return $atual;
}

// ─── LIMITE DE TENTATIVAS (FORÇA BRUTA) ──────────────────────────────────────
// As chaves (IP, e-mail, CPF) são gravadas como hash, sem guardar o dado em si.
const LOGIN_MAX_POR_CONTA = 5;        // falhas por e-mail/CPF ...
const LOGIN_MAX_POR_IP    = 20;       // ... e por IP ...
const LOGIN_JANELA        = 15 * 60;  // ... a cada 15 minutos
const CADASTRO_MAX_POR_IP = 5;        // contas criadas por IP ...
const CADASTRO_JANELA     = 60 * 60;  // ... por hora

function garantir_tabela_tentativas(): void {
    static $ok = false;
    if ($ok) return;
    db_exec('CREATE TABLE IF NOT EXISTS tentativa_login (
               id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
               tipo VARCHAR(20) NOT NULL,
               chave CHAR(64) NOT NULL,
               dataHora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               KEY idx_tentativa (tipo, chave, dataHora)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $ok = true;
}
function chave_tentativa(string $valor): string { return hash('sha256', mb_strtolower(trim($valor))); }
function ip_cliente(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

function limite_excedido(string $tipo, string $valor, int $max, int $janela): bool {
    garantir_tabela_tentativas();
    return (int)db_val('SELECT COUNT(*) FROM tentativa_login WHERE tipo = ? AND chave = ?
                        AND dataHora > NOW() - INTERVAL ? SECOND', [$tipo, chave_tentativa($valor), $janela]) >= $max;
}
function registrar_tentativa(string $tipo, string $valor): void {
    garantir_tabela_tentativas();
    db_exec('INSERT INTO tentativa_login (tipo, chave) VALUES (?, ?)', [$tipo, chave_tentativa($valor)]);
    if (random_int(1, 50) === 1) db_exec('DELETE FROM tentativa_login WHERE dataHora < NOW() - INTERVAL 1 DAY');
}
function limpar_tentativas(string $tipo, string $valor): void {
    garantir_tabela_tentativas();
    db_exec('DELETE FROM tentativa_login WHERE tipo = ? AND chave = ?', [$tipo, chave_tentativa($valor)]);
}

// Hash fictício: quando o usuário não existe, a senha é verificada contra ele
// para o tempo de resposta não revelar quais e-mails/CPFs estão cadastrados.
const HASH_FICTICIO = '$2y$10$e434OaOtHdFiofkvOB9kxO0RXPf3MtMZGAV3vXzWCGOOQhxLYIBoO';
function conferir_senha(string $senha, ?array $u): bool {
    $ok = password_verify($senha, $u['senha'] ?? HASH_FICTICIO);
    return $u !== null && $ok;
}

// ─── POLÍTICA DE SENHA ───────────────────────────────────────────────────────
// Retorna a mensagem de erro, ou null se a senha for aceitável.
function erro_senha(string $senha, string $email = ''): ?string {
    if (strlen($senha) < 8)  return 'A senha deve ter ao menos 8 caracteres.';
    if (strlen($senha) > 72) return 'A senha deve ter no máximo 72 caracteres.';
    if (!preg_match('/\pL/u', $senha) || !preg_match('/\d/', $senha)) return 'A senha deve conter letras e números.';
    if ($email !== '' && mb_strtolower($senha) === mb_strtolower($email)) return 'A senha não pode ser igual ao e-mail.';
    return null;
}

// ─── CPF ─────────────────────────────────────────────────────────────────────
// Valida CPF pelo algoritmo oficial dos dígitos verificadores (módulo 11),
// rejeitando também sequências de dígitos repetidos (ex.: 000.000.000-00).
function cpf_valido(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t <= 10; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) $soma += (int)$cpf[$i] * (($t + 1) - $i);
        if ((int)$cpf[$t] !== (($soma * 10) % 11) % 10) return false;
    }
    return true;
}

// ─── CSV ─────────────────────────────────────────────────────────────────────
// Evita "injeção de fórmula": o Excel executaria células começando com = + - @.
function csv_seguro($v): string {
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}
