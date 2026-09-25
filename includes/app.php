<?php
// ─── BOOTSTRAP COMUM A TODAS AS PÁGINAS ──────────────────────────────────────
// Sessão, conexão, autenticação e funções utilitárias.
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
date_default_timezone_set('America/Sao_Paulo');

// ─── CONSULTAS ───────────────────────────────────────────────────────────────
// Executa um prepared statement. Tipos são inferidos quando não informados.
function db_exec(string $sql, array $params = []): mysqli_stmt {
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}
function db_all(string $sql, array $params = []): array {
    return db_exec($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
}
function db_one(string $sql, array $params = []): ?array {
    return db_exec($sql, $params)->get_result()->fetch_assoc() ?: null;
}
function db_val(string $sql, array $params = []) {
    $row = db_exec($sql, $params)->get_result()->fetch_row();
    return $row ? $row[0] : null;
}
// Mensagem amigável para erros de gravação (ex.: CPF/e-mail/placa duplicados).
function erro_db(mysqli_sql_exception $ex): string {
    if ($ex->getCode() === 1062) {
        if (str_contains($ex->getMessage(), "'cpf'"))   return 'Já existe um cadastro com este CPF.';
        if (str_contains($ex->getMessage(), "'email'")) return 'Já existe um cadastro com este e-mail.';
        if (str_contains($ex->getMessage(), "'placa'")) return 'Já existe um veículo com esta placa.';
        return 'Registro duplicado.';
    }
    if ($ex->getCode() === 1451) return 'Não é possível remover: existem registros vinculados.';
    return 'Erro no banco de dados: ' . $ex->getMessage();
}

// ─── HELPERS DE TEXTO / FORMATAÇÃO ───────────────────────────────────────────
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// UUID v7: começa pelo timestamp em ms, então ordenar por id preserva a ordem de
// inserção (útil para itens gravados no mesmo segundo).
function uuid(): string {
    static $ultimo = 0;
    $ms = max((int)floor(microtime(true) * 1000), $ultimo + 1);
    $ultimo = $ms;
    $b = substr(pack('J', $ms), 2) . random_bytes(10);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x70);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function so_digitos($s): string { return preg_replace('/\D/', '', (string)$s); }
function initials(string $nome): string {
    $parts = preg_split('/\s+/', trim($nome));
    $i = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
    if (count($parts) > 1) $i .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    return $i;
}
function primeiro_nome(string $nome): string { return explode(' ', trim($nome))[0]; }
function fmt_cpf($cpf): string {
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', (string)$cpf);
}
function fmt_tel($tel): string {
    $t = so_digitos($tel);
    if (strlen($t) === 11) return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $t);
    if (strlen($t) === 10) return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $t);
    return $t !== '' ? $t : '—';
}
function normalizar_placa($p): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$p));
    // Placa antiga (ABC1234) exibida com hífen; Mercosul (ABC1D23) sem.
    return preg_match('/^[A-Z]{3}\d{4}$/', $p) ? substr($p, 0, 3) . '-' . substr($p, 3) : $p;
}
function proto_num($n): string { return '#' . str_pad((string)$n, 5, '0', STR_PAD_LEFT); }

const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
               'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const DIAS  = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

function data_hoje(): string {
    return DIAS[(int)date('w')] . ', ' . date('d') . ' ' . mb_substr(MESES[(int)date('n')], 0, 3) . ' ' . date('Y');
}
function fmt_data($d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function fmt_data_hora($d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function fmt_data_curta($d): string {   // 13 mai 2026
    if (!$d) return '—';
    $t = strtotime($d);
    return date('d', $t) . ' ' . mb_substr(MESES[(int)date('n', $t)], 0, 3) . ' ' . date('Y', $t);
}
function fmt_data_extenso($d): string { // 13 de maio de 2026
    $t = strtotime($d);
    return date('d', $t) . ' de ' . MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
}
function mes_ano($d): string {          // Maio 2026
    $t = strtotime($d);
    return ucfirst(MESES[(int)date('n', $t)]) . ' ' . date('Y', $t);
}
function tempo_relativo($d): string {
    $s = time() - strtotime($d);
    if ($s < 60)    return 'agora';
    if ($s < 3600)  return 'há ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'há ' . floor($s / 3600) . 'h ' . str_pad((string)floor(($s % 3600) / 60), 2, '0', STR_PAD_LEFT) . 'min';
    return fmt_data_hora($d);
}
function plural(int $n, string $sing, string $plur): string { return $n . ' ' . ($n === 1 ? $sing : $plur); }

// ─── STATUS ──────────────────────────────────────────────────────────────────
// Retornam [classe do badge, rótulo].
function status_protocolo(?string $s): array {
    return match ($s) {
        'ABERTO'         => ['open', 'Aberto'],
        'EM_ATENDIMENTO' => ['prog', 'Em atendimento'],
        'FINALIZADO'     => ['done', 'Finalizado'],
        'CANCELADO'      => ['done', 'Cancelado'],
        default          => ['done', '—'],
    };
}
function status_veiculo(?string $statusProtocoloAtivo): array {
    return match ($statusProtocoloAtivo) {
        'EM_ATENDIMENTO' => ['prog', 'Em serviço'],
        'ABERTO'         => ['open', 'Aguardando'],
        default          => ['done', 'Sem pendências'],
    };
}
function badge(array $st): string { return '<span class="badge ' . e($st[0]) . '">' . e($st[1]) . '</span>'; }

// Status do protocolo ativo (não finalizado/cancelado) de cada veículo: SQL reutilizável.
const SQL_STATUS_ATIVO = "(SELECT p.status FROM protocolo p WHERE p.idVeiculo = v.id
                           AND p.status IN ('ABERTO','EM_ATENDIMENTO') ORDER BY p.numero DESC LIMIT 1)";

// ─── REGRAS DE NEGÓCIO COMPARTILHADAS ────────────────────────────────────────
// Retorna o id do modelo (da marca informada), criando-o se ainda não existir.
function obter_modelo(string $idMarca, string $nomeModelo): string {
    $nomeModelo = trim($nomeModelo);
    $id = db_val('SELECT id FROM modelo WHERE idMarca = ? AND nome = ?', [$idMarca, $nomeModelo]);
    if ($id) return $id;
    $id = uuid();
    db_exec('INSERT INTO modelo (id, nome, idMarca) VALUES (?,?,?)', [$id, $nomeModelo, $idMarca]);
    return $id;
}

// Muda o status do protocolo e mantém a ocorrência vinculada em sincronia.
function mudar_status_protocolo(int $numero, string $novo): void {
    $mapOcorrencia = ['ABERTO' => 'ABERTA', 'EM_ATENDIMENTO' => 'EM_ANDAMENTO',
                      'FINALIZADO' => 'FECHADA', 'CANCELADO' => 'CANCELADA'];
    $final = in_array($novo, ['FINALIZADO', 'CANCELADO'], true) ? date('Y-m-d') : null;
    db_exec('UPDATE protocolo SET status = ?, dataFinalizacao = ? WHERE numero = ?', [$novo, $final, $numero]);
    db_exec('UPDATE ocorrencia o JOIN protocolo p ON p.idOcorrencia = o.id SET o.status = ? WHERE p.numero = ?',
            [$mapOcorrencia[$novo], $numero]);
}

// ─── REQUISIÇÃO / FLASH / CSRF ───────────────────────────────────────────────
function redirecionar(string $url): never { header('Location: ' . $url); exit; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function post(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function acao(): string { return is_post() ? post('_action') : ''; }

function flash(string $tipo, string $msg): void { $_SESSION['flash'] = [$tipo, $msg]; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '"/>'; }
function verificar_csrf(): void {
    if (is_post() && !hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        die('Requisição inválida (token expirado). Volte e tente novamente.');
    }
}

// ─── AUTENTICAÇÃO ────────────────────────────────────────────────────────────
// $_SESSION['user'] = [id, nome, email, perfil (CLIENTE|COLABORADOR|ADMIN), cargo]
function usuario(): ?array { return $_SESSION['user'] ?? null; }
function e_admin(): bool { return (usuario()['perfil'] ?? '') === 'ADMIN'; }

// Caminho (a partir da raiz do projeto) da página inicial de cada perfil.
function home_do_perfil(string $perfil): string {
    return match ($perfil) {
        'ADMIN'       => 'admin/dashboard.php',
        'COLABORADOR' => 'colaborador/dashboard.php',
        default       => 'cliente/dashboard.php',
    };
}

// Todas as páginas internas ficam um nível abaixo da raiz, por isso o "../".
function exigir_login(array $perfis): array {
    $u = usuario();
    if (!$u) redirecionar('../login.php');
    if (!in_array($u['perfil'], $perfis, true)) redirecionar('../' . home_do_perfil($u['perfil']));
    verificar_csrf();
    return $u;
}
