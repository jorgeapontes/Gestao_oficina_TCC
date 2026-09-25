<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

// ─── CRUD ────────────────────────────────────────────────────────────────────
if (in_array(acao(), ['criar', 'editar'], true)) {
    $id       = post('id');
    $nome     = post('nome');
    $cpf      = so_digitos(post('cpf'));
    $email    = post('email');
    $telefone = so_digitos(post('telefone'));
    $endereco = post('endereco');
    $senha    = $_POST['senha'] ?? '';

    if (!$nome || !$cpf || !$email || !$endereco || (acao() === 'criar' && $senha === '')) {
        flash('err', 'Preencha todos os campos obrigatórios.');
    } elseif (strlen($cpf) !== 11) {
        flash('err', 'CPF inválido: informe os 11 dígitos.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('err', 'E-mail inválido.');
    } elseif ($senha !== '' && strlen($senha) < 8) {
        flash('err', 'A senha deve ter ao menos 8 caracteres.');
    } elseif (db_val('SELECT 1 FROM colaborador WHERE email = ?', [$email])) {
        flash('err', 'Este e-mail já pertence a um colaborador.');
    } else {
        try {
            if (acao() === 'criar') {
                db_exec('INSERT INTO cliente (id, nome, cpf, email, senha, telefone, endereco) VALUES (?,?,?,?,?,?,?)',
                        [uuid(), $nome, $cpf, $email, password_hash($senha, PASSWORD_DEFAULT), $telefone, $endereco]);
                flash('ok', 'Cliente cadastrado com sucesso!');
            } else {
                db_exec('UPDATE cliente SET nome=?, cpf=?, email=?, telefone=?, endereco=? WHERE id=?',
                        [$nome, $cpf, $email, $telefone, $endereco, $id]);
                if ($senha !== '') {
                    db_exec('UPDATE cliente SET senha=? WHERE id=?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
                }
                flash('ok', 'Cliente atualizado.');
            }
        } catch (mysqli_sql_exception $ex) {
            flash('err', erro_db($ex));
        }
    }
    redirecionar('visualizar_clientes.php');
}

// Remover cliente apaga veículos, ocorrências e protocolos dele (CASCADE): só admin.
if (acao() === 'excluir' && e_admin()) {
    db_exec('DELETE FROM cliente WHERE id = ?', [post('id')]);
    flash('ok', 'Cliente removido.');
    redirecionar('visualizar_clientes.php');
}

// ─── LEITURA ─────────────────────────────────────────────────────────────────
$clientes = db_all("SELECT c.id, c.nome, c.cpf, c.email, c.telefone, c.endereco,
                           (SELECT COUNT(*) FROM veiculo v WHERE v.idCliente = c.id) AS veiculos,
                           (SELECT COUNT(*) FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                            WHERE v.idCliente = c.id AND p.status IN ('ABERTO','EM_ATENDIMENTO')) AS ativos,
                           (SELECT MAX(p.dataFinalizacao) FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                            WHERE v.idCliente = c.id AND p.status = 'FINALIZADO') AS ultimo
                    FROM cliente c ORDER BY c.nome");
$busca = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Clientes</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #F5F4F2; --surface: #FFFFFF; --border: #E4E2DE;
      --text: #1A1917; --muted: #7A7872; --sidebar-w: 230px;
      --tag-open: #E8F2EC; --tag-open-t: #2D7A4F;
      --tag-prog: #FEF4E4; --tag-prog-t: #A05A00;
      --tag-done: #EDECEA; --tag-done-t: #5A5750;
    }
    body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }
    aside { width: var(--sidebar-w); min-height: 100vh; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 28px 0; position: fixed; top: 0; left: 0; }
    .logo { padding: 0 24px 28px; border-bottom: 1px solid var(--border); }
    .logo-mark { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; letter-spacing: 0.08em; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
    .logo-name { font-size: 15px; font-weight: 600; line-height: 1.3; }
    nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 2px; }
    .nav-label { font-size: 10px; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); padding: 14px 12px 6px; }
    .nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; font-size: 14px; color: var(--muted); cursor: pointer; text-decoration: none; transition: background 0.15s, color 0.15s; }
    .nav-item:hover { background: var(--bg); color: var(--text); }
    .nav-item.active { background: var(--text); color: #fff; }
    .nav-item .icon { width: 18px; height: 18px; opacity: 0.7; flex-shrink: 0; }
    .nav-item.active .icon { opacity: 1; }
    .sidebar-footer { padding: 20px 24px 0; border-top: 1px solid var(--border); }
    .user-chip { display: flex; align-items: center; gap: 10px; }
    .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--text); color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .user-name { font-size: 13px; font-weight: 500; }
    .user-role { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }
    main { margin-left: var(--sidebar-w); flex: 1; padding: 40px 48px; }
    .topbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-bottom: 32px; }
    .search-wrap { position: relative; flex: 1; max-width: 360px; }
    .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
    .search-input { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 9px 12px 9px 36px; font-size: 13.5px; font-family: inherit; background: var(--surface); color: var(--text); outline: none; transition: border-color 0.15s; }
    .search-input::placeholder { color: var(--muted); }
    .search-input:focus { border-color: var(--text); }
    .notif-btn { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }

    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }

    .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
    .table-toolbar { padding: 14px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
    .filter-btn { padding: 6px 12px; border: 1px solid var(--border); border-radius: 7px; background: transparent; font-family: inherit; font-size: 12.5px; font-weight: 500; color: var(--muted); cursor: pointer; transition: all 0.15s; }
    .filter-btn.active, .filter-btn:hover { background: var(--text); color: #fff; border-color: var(--text); }
    .table-count { margin-left: auto; font-size: 12px; color: var(--muted); font-family: 'DM Mono', monospace; }

    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 11px; font-weight: 500; color: var(--muted); letter-spacing: 0.06em; text-transform: uppercase; padding: 10px 22px; border-bottom: 1px solid var(--border); }
    tbody tr { transition: background 0.1s; cursor: pointer; }
    tbody tr:hover { background: var(--bg); }
    tbody td { padding: 13px 22px; font-size: 13.5px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }

    .client-chip { display: flex; align-items: center; gap: 10px; }
    .c-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--text); color: #fff; font-size: 11px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .c-name { font-size: 13.5px; font-weight: 500; }
    .c-email { font-size: 12px; color: var(--muted); }

    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.open { background: var(--tag-open); color: var(--tag-open-t); }
    .badge.prog { background: var(--tag-prog); color: var(--tag-prog-t); }
    .badge.done { background: var(--tag-done); color: var(--tag-done-t); }

    .td-mono { font-family: 'DM Mono', monospace; font-size: 12.5px; }
    .row-actions { display: flex; gap: 6px; opacity: 0; transition: opacity 0.15s; }
    tbody tr:hover .row-actions { opacity: 1; }
    .act-btn { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); font-family: inherit; font-size: 12px; cursor: pointer; color: var(--muted); }
    .act-btn:hover { border-color: var(--text); color: var(--text); }

    .pagination { padding: 14px 22px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .page-info { font-size: 12.5px; color: var(--muted); }
    .page-btns { display: flex; gap: 4px; }
    .page-btn { width: 30px; height: 30px; border: 1px solid var(--border); border-radius: 7px; display: flex; align-items: center; justify-content: center; background: transparent; cursor: pointer; font-family: inherit; font-size: 13px; color: var(--muted); }
    .page-btn.current { background: var(--text); color: #fff; border-color: var(--text); }
    .page-btn:hover:not(.current) { border-color: var(--text); color: var(--text); }
  </style>
</head>
<body>
<style>
  .act-btn { text-decoration: none; display: inline-block; }
  .act-btn.danger:hover { border-color: #C0392B; color: #C0392B; }
  .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--text); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 13.5px; font-weight: 500; cursor: pointer; }
  .btn-primary:hover { opacity: .85; }
  .empty-row td { text-align: center; color: var(--muted); padding: 40px 22px; }
  /* MODAL */
  .modal-backdrop { position: fixed; inset: 0; background: rgba(26,25,23,0.35); display: flex; align-items: center; justify-content: center; z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
  .modal-backdrop.open { opacity: 1; pointer-events: all; }
  .modal { background: var(--surface); border-radius: 14px; width: 100%; max-width: 480px; padding: 28px 32px; }
  .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
  .modal-title { font-size: 16px; font-weight: 600; }
  .modal-close { border: none; background: none; font-size: 20px; color: var(--muted); cursor: pointer; line-height: 1; padding: 2px 6px; border-radius: 6px; }
  .field { margin-bottom: 16px; }
  .field label { display: block; font-size: 12.5px; font-weight: 500; color: var(--muted); margin-bottom: 6px; }
  .field input { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; font-size: 14px; font-family: inherit; background: var(--surface); color: var(--text); outline: none; }
  .field input:focus { border-color: var(--text); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .modal-footer { display: flex; gap: 10px; margin-top: 8px; }
  .btn-cancel { flex: 1; padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; background: transparent; font-family: inherit; font-size: 14px; font-weight: 500; color: var(--muted); cursor: pointer; }
  .btn-confirm { flex: 2; padding: 10px 16px; background: var(--text); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 14px; font-weight: 500; cursor: pointer; }
</style>

<?php sidebar('clientes'); ?>

<main>
  <div class="topbar">
    <div class="search-wrap">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" value="<?= e($busca) ?>" placeholder="Buscar por nome, CPF ou e-mail..." oninput="filterTable(this.value)"/>
    </div>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Clientes</div>
      <div class="page-sub">Lista de todos os clientes cadastrados no sistema.</div>
    </div>
    <button class="btn-primary" onclick="openModal()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo cliente
    </button>
  </div>

  <div class="table-card">
    <div class="table-toolbar">
      <button class="filter-btn active" onclick="setFilter('all',this)">Todos</button>
      <button class="filter-btn" onclick="setFilter('ativo',this)">Com veículos</button>
      <button class="filter-btn" onclick="setFilter('sem',this)">Sem veículos</button>
      <span class="table-count" id="count"><?= plural(count($clientes), 'cliente', 'clientes') ?></span>
    </div>

    <table id="clients-table">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>CPF</th>
          <th>Telefone</th>
          <th>Veículos</th>
          <th>Último atendimento</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="table-body">
        <?php if (!$clientes): ?>
          <tr class="empty-row"><td colspan="6">Nenhum cliente cadastrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($clientes as $c):
          $nv = (int)$c['veiculos'];
          $badgeCls = $nv === 0 ? 'done' : ((int)$c['ativos'] > 0 ? 'prog' : 'open');
        ?>
        <tr data-filter="<?= $nv ? 'ativo' : 'sem' ?>">
          <td><div class="client-chip"><div class="c-avatar"><?= e(initials($c['nome'])) ?></div><div><div class="c-name"><?= e($c['nome']) ?></div><div class="c-email"><?= e($c['email']) ?></div></div></div></td>
          <td class="td-mono"><?= e(fmt_cpf($c['cpf'])) ?></td>
          <td><?= e(fmt_tel($c['telefone'])) ?></td>
          <td><span class="badge <?= $badgeCls ?>"><?= $nv ? plural($nv, 'veículo', 'veículos') : 'Nenhum' ?></span></td>
          <td class="td-mono"><?= fmt_data($c['ultimo']) ?></td>
          <td><div class="row-actions">
            <a class="act-btn" href="veiculos.php?cliente=<?= urlencode($c['id']) ?>">Ver veículos</a>
            <a class="act-btn" href="veiculos.php?novo=1&amp;cliente=<?= urlencode($c['id']) ?>">+ Veículo</a>
            <button class="act-btn" onclick='openModal(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
            <?php if (e_admin()): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Remover <?= e(addslashes($c['nome'])) ?>? Veículos, ocorrências e protocolos do cliente também serão apagados.')">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="excluir"/>
                <input type="hidden" name="id" value="<?= e($c['id']) ?>"/>
                <button class="act-btn danger" type="submit">Remover</button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- MODAL -->
<div class="modal-backdrop" id="modal" onclick="if (event.target === this) closeModal()">
  <form class="modal" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="f-action" value="criar"/>
    <input type="hidden" name="id" id="f-id" value=""/>
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Novo cliente</div>
      <button type="button" class="modal-close" onclick="closeModal()">×</button>
    </div>
    <div class="field">
      <label>Nome completo *</label>
      <input type="text" name="nome" id="f-nome" maxlength="100" required/>
    </div>
    <div class="field-row">
      <div class="field">
        <label>CPF *</label>
        <input type="text" name="cpf" id="f-cpf" placeholder="000.000.000-00" maxlength="14" required/>
      </div>
      <div class="field">
        <label>Telefone</label>
        <input type="tel" name="telefone" id="f-tel" placeholder="(00) 00000-0000" maxlength="15"/>
      </div>
    </div>
    <div class="field">
      <label>E-mail *</label>
      <input type="email" name="email" id="f-email" maxlength="100" required/>
    </div>
    <div class="field">
      <label>Endereço *</label>
      <input type="text" name="endereco" id="f-end" maxlength="100" required/>
    </div>
    <div class="field">
      <label id="f-senha-label">Senha provisória *</label>
      <input type="password" name="senha" id="f-senha" placeholder="Mínimo 8 caracteres" minlength="8"/>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal()">Cancelar</button>
      <button type="submit" class="btn-confirm" id="f-submit">Cadastrar cliente</button>
    </div>
  </form>
</div>

<?php flash_html(); ?>

<script>
  let currentFilter = 'all';
  let searchTerm = '';

  function setFilter(f, btn) {
    currentFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
  }
  function filterTable(val) { searchTerm = val.toLowerCase(); applyFilters(); }
  function applyFilters() {
    const rows = document.querySelectorAll('#table-body tr[data-filter]');
    let visible = 0;
    rows.forEach(r => {
      const matchFilter = currentFilter === 'all' || r.dataset.filter === currentFilter;
      const matchSearch = !searchTerm || r.textContent.toLowerCase().includes(searchTerm);
      const show = matchFilter && matchSearch;
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    document.getElementById('count').textContent = visible + ' cliente' + (visible !== 1 ? 's' : '');
  }

  const maskCpf = v => v.replace(/\D/g,'').substring(0,11).replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2');
  const maskTel = v => { v = v.replace(/\D/g,'').substring(0,11);
    return v.length > 10 ? v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3') : v.length > 6 ? v.replace(/(\d{2})(\d{4})(\d+)/,'($1) $2-$3') : v.length > 2 ? v.replace(/(\d{2})(\d+)/,'($1) $2') : v; };
  document.getElementById('f-cpf').addEventListener('input', e => e.target.value = maskCpf(e.target.value));
  document.getElementById('f-tel').addEventListener('input', e => e.target.value = maskTel(e.target.value));

  function openModal(c) {
    const edit = !!c;
    document.getElementById('modal-title').textContent = edit ? 'Editar cliente' : 'Novo cliente';
    document.getElementById('f-submit').textContent    = edit ? 'Salvar alterações' : 'Cadastrar cliente';
    document.getElementById('f-senha-label').textContent = edit ? 'Nova senha (deixe em branco para manter)' : 'Senha provisória *';
    document.getElementById('f-senha').required = !edit;
    document.getElementById('f-action').value = edit ? 'editar' : 'criar';
    document.getElementById('f-id').value     = edit ? c.id : '';
    document.getElementById('f-nome').value   = edit ? c.nome : '';
    document.getElementById('f-cpf').value    = edit ? maskCpf(c.cpf) : '';
    document.getElementById('f-tel').value    = edit ? maskTel(c.telefone || '') : '';
    document.getElementById('f-email').value  = edit ? c.email : '';
    document.getElementById('f-end').value    = edit ? c.endereco : '';
    document.getElementById('f-senha').value  = '';
    document.getElementById('modal').classList.add('open');
  }
  function closeModal() { document.getElementById('modal').classList.remove('open'); }

  <?php if ($busca !== ''): ?>filterTable(<?= json_encode($busca) ?>);<?php endif; ?>
  <?php if (isset($_GET['novo'])): ?>openModal();<?php endif; ?>
</script>
</body>
</html>
