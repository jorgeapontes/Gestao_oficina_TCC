<?php
// ─── COMPONENTES DE LAYOUT ───────────────────────────────────────────────────
// Sidebar única: o menu muda conforme o perfil do usuário logado.

const ICONES = [
    'dashboard'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'clientes'     => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
    'veiculos'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-4 0v2M8 7V5a2 2 0 00-4 0v2"/><circle cx="12" cy="14" r="2"/>',
    'ocorrencias'  => '<path d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
    'colaboradores'=> '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
    'historico'    => '<path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
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
            'Registros' => [['historico', 'Histórico', 'admin/historico.php']],
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

function sidebar(string $ativo): void {
    $u = usuario();
    ?>
<aside>
  <div class="logo">
    <div class="logo-mark">IMM</div>
    <div class="logo-name">Integrated Mechanical<br>Management</div>
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
    <a class="nav-item" href="../logout.php" style="margin-top:12px">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      Sair
    </a>
  </div>
</aside>
    <?php
}

// Mensagem de retorno (sucesso/erro) gravada com flash() antes do redirect.
function flash_html(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return;
    [$tipo, $texto] = $f;
    ?>
<style>
  .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999; padding: .75rem 1.25rem; border-radius: 8px;
           font-size: .875rem; font-weight: 500; box-shadow: 0 4px 20px rgba(0,0,0,.18); display: flex; align-items: center;
           gap: .5rem; animation: toastIn .25s ease; transition: opacity .4s; max-width: 420px; }
  .toast.ok  { background: #1a7f4b; color: #fff; }
  .toast.err { background: #c0392b; color: #fff; }
  @keyframes toastIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
</style>
<div class="toast <?= e($tipo) ?>" id="toast" onclick="this.remove()">
  <?php if ($tipo === 'ok'): ?>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
  <?php else: ?>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
  <?php endif; ?>
  <?= e($texto) ?>
</div>
<script>setTimeout(() => { const t = document.getElementById('toast'); if (t) t.style.opacity = 0; }, 4000);</script>
    <?php
}
