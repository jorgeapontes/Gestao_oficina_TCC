<?php
// ─── BOOTSTRAP COMUM A TODAS AS PÁGINAS ──────────────────────────────────────
// Conexão, segurança (cabeçalhos, sessão, limites), autenticação e utilitários.
require __DIR__ . '/db.php';
require __DIR__ . '/seguranca.php';
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
    if ($ex->getCode() === 1406) return 'Um dos campos excede o tamanho permitido.';
    // Detalhes técnicos (tabelas, colunas, SQL) ficam só no log do servidor.
    error_log('[IMM] Erro de banco: ' . $ex->getMessage());
    return 'Não foi possível salvar os dados. Verifique as informações e tente novamente.';
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
// Ciclo do protocolo: ABERTO → (etapas intermediárias) → FINALIZADO ou CANCELADO.
// Enquanto não for finalizado/cancelado, o protocolo é considerado "ativo".
const STATUS_ATIVOS = ['ABERTO', 'AGUARDANDO_APROVACAO', 'EM_ATENDIMENTO', 'AGUARDANDO_PECAS', 'PRONTO'];
// Lista SQL dos status ativos, para usar em "p.status IN " . SQL_ATIVOS.
const SQL_ATIVOS = "('ABERTO','AGUARDANDO_APROVACAO','EM_ATENDIMENTO','AGUARDANDO_PECAS','PRONTO')";

// Retornam [classe do badge, rótulo].
function status_protocolo(?string $s): array {
    return match ($s) {
        'ABERTO'               => ['open',   'Aberto'],
        'AGUARDANDO_APROVACAO' => ['wait',   'Aguardando aprovação'],
        'EM_ATENDIMENTO'       => ['prog',   'Em atendimento'],
        'AGUARDANDO_PECAS'     => ['wait',   'Aguardando peças'],
        'PRONTO'               => ['ready',  'Pronto p/ retirada'],
        'FINALIZADO'           => ['done',   'Finalizado'],
        'CANCELADO'            => ['cancel', 'Cancelado'],
        default                => ['done',   '—'],
    };
}
function status_veiculo(?string $statusProtocoloAtivo): array {
    return match ($statusProtocoloAtivo) {
        'ABERTO'               => ['open',  'Aguardando'],
        'AGUARDANDO_APROVACAO' => ['wait',  'Aguardando aprovação'],
        'EM_ATENDIMENTO'       => ['prog',  'Em serviço'],
        'AGUARDANDO_PECAS'     => ['wait',  'Aguardando peças'],
        'PRONTO'               => ['ready', 'Pronto p/ retirada'],
        default                => ['done',  'Sem pendências'],
    };
}
function badge(array $st): string { return '<span class="badge ' . e($st[0]) . '">' . e($st[1]) . '</span>'; }

// Status do protocolo ativo de cada veículo (alias "v"): SQL reutilizável.
const SQL_STATUS_ATIVO = "(SELECT p.status FROM protocolo p WHERE p.idVeiculo = v.id
                           AND p.status IN " . SQL_ATIVOS . " ORDER BY p.numero DESC LIMIT 1)";
// Número do protocolo ativo de cada veículo (alias "v").
const SQL_PROTOCOLO_ATIVO = "(SELECT p.numero FROM protocolo p WHERE p.idVeiculo = v.id
                              AND p.status IN " . SQL_ATIVOS . " ORDER BY p.numero DESC LIMIT 1)";

// Previsão de entrega vencida em um protocolo ainda ativo.
function atrasado(?string $previsao, ?string $status): bool {
    return $previsao && in_array($status, STATUS_ATIVOS, true) && $previsao < date('Y-m-d');
}
function fmt_km($km): string { return $km === null || $km === '' ? '—' : number_format((int)$km, 0, ',', '.') . ' km'; }

// ─── REGRAS DE NEGÓCIO COMPARTILHADAS ────────────────────────────────────────
// Marcas oferecidas no cadastro de veículo: as 10 com mais carros em circulação no Brasil.
// Qualquer outra pode ser digitada na opção "Outra marca" (fica gravada na tabela marca).
const MARCAS_POPULARES = ['Chevrolet', 'Fiat', 'Ford', 'Honda', 'Hyundai',
                          'Jeep', 'Nissan', 'Renault', 'Toyota', 'Volkswagen'];
const MARCA_OUTRA = '__outra';

// Lê marca, modelo e ano do formulário de veículo (cliente e colaborador).
// Retorna ['idModelo' => ..., 'ano' => ...] ou ['erro' => mensagem].
function dados_veiculo_do_formulario(): array {
    $outra  = post('marca') === MARCA_OUTRA;
    $marca  = $outra ? post('marcaOutra') : post('marca');
    $modelo = post('modelo');
    $ano    = (int)post('ano');
    if ($marca === '' || $modelo === '' || !$ano) return ['erro' => 'Preencha todos os campos.'];
    if (!$outra && !in_array($marca, MARCAS_POPULARES, true)) return ['erro' => 'Marca inválida.'];
    if (mb_strlen($marca) > 100)  return ['erro' => 'A marca deve ter no máximo 100 caracteres.'];
    if (mb_strlen($modelo) > 100) return ['erro' => 'O modelo deve ter no máximo 100 caracteres.'];
    if ($ano < 1900 || $ano > (int)date('Y') + 1) return ['erro' => 'Ano inválido.'];
    return ['idModelo' => obter_modelo(obter_marca($marca), $modelo), 'ano' => $ano];
}

// Campos Marca (lista + "Outra marca", que abre um campo de texto) e Modelo do formulário de veículo.
// O JavaScript que alterna o campo de texto está em assets/js/app.js (preencherMarca).
function campos_marca_modelo_veiculo(): void {
    ?>
      <div class="field-row">
        <div class="field">
          <label for="f-marca">Marca</label>
          <select name="marca" id="f-marca" required>
            <option value="">Selecione</option>
            <?php foreach (MARCAS_POPULARES as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
            <option value="<?= MARCA_OUTRA ?>">Outra marca (digitar)</option>
          </select>
          <input type="text" name="marcaOutra" id="f-marca-outra" placeholder="Digite a marca" maxlength="100"
                 aria-label="Nome da marca" hidden disabled/>
        </div>
        <div class="field">
          <label for="f-modelo">Modelo</label>
          <input type="text" name="modelo" id="f-modelo" placeholder="Ex: Corolla" maxlength="100" required/>
        </div>
      </div>
    <?php
}
// Retorna o id da marca com este nome (sem diferenciar maiúsculas), criando-a se ainda não existir.
function obter_marca(string $nome): string {
    $nome = mb_substr(trim($nome), 0, 100);
    $id = db_val('SELECT id FROM marca WHERE nome = ?', [$nome]);
    if ($id) return $id;
    $id = uuid();
    db_exec('INSERT INTO marca (id, nome) VALUES (?,?)', [$id, $nome]);
    return $id;
}
// Retorna o id do modelo (da marca informada), criando-o se ainda não existir.
function obter_modelo(string $idMarca, string $nomeModelo): string {
    $nomeModelo = trim($nomeModelo);
    $id = db_val('SELECT id FROM modelo WHERE idMarca = ? AND nome = ?', [$idMarca, $nomeModelo]);
    if ($id) return $id;
    $id = uuid();
    db_exec('INSERT INTO modelo (id, nome, idMarca) VALUES (?,?,?)', [$id, $nomeModelo, $idMarca]);
    return $id;
}

// Muda o status do protocolo, mantém a ocorrência vinculada em sincronia,
// registra a etapa na linha do tempo e avisa o cliente.
function mudar_status_protocolo(int $numero, string $novo, ?string $idColaborador = null): void {
    $statusOcorrencia = match ($novo) {
        'ABERTO'     => 'ABERTA',
        'FINALIZADO' => 'FECHADA',
        'CANCELADO'  => 'CANCELADA',
        default      => 'EM_ANDAMENTO',
    };
    $final = in_array($novo, ['FINALIZADO', 'CANCELADO'], true) ? date('Y-m-d') : null;
    db_exec('UPDATE protocolo SET status = ?, dataFinalizacao = ? WHERE numero = ?', [$novo, $final, $numero]);
    db_exec('UPDATE ocorrencia o JOIN protocolo p ON p.idOcorrencia = o.id SET o.status = ? WHERE p.numero = ?',
            [$statusOcorrencia, $numero]);
    registrar_etapa($numero, $novo, $idColaborador);

    $info = db_one('SELECT v.placa, v.idCliente FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo WHERE p.numero = ?', [$numero]);
    if ($info) {
        $msg = match ($novo) {
            'AGUARDANDO_APROVACAO' => 'A oficina aguarda a sua aprovação para continuar o serviço.',
            'EM_ATENDIMENTO'       => 'O serviço no seu veículo foi iniciado.',
            'AGUARDANDO_PECAS'     => 'O serviço está aguardando a chegada de peças.',
            'PRONTO'               => 'Seu veículo está pronto para retirada!',
            'FINALIZADO'           => 'Atendimento concluído. Conte para nós como foi: avalie o atendimento.',
            'CANCELADO'            => 'O atendimento foi cancelado.',
            default                => 'O status do atendimento foi atualizado.',
        };
        $link = $novo === 'FINALIZADO' ? 'cliente/historico.php?numero=' . $numero . '#p' . $numero
                                       : 'cliente/visualizar_ocorrencias.php?numero=' . $numero;
        notificar_cliente($info['idCliente'], $info['placa'] . ' · ' . status_protocolo($novo)[1], $msg, $link);
    }
}

// Registra uma etapa na linha do tempo do protocolo.
function registrar_etapa(int $numero, string $status, ?string $idColaborador = null): void {
    db_exec('INSERT INTO protocolo_status (numeroProtocolo, status, idColaborador) VALUES (?,?,?)',
            [$numero, $status, $idColaborador]);
}

// Etapas registradas de um protocolo, em ordem cronológica.
function etapas_protocolo(int $numero): array {
    return db_all('SELECT s.status, s.dataHora, c.nome AS colaborador
                   FROM protocolo_status s LEFT JOIN colaborador c ON c.id = s.idColaborador
                   WHERE s.numeroProtocolo = ? ORDER BY s.dataHora, s.id', [$numero]);
}

// ─── NOTIFICAÇÕES ────────────────────────────────────────────────────────────
// O link é gravado a partir da raiz do projeto (ex.: "cliente/historico.php").
function notificar_cliente(string $idCliente, string $titulo, string $mensagem, ?string $link = null): void {
    db_exec('INSERT INTO notificacao (idCliente, titulo, mensagem, link) VALUES (?,?,?,?)',
            [$idCliente, mb_substr($titulo, 0, 100), mb_substr($mensagem, 0, 255), $link]);
}
function notificar_colaborador(string $idColaborador, string $titulo, string $mensagem, ?string $link = null): void {
    db_exec('INSERT INTO notificacao (idColaborador, titulo, mensagem, link) VALUES (?,?,?,?)',
            [$idColaborador, mb_substr($titulo, 0, 100), mb_substr($mensagem, 0, 255), $link]);
}
// Avisa todos os administradores (exceto quem estiver em $exceto, para não duplicar).
function notificar_admins(string $titulo, string $mensagem, ?string $link = null, array $exceto = []): void {
    foreach (db_all("SELECT id FROM colaborador WHERE perfil = 'ADMIN'") as $a) {
        if (!in_array($a['id'], $exceto, true)) notificar_colaborador($a['id'], $titulo, $mensagem, $link);
    }
}
// Coluna de destinatário conforme o perfil do usuário logado.
function coluna_notificacao(array $u): string { return $u['perfil'] === 'CLIENTE' ? 'idCliente' : 'idColaborador'; }
function notificacoes_usuario(array $u, int $limite = 12): array {
    return db_all('SELECT id, titulo, mensagem, link, lida, dataHora FROM notificacao
                   WHERE ' . coluna_notificacao($u) . " = ? ORDER BY id DESC LIMIT $limite", [$u['id']]);
}
function notificacoes_nao_lidas(array $u): int {
    return (int)db_val('SELECT COUNT(*) FROM notificacao WHERE ' . coluna_notificacao($u) . ' = ? AND lida = 0', [$u['id']]);
}

// ─── REVISÃO PREVENTIVA ──────────────────────────────────────────────────────
// Veículos sem atendimento ativo cujo último serviço finalizado foi há mais de
// MESES_REVISAO meses. Com $idCliente, restringe aos veículos daquele cliente.
const MESES_REVISAO = 6;
function veiculos_para_revisao(?string $idCliente = null, int $limite = 20): array {
    $params = [];
    $filtro = '';
    if ($idCliente !== null) { $filtro = ' AND v.idCliente = ?'; $params[] = $idCliente; }
    $params[] = MESES_REVISAO;
    return db_all("SELECT v.id, v.placa, mo.nome AS modelo, ma.nome AS marca, c.nome AS cliente, c.telefone,
                          MAX(p.dataFinalizacao) AS ultimo
                   FROM veiculo v
                   JOIN modelo mo   ON mo.id = v.idModelo
                   JOIN marca ma    ON ma.id = mo.idMarca
                   JOIN cliente c   ON c.id = v.idCliente
                   JOIN protocolo p ON p.idVeiculo = v.id AND p.status = 'FINALIZADO'
                   WHERE NOT EXISTS (SELECT 1 FROM protocolo a WHERE a.idVeiculo = v.id AND a.status IN " . SQL_ATIVOS . ")$filtro
                   GROUP BY v.id, v.placa, mo.nome, ma.nome, c.nome, c.telefone
                   HAVING ultimo < CURDATE() - INTERVAL ? MONTH
                   ORDER BY ultimo LIMIT $limite", $params);
}
function meses_desde(string $data): int {
    $d = (new DateTime($data))->diff(new DateTime('today'));
    return $d->y * 12 + $d->m;
}

// ─── FOTOS ───────────────────────────────────────────────────────────────────
const PASTA_FOTOS = 'uploads/fotos/';          // a partir da raiz do projeto
const TAMANHO_MAX_FOTO = 8 * 1024 * 1024;      // 8 MB por arquivo
const MAX_FOTOS_POR_ENVIO = 10;
// As fotos não são servidas direto da pasta: passam por comum/foto.php, que confere a permissão.
function foto_url(string $arquivo, string $raiz = '../'): string {
    return $raiz . 'comum/foto.php?f=' . rawurlencode($arquivo);
}
// Arquivos de foto dos veículos que atendem ao filtro (alias "v"). Usado antes de
// excluir cliente/veículo: os registros saem pelo CASCADE, mas os arquivos ficariam no disco.
function arquivos_fotos(string $filtro, array $params): array {
    return array_column(db_all("SELECT f.arquivo FROM foto f JOIN protocolo p ON p.numero = f.numeroProtocolo
                                JOIN veiculo v ON v.id = p.idVeiculo WHERE $filtro", $params), 'arquivo');
}
function apagar_arquivos_fotos(array $arquivos): void {
    foreach ($arquivos as $a) @unlink(__DIR__ . '/../' . PASTA_FOTOS . basename($a));
}
function fotos_protocolo(int $numero): array {
    return db_all('SELECT f.id, f.arquivo, f.legenda, f.dataHora, c.nome AS colaborador
                   FROM foto f LEFT JOIN colaborador c ON c.id = f.idColaborador
                   WHERE f.numeroProtocolo = ? ORDER BY f.dataHora, f.id', [$numero]);
}

// ─── REQUISIÇÃO / FLASH / CSRF ───────────────────────────────────────────────
function redirecionar(string $url): never { header('Location: ' . $url); exit; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
// Valores de formulário sempre como texto (um array enviado no lugar vira '').
function post(string $k): string { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : ''; }
// Senhas: sem trim, para não alterar o que o usuário digitou.
function post_senha(string $k): string { return is_string($_POST[$k] ?? null) ? $_POST[$k] : ''; }
function get(string $k): string { return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : ''; }
function acao(): string { return is_post() ? post('_action') : ''; }

function flash(string $tipo, string $msg): void { $_SESSION['flash'] = [$tipo, $msg]; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '"/>'; }
function verificar_csrf(): void {
    if (is_post() && !hash_equals(csrf_token(), post('_csrf'))) {
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
// Também revalida o usuário no banco a cada requisição e impede que páginas
// com dados pessoais fiquem guardadas no cache do navegador.
function exigir_login(array $perfis): array {
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    $u = revalidar_usuario();
    if (!$u) redirecionar('../login.php');
    if (!in_array($u['perfil'], $perfis, true)) redirecionar('../' . home_do_perfil($u['perfil']));
    verificar_csrf();
    return $u;
}
