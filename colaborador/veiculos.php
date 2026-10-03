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
                           " . SQL_PROTOCOLO_ATIVO . " AS protocoloAtivo,
                           (SELECT COUNT(*) FROM itemocorrencia i JOIN protocolo p ON p.idOcorrencia = i.idOcorrencia
                            WHERE p.idVeiculo = v.id AND p.status IN " . SQL_ATIVOS . ") AS itensAtivos
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
$filtros = ['all' => 'Todos', 'prog' => 'Em serviço', 'open' => 'Aguardando', 'wait' => 'Aguard. peças/aprovação', 'ready' => 'Prontos', 'done' => 'Sem pendências'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Veículos'); ?>
  <style>
    .owner-chip { display: flex; align-items: center; gap: 8px; }
    .o-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--accent); color: var(--on-accent); font-size: 9px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .filter-chip-client { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); margin-left: 6px; }
    .filter-chip-client a { color: var(--text); text-decoration: none; font-weight: 500; }
  </style>
</head>
<body>
<?php sidebar('veiculos'); ?>

<main>
  <div class="topbar">
    <div class="search-wrap">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="search" id="search" value="<?= e($busca) ?>" placeholder="Buscar por placa, modelo ou cliente..." oninput="filterTable(this.value)"/>
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

  <div class="card">
    <div class="table-toolbar">
      <?php foreach ($filtros as $chave => $rotulo): ?>
        <button class="filter-btn<?= $chave === 'all' ? ' active' : '' ?>" onclick="setFilter('<?= $chave ?>', this)"><?= e($rotulo) ?></button>
      <?php endforeach; ?>
      <?php if ($filtroCliente): ?>
        <span class="filter-chip-client">Cliente: <strong><?= e($filtroCliente) ?></strong> <a href="veiculos.php" title="Remover filtro">✕</a></span>
      <?php endif; ?>
      <span class="table-count" id="vcount"><?= plural(count($veiculos), 'veículo', 'veículos') ?></span>
    </div>

    <table class="table-stack">
      <thead>
        <tr>
          <th>Placa</th>
          <th class="hide-mobile">Veículo</th>
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
          <td class="cell-main"><span class="td-plate"><?= e($v['placa']) ?></span>&nbsp; <span class="show-mobile"><?= e($v['marca'] . ' ' . $v['modelo']) ?></span></td>
          <td data-label="Veículo" class="hide-mobile"><?= e($v['marca'] . ' ' . $v['modelo']) ?></td>
          <td data-label="Ano / Cor"><?= e($v['ano'] . ' · ' . $v['cor']) ?></td>
          <td data-label="Proprietário"><div class="owner-chip"><div class="o-avatar"><?= e(initials($v['cliente'])) ?></div><?= e($v['cliente']) ?></div></td>
          <td data-label="Status"><?= badge($st) ?></td>
          <td data-label="Itens ativos" class="td-mono"><?= (int)$v['itensAtivos'] ?></td>
          <td class="cell-actions"><div class="row-actions">
            <?php if ($v['protocoloAtivo']): ?>
              <a class="act-btn" href="atendimento.php?numero=<?= (int)$v['protocoloAtivo'] ?>">Atendimento</a>
            <?php else: ?>
              <a class="act-btn" href="registrar_ocorrencia.php?placa=<?= urlencode($v['placa']) ?>">Nova ocorrência</a>
            <?php endif; ?>
            <a class="act-btn" href="../admin/historico.php?q=<?= urlencode($v['placa']) ?>">Histórico</a>
            <button class="act-btn" onclick='openModal(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Editar</button>
            <?php if (e_admin()): ?>
              <form method="POST" style="display:inline" data-confirm="Remover o veículo <?= e($v['placa']) ?>? Ocorrências e protocolos dele também serão apagados." data-confirm-title="Remover veículo" data-confirm-ok="Remover" data-confirm-danger>
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

<!-- MODAL CADASTRAR / EDITAR -->
<div class="modal-backdrop" id="modal">
  <form class="modal" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="f-action" value="criar"/>
    <input type="hidden" name="id" id="f-id" value=""/>
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Cadastrar veículo</div>
      <button type="button" class="modal-close" data-close-modal aria-label="Fechar">×</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="f-cliente">Cliente proprietário</label>
        <select name="idCliente" id="f-cliente" required>
          <option value="">Selecione o cliente</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= e($c['id']) ?>"><?= e($c['nome']) ?> — <?= e(fmt_cpf($c['cpf'])) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$clientes): ?><div class="field-hint">Nenhum cliente cadastrado. <a href="visualizar_clientes.php?novo=1">Cadastrar cliente</a></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="f-placa">Placa</label>
        <input type="text" name="placa" id="f-placa" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase" data-mask="placa" required/>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="f-marca">Marca</label>
          <select name="idMarca" id="f-marca" required>
            <option value="">Selecione</option>
            <?php foreach ($marcas as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-modelo">Modelo</label>
          <input type="text" name="modelo" id="f-modelo" placeholder="Ex: Corolla" maxlength="100" required/>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="f-ano">Ano</label>
          <input type="number" name="ano" id="f-ano" placeholder="2020" min="1900" max="<?= date('Y') + 1 ?>" inputmode="numeric" required/>
        </div>
        <div class="field">
          <label for="f-cor">Cor</label>
          <input type="text" name="cor" id="f-cor" placeholder="Ex: Prata" maxlength="10" required/>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-outline" data-close-modal>Cancelar</button>
      <button type="submit" class="btn-primary" id="f-submit">Cadastrar veículo</button>
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
    let visible = 0;
    document.querySelectorAll('#vtable-body tr[data-status]').forEach(r => {
      const show = (currentFilter === 'all' || r.dataset.status === currentFilter)
                && (!searchTerm || r.textContent.toLowerCase().includes(searchTerm));
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
    abrirModal('modal');
  }

  <?php if ($busca !== ''): ?>filterTable(<?= json_encode($busca) ?>);<?php endif; ?>
  <?php if ($abrirNovo): ?>
    openModal();
    document.getElementById('f-placa').value = <?= json_encode($placaNovo) ?>;
    <?php if ($idCliente !== ''): ?>document.getElementById('f-cliente').value = <?= json_encode($idCliente) ?>;<?php endif; ?>
  <?php endif; ?>
</script>
</body>
</html>
