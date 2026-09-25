<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

// ─── CRUD ────────────────────────────────────────────────────────────────────
if (in_array(acao(), ['criar', 'editar'], true)) {
    $id        = post('id');
    $idCliente = post('idCliente');
    $placa     = normalizar_placa(post('placa'));
    $idMarca   = post('idMarca');
    $modelo    = post('modelo');
    $ano       = (int)post('ano');
    $cor       = post('cor');

    if (!$idCliente || !$placa || !$idMarca || !$modelo || !$cor || !$ano) {
        flash('err', 'Preencha todos os campos.');
    } elseif (!preg_match('/^[A-Z]{3}-?\d[A-Z0-9]\d{2}$/', $placa)) {
        flash('err', 'Placa inválida. Use o formato ABC-1234 ou ABC1D23.');
    } elseif ($ano < 1900 || $ano > (int)date('Y') + 1) {
        flash('err', 'Ano inválido.');
    } else {
        try {
            $idModelo = obter_modelo($idMarca, $modelo);
            if (acao() === 'criar') {
                db_exec('INSERT INTO veiculo (id, placa, ano, cor, idModelo, idCliente) VALUES (?,?,?,?,?,?)',
                        [uuid(), $placa, $ano, mb_substr($cor, 0, 10), $idModelo, $idCliente]);
                flash('ok', "Veículo $placa cadastrado com sucesso!");
            } else {
                db_exec('UPDATE veiculo SET placa=?, ano=?, cor=?, idModelo=?, idCliente=? WHERE id=?',
                        [$placa, $ano, mb_substr($cor, 0, 10), $idModelo, $idCliente, $id]);
                flash('ok', "Veículo $placa atualizado.");
            }
        } catch (mysqli_sql_exception $ex) {
            flash('err', erro_db($ex));
        }
    }
    redirecionar('veiculos.php');
}

// Remover veículo apaga também suas ocorrências e protocolos (ON DELETE CASCADE),
// por isso fica restrito ao administrador.
if (acao() === 'excluir' && e_admin()) {
    db_exec('DELETE FROM veiculo WHERE id = ?', [post('id')]);
    flash('ok', 'Veículo removido.');
    redirecionar('veiculos.php');
}

// ─── LEITURA ─────────────────────────────────────────────────────────────────
$busca     = trim($_GET['q'] ?? '');
$idCliente = $_GET['cliente'] ?? '';
$where = [];
$params = [];
if ($idCliente !== '') { $where[] = 'v.idCliente = ?'; $params[] = $idCliente; }

$veiculos = db_all("SELECT v.id, v.placa, v.ano, v.cor, v.idCliente, mo.nome AS modelo, ma.id AS idMarca, ma.nome AS marca,
                           c.nome AS cliente, c.cpf,
                           " . SQL_STATUS_ATIVO . " AS statusAtivo,
                           (SELECT p.numero FROM protocolo p WHERE p.idVeiculo = v.id
                            AND p.status IN ('ABERTO','EM_ATENDIMENTO') ORDER BY p.numero DESC LIMIT 1) AS protocoloAtivo,
                           (SELECT COUNT(*) FROM itemocorrencia i JOIN protocolo p ON p.idOcorrencia = i.idOcorrencia
                            WHERE p.idVeiculo = v.id AND p.status IN ('ABERTO','EM_ATENDIMENTO')) AS itensAtivos
                    FROM veiculo v
                    JOIN modelo mo ON mo.id = v.idModelo
                    JOIN marca ma  ON ma.id = mo.idMarca
                    JOIN cliente c ON c.id = v.idCliente"
                  . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY v.placa', $params);

$marcas   = db_all('SELECT id, nome FROM marca ORDER BY nome');
$clientes = db_all('SELECT id, nome, cpf FROM cliente ORDER BY nome');
$filtroCliente = $idCliente !== '' ? db_val('SELECT nome FROM cliente WHERE id = ?', [$idCliente]) : null;

$abrirNovo  = isset($_GET['novo']);
$placaNovo  = normalizar_placa($_GET['placa'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Veículos</title>
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

    .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--text); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: opacity 0.15s; }
    .btn-primary:hover { opacity: 0.85; }
    .btn-primary svg { width: 15px; height: 15px; }

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

    .td-plate { font-family: 'DM Mono', monospace; font-size: 12.5px; background: var(--bg); padding: 3px 8px; border-radius: 5px; border: 1px solid var(--border); display: inline-block; }
    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.open { background: var(--tag-open); color: var(--tag-open-t); }
    .badge.prog { background: var(--tag-prog); color: var(--tag-prog-t); }
    .badge.done { background: var(--tag-done); color: var(--tag-done-t); }

    .owner-chip { display: flex; align-items: center; gap: 8px; }
    .o-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--text); color: #fff; font-size: 9px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

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

    /* MODAL */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(26,25,23,0.35); display: flex; align-items: center; justify-content: center; z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .modal-backdrop.open { opacity: 1; pointer-events: all; }
    .modal { background: var(--surface); border-radius: 14px; width: 100%; max-width: 460px; padding: 28px 32px; transform: translateY(10px); transition: transform 0.2s; }
    .modal-backdrop.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .modal-title { font-size: 16px; font-weight: 600; }
    .modal-close { border: none; background: none; font-size: 20px; color: var(--muted); cursor: pointer; line-height: 1; padding: 2px 6px; border-radius: 6px; }
    .modal-close:hover { background: var(--bg); color: var(--text); }
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 12.5px; font-weight: 500; color: var(--muted); margin-bottom: 6px; }
    .field input, .field select { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; font-size: 14px; font-family: inherit; background: var(--surface); color: var(--text); outline: none; transition: border-color 0.15s; -webkit-appearance: none; }
    .field input::placeholder { color: var(--muted); }
    .field input:focus, .field select:focus { border-color: var(--text); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .modal-footer { display: flex; gap: 10px; margin-top: 8px; }
    .btn-cancel { flex: 1; padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; background: transparent; font-family: inherit; font-size: 14px; font-weight: 500; color: var(--muted); cursor: pointer; }
    .btn-cancel:hover { color: var(--text); border-color: var(--text); }
    .btn-confirm { flex: 2; padding: 10px 16px; background: var(--text); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 14px; font-weight: 500; cursor: pointer; }
    .btn-confirm:hover { opacity: 0.85; }
  </style>
</head>
<body>
<style>
  .act-btn { text-decoration: none; display: inline-block; }
  .act-btn.danger:hover { border-color: #C0392B; color: #C0392B; }
  .filter-chip-client { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); margin-left: 6px; }
  .filter-chip-client a { color: var(--text); text-decoration: none; font-weight: 500; }
  .empty-row td { text-align: center; color: var(--muted); padding: 40px 22px; }
</style>

<?php sidebar('veiculos'); ?>

<main>
  <div class="topbar">
    <div class="search-wrap">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" id="search" value="<?= e($busca) ?>" placeholder="Buscar por placa, modelo ou cliente..." oninput="filterTable(this.value)"/>
    </div>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Veículos</div>
      <div class="page-sub">Todos os veículos cadastrados no sistema.</div>
    </div>
    <button class="btn-primary" onclick="openModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo veículo
    </button>
  </div>

  <div class="table-card">
    <div class="table-toolbar">
      <button class="filter-btn active" onclick="setFilter('all',this)">Todos</button>
      <button class="filter-btn" onclick="setFilter('prog',this)">Em serviço</button>
      <button class="filter-btn" onclick="setFilter('open',this)">Aguardando</button>
      <button class="filter-btn" onclick="setFilter('done',this)">Sem pendências</button>
      <?php if ($filtroCliente): ?>
        <span class="filter-chip-client">Cliente: <strong><?= e($filtroCliente) ?></strong> <a href="veiculos.php" title="Remover filtro">✕</a></span>
      <?php endif; ?>
      <span class="table-count" id="vcount"><?= plural(count($veiculos), 'veículo', 'veículos') ?></span>
    </div>

    <table>
      <thead>
        <tr>
          <th>Placa</th>
          <th>Veículo</th>
          <th>Ano / Cor</th>
          <th>Proprietário</th>
          <th>Status</th>
          <th>Itens ativos</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="vtable-body">
        <?php if (!$veiculos): ?>
          <tr class="empty-row"><td colspan="7">Nenhum veículo cadastrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($veiculos as $v): $st = status_veiculo($v['statusAtivo']); ?>
        <tr data-status="<?= $st[0] ?>">
          <td><span class="td-plate"><?= e($v['placa']) ?></span></td>
          <td><?= e($v['marca'] . ' ' . $v['modelo']) ?></td>
          <td><?= e($v['ano'] . ' · ' . $v['cor']) ?></td>
          <td><div class="owner-chip"><div class="o-avatar"><?= e(initials($v['cliente'])) ?></div><?= e($v['cliente']) ?></div></td>
          <td><?= badge($st) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:12.5px;"><?= (int)$v['itensAtivos'] ?></td>
          <td><div class="row-actions">
            <?php if ($v['protocoloAtivo']): ?>
              <a class="act-btn" href="atendimento.php?numero=<?= (int)$v['protocoloAtivo'] ?>">Atendimento</a>
            <?php else: ?>
              <a class="act-btn" href="registrar_ocorrencia.php?placa=<?= urlencode($v['placa']) ?>">Nova ocorrência</a>
            <?php endif; ?>
            <a class="act-btn" href="../admin/historico.php?q=<?= urlencode($v['placa']) ?>">Histórico</a>
            <button class="act-btn" onclick='openModal(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
            <?php if (e_admin()): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Remover o veículo <?= e($v['placa']) ?>? Ocorrências e protocolos dele também serão apagados.')">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="excluir"/>
                <input type="hidden" name="id" value="<?= e($v['id']) ?>"/>
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
<div class="modal-backdrop" id="modal" onclick="closeModalOutside(event)">
  <form class="modal" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="f-action" value="criar"/>
    <input type="hidden" name="id" id="f-id" value=""/>
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Cadastrar veículo</div>
      <button type="button" class="modal-close" onclick="closeModal()">×</button>
    </div>
    <div class="field">
      <label>Cliente proprietário</label>
      <select name="idCliente" id="f-cliente" required>
        <option value="">Selecione o cliente</option>
        <?php foreach ($clientes as $c): ?>
          <option value="<?= e($c['id']) ?>"><?= e($c['nome']) ?> — <?= e(fmt_cpf($c['cpf'])) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$clientes): ?><div style="font-size:12px;color:var(--muted);margin-top:6px">Nenhum cliente cadastrado. <a href="visualizar_clientes.php?novo=1">Cadastrar cliente</a></div><?php endif; ?>
    </div>
    <div class="field">
      <label>Placa</label>
      <input type="text" name="placa" id="f-placa" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase" required/>
    </div>
    <div class="field-row">
      <div class="field">
        <label>Marca</label>
        <select name="idMarca" id="f-marca" required>
          <option value="">Selecione</option>
          <?php foreach ($marcas as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Modelo</label>
        <input type="text" name="modelo" id="f-modelo" placeholder="Ex: Corolla" maxlength="100" required/>
      </div>
    </div>
    <div class="field-row">
      <div class="field">
        <label>Ano</label>
        <input type="number" name="ano" id="f-ano" placeholder="2020" min="1900" max="<?= date('Y') + 1 ?>" required/>
      </div>
      <div class="field">
        <label>Cor</label>
        <input type="text" name="cor" id="f-cor" placeholder="Ex: Prata" maxlength="10" required/>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal()">Cancelar</button>
      <button type="submit" class="btn-confirm" id="f-submit">Cadastrar veículo</button>
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
    const rows = document.querySelectorAll('#vtable-body tr[data-status]');
    let visible = 0;
    rows.forEach(r => {
      const matchFilter = currentFilter === 'all' || r.dataset.status === currentFilter;
      const matchSearch = !searchTerm || r.textContent.toLowerCase().includes(searchTerm);
      const show = matchFilter && matchSearch;
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    document.getElementById('vcount').textContent = visible + ' veículo' + (visible !== 1 ? 's' : '');
  }

  function openModal(v) {
    const edit = !!v;
    document.getElementById('modal-title').textContent = edit ? 'Editar veículo' : 'Cadastrar veículo';
    document.getElementById('f-submit').textContent   = edit ? 'Salvar alterações' : 'Cadastrar veículo';
    document.getElementById('f-action').value  = edit ? 'editar' : 'criar';
    document.getElementById('f-id').value      = edit ? v.id : '';
    document.getElementById('f-cliente').value = edit ? v.idCliente : '';
    document.getElementById('f-placa').value   = edit ? v.placa : '';
    document.getElementById('f-marca').value   = edit ? v.idMarca : '';
    document.getElementById('f-modelo').value  = edit ? v.modelo : '';
    document.getElementById('f-ano').value     = edit ? v.ano : '';
    document.getElementById('f-cor').value     = edit ? v.cor : '';
    document.getElementById('modal').classList.add('open');
  }
  function closeModal() { document.getElementById('modal').classList.remove('open'); }
  function closeModalOutside(e) { if (e.target === document.getElementById('modal')) closeModal(); }

  <?php if ($busca !== ''): ?>filterTable(<?= json_encode($busca) ?>);<?php endif; ?>
  <?php if ($abrirNovo): ?>
    openModal();
    document.getElementById('f-placa').value = <?= json_encode($placaNovo) ?>;
    <?php if ($idCliente !== ''): ?>document.getElementById('f-cliente').value = <?= json_encode($idCliente) ?>;<?php endif; ?>
  <?php endif; ?>
</script>
</body>
</html>
