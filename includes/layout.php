<?php
// ─── COMPONENTES DE LAYOUT ───────────────────────────────────────────────────
// <head> comum, sidebar (menu muda conforme o perfil), notificações e mensagens.

const ICONES = [
    'dashboard'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'clientes'     => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
    'veiculos'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-4 0v2M8 7V5a2 2 0 00-4 0v2"/><circle cx="12" cy="14" r="2"/>',
    'ocorrencias'  => '<path d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
    'colaboradores'=> '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
    'historico'    => '<path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    'relatorios'   => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'perfil'       => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
];

// Menu de cada perfil: [seção => [[chave, rótulo, caminho a partir da raiz], ...]]
function menu_do_perfil(string $perfil): array {
    return match ($perfil) {
        'ADMIN' => [
            'Principal' => [
                ['dashboard',     'Dashboard',     'admin/dashboard.php'],
                ['clientes',      'Clientes',      'colaborador/visualizar_clientes.php'],
                ['veiculos',      'Veículos',      'colaborador/veiculos.php'],
                ['ocorrencias',   'Ocorrências',   'colaborador/registrar_ocorrencia.php'],
                ['colaboradores', 'Colaboradores', 'admin/gerenciar_colaboradores.php'],
            ],
            'Registros' => [
                ['historico',  'Histórico',  'admin/historico.php'],
                ['relatorios', 'Relatórios', 'admin/relatorios.php'],
            ],
        ],
        'COLABORADOR' => [
            'Principal' => [
                ['dashboard',   'Meu Painel',  'colaborador/dashboard.php'],
                ['ocorrencias', 'Ocorrências', 'colaborador/registrar_ocorrencia.php'],
                ['clientes',    'Clientes',    'colaborador/visualizar_clientes.php'],
                ['veiculos',    'Veículos',    'colaborador/veiculos.php'],
            ],
            'Registros' => [['historico', 'Histórico', 'admin/historico.php']],
        ],
        default => [
            'Principal' => [
                ['dashboard',   'Meu Painel',    'cliente/dashboard.php'],
                ['veiculos',    'Meus Veículos', 'cliente/meus_veiculos.php'],
                ['ocorrencias', 'Ocorrências',   'cliente/visualizar_ocorrencias.php'],
            ],
            'Registros' => [['historico', 'Histórico', 'cliente/historico.php']],
            'Conta'     => [['perfil', 'Meu Perfil', 'cliente/perfil.php']],
        ],
    };
}

function rotulo_perfil(array $u): string {
    return match ($u['perfil']) {
        'ADMIN'       => 'Administrador',
        'COLABORADOR' => ($u['cargo'] ? $u['cargo'] . ' · ' : '') . 'Colaborador',
        default       => 'Cliente',
    };
}

// URL de um arquivo estático com a data de modificação, para o navegador
// baixar de novo sempre que o arquivo mudar.
function asset(string $caminho, string $raiz): string {
    $arquivo = __DIR__ . '/../' . $caminho;
    return $raiz . $caminho . (is_file($arquivo) ? '?v=' . filemtime($arquivo) : '');
}

// Conteúdo comum do <head>: metas, fontes, CSS/JS do sistema e o tema salvo.
// O script do tema roda antes da página aparecer, evitando "piscar" no modo escuro.
function head_comum(string $titulo, string $raiz = '../'): void {
    ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="theme-color" content="#F5F4F2" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#141413" media="(prefers-color-scheme: dark)"/>
  <title>IMM – <?= e($titulo) ?></title>
  <script>
    try { const t = localStorage.getItem('imm-tema'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css', $raiz)) ?>"/>
  <script src="<?= e(asset('assets/js/app.js', $raiz)) ?>"></script>
    <?php
}

function icone_sino(): string {
    return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>';
}

function sidebar(string $ativo): void {
    $u = usuario();
    $naoLidas = notificacoes_nao_lidas($u);
    $notifs   = notificacoes_usuario($u);
    $sino = icone_sino() . ($naoLidas ? '<span class="notif-count">' . ($naoLidas > 9 ? '9+' : $naoLidas) . '</span>' : '');
    ?>
<header class="mobile-bar">
  <button type="button" class="icon-btn" data-sidebar-toggle aria-label="Abrir menu">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
  </button>
  <div class="mobile-brand">IMM</div>
  <button type="button" class="icon-btn notif-trigger" data-notif-toggle aria-label="Notificações"><?= $sino ?></button>
</header>
<div class="sidebar-overlay" data-sidebar-close></div>

<aside id="sidebar">
  <div class="logo">
    <div>
      <div class="logo-mark">IMM</div>
      <div class="logo-name">Integrated Mechanical<br>Management</div>
    </div>
    <button type="button" class="icon-btn notif-trigger hide-mobile" data-notif-toggle aria-label="Notificações"><?= $sino ?></button>
    <button type="button" class="icon-btn show-mobile" data-sidebar-close aria-label="Fechar menu">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <nav>
    <?php foreach (menu_do_perfil($u['perfil']) as $secao => $itens): ?>
      <span class="nav-label"><?= e($secao) ?></span>
      <?php foreach ($itens as [$chave, $rotulo, $caminho]): ?>
        <a class="nav-item<?= $chave === $ativo ? ' active' : '' ?>" href="../<?= $caminho ?>">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= ICONES[$chave] ?></svg>
          <?= e($rotulo) ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?= e(initials($u['nome'])) ?></div>
      <div class="user-info">
        <div class="user-name"><?= e($u['nome']) ?></div>
        <div class="user-role"><?= e(rotulo_perfil($u)) ?></div>
      </div>
    </div>
    <button type="button" class="nav-item theme-toggle" data-theme-toggle style="margin-top:12px">
      <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
      <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
      <span class="theme-label">Modo escuro</span>
    </button>
    <a class="nav-item" href="../logout.php">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      Sair
    </a>
  </div>
</aside>

<div class="notif-panel" id="notif-panel" role="dialog" aria-label="Notificações">
  <div class="notif-head">
    <span>Notificações</span>
    <?php if ($naoLidas): ?>
      <form method="POST" action="../comum/notificacao.php">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="ler_todas"/>
        <button type="submit" class="link-btn">Marcar todas como lidas</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="notif-list">
    <?php if (!$notifs): ?>
      <div class="notif-empty">Nenhuma notificação por enquanto.</div>
    <?php endif; ?>
    <?php foreach ($notifs as $n): ?>
      <a class="notif-item<?= $n['lida'] ? '' : ' unread' ?>" href="../comum/notificacao.php?id=<?= (int)$n['id'] ?>">
        <div class="notif-title"><?= e($n['titulo']) ?></div>
        <div class="notif-msg"><?= e($n['mensagem']) ?></div>
        <div class="notif-time"><?= tempo_relativo($n['dataHora']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
    <?php
}

// Linha do tempo de status de um protocolo (etapas registradas).
function linha_do_tempo(array $etapas, ?string $previsao = null): void {
    ?>
<ol class="timeline-steps">
  <?php foreach ($etapas as $i => $et): $st = status_protocolo($et['status']); $ultima = $i === count($etapas) - 1; ?>
    <li class="step <?= e($st[0]) ?><?= $ultima ? ' current' : '' ?>">
      <span class="step-dot"></span>
      <div class="step-body">
        <div class="step-title"><?= e($st[1]) ?></div>
        <div class="step-meta"><?= fmt_data_hora($et['dataHora']) ?><?= $et['colaborador'] ? ' · ' . e($et['colaborador']) : '' ?></div>
      </div>
    </li>
  <?php endforeach; ?>
  <?php if ($previsao && $etapas && in_array(end($etapas)['status'], STATUS_ATIVOS, true)): ?>
    <li class="step future">
      <span class="step-dot"></span>
      <div class="step-body">
        <div class="step-title">Previsão de entrega</div>
        <div class="step-meta"><?= fmt_data($previsao) ?><?= $previsao < date('Y-m-d') ? ' · <span class="text-danger">atrasado</span>' : '' ?></div>
      </div>
    </li>
  <?php endif; ?>
</ol>
    <?php
}

// Estrelas (somente leitura) de 1 a 5.
function estrelas(int $nota): string {
    $html = '<span class="stars" title="' . $nota . ' de 5">';
    for ($i = 1; $i <= 5; $i++) $html .= '<span class="' . ($i <= $nota ? 'on' : '') . '">★</span>';
    return $html . '</span>';
}

// Mensagem de retorno (sucesso/erro) gravada com flash() antes do redirect.
function flash_html(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return;
    [$tipo, $texto] = $f;
    ?>
<div class="toast <?= e($tipo) ?>" id="toast" role="status" onclick="this.remove()">
  <?php if ($tipo === 'ok'): ?>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
  <?php else: ?>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
  <?php endif; ?>
  <?= e($texto) ?>
</div>
    <?php
}
