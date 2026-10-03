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
                            WHERE v.idCliente = c.id AND p.status IN " . SQL_ATIVOS . ") AS ativos,
                           (SELECT MAX(p.dataFinalizacao) FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                            WHERE v.idCliente = c.id AND p.status = 'FINALIZADO') AS ultimo
                    FROM cliente c ORDER BY c.nome");
$busca = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Clientes'); ?>
  <style>
    .client-chip { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .c-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: var(--on-accent); font-size: 11px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .c-name { font-size: 13.5px; font-weight: 500; }
    .c-email { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; font-weight: 400; }
  </style>
</head>
<body>
<?php sidebar('clientes'); ?>

<main>
  <div class="topbar">
    <div class="search-wrap">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="search" value="<?= e($busca) ?>" placeholder="Buscar por nome, CPF ou e-mail..." oninput="filterTable(this.value)"/>
    </div>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Clientes</div>
      <div class="page-sub">Lista de todos os clientes cadastrados no sistema.</div>
    </div>
    <button class="btn-primary" onclick="openModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo cliente
    </button>
  </div>

  <div class="card">
    <div class="table-toolbar">
      <button class="filter-btn active" onclick="setFilter('all',this)">Todos</button>
      <button class="filter-btn" onclick="setFilter('ativo',this)">Com veículos</button>
      <button class="filter-btn" onclick="setFilter('sem',this)">Sem veículos</button>
      <span class="table-count" id="count"><?= plural(count($clientes), 'cliente', 'clientes') ?></span>
    </div>

    <table class="table-stack">
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
          $tel = so_digitos($c['telefone']);
        ?>
        <tr data-filter="<?= $nv ? 'ativo' : 'sem' ?>">
          <td class="cell-main"><div class="client-chip"><div class="c-avatar"><?= e(initials($c['nome'])) ?></div><div><div class="c-name"><?= e($c['nome']) ?></div><div class="c-email"><?= e($c['email']) ?></div></div></div></td>
          <td data-label="CPF" class="td-mono"><?= e(fmt_cpf($c['cpf'])) ?></td>
          <td data-label="Telefone"><?php if ($tel !== ''): ?><a href="tel:<?= e($tel) ?>" style="text-decoration:none"><?= e(fmt_tel($c['telefone'])) ?></a><?php else: ?>—<?php endif; ?></td>
          <td data-label="Veículos"><span class="badge <?= $badgeCls ?>"><?= $nv ? plural($nv, 'veículo', 'veículos') : 'Nenhum' ?></span></td>
          <td data-label="Último atendimento" class="td-mono"><?= fmt_data($c['ultimo']) ?></td>
          <td class="cell-actions"><div class="row-actions">
            <a class="act-btn" href="veiculos.php?cliente=<?= urlencode($c['id']) ?>">Ver veículos</a>
            <a class="act-btn" href="veiculos.php?novo=1&amp;cliente=<?= urlencode($c['id']) ?>">+ Veículo</a>
            <button class="act-btn" onclick='openModal(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Editar</button>
            <?php if (e_admin()): ?>
              <form method="POST" style="display:inline" data-confirm="Remover <?= e($c['nome']) ?>? Veículos, ocorrências e protocolos do cliente também serão apagados." data-confirm-title="Remover cliente" data-confirm-ok="Remover" data-confirm-danger>
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

<!-- MODAL CADASTRAR / EDITAR -->
<div class="modal-backdrop" id="modal">
  <form class="modal" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="f-action" value="criar"/>
    <input type="hidden" name="id" id="f-id" value=""/>
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Novo cliente</div>
      <button type="button" class="modal-close" data-close-modal aria-label="Fechar">×</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="f-nome">Nome completo *</label>
        <input type="text" name="nome" id="f-nome" maxlength="100" required/>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="f-cpf">CPF *</label>
          <input type="text" name="cpf" id="f-cpf" placeholder="000.000.000-00" maxlength="14" inputmode="numeric" data-mask="cpf" required/>
        </div>
        <div class="field">
          <label for="f-tel">Telefone</label>
          <input type="tel" name="telefone" id="f-tel" placeholder="(00) 00000-0000" maxlength="15" data-mask="tel"/>
        </div>
      </div>
      <div class="field">
        <label for="f-email">E-mail *</label>
        <input type="email" name="email" id="f-email" maxlength="100" required/>
      </div>
      <div class="field">
        <label for="f-end">Endereço *</label>
        <input type="text" name="endereco" id="f-end" maxlength="200" required/>
      </div>
      <div class="field">
        <label id="f-senha-label" for="f-senha">Senha provisória *</label>
        <input type="password" name="senha" id="f-senha" placeholder="Mínimo 8 caracteres" minlength="8" autocomplete="new-password"/>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-outline" data-close-modal>Cancelar</button>
      <button type="submit" class="btn-primary" id="f-submit">Cadastrar cliente</button>
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
    document.querySelectorAll('#table-body tr[data-filter]').forEach(r => {
      const show = (currentFilter === 'all' || r.dataset.filter === currentFilter)
                && (!searchTerm || r.textContent.toLowerCase().includes(searchTerm));
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    document.getElementById('count').textContent = visible + ' cliente' + (visible !== 1 ? 's' : '');
  }

  function openModal(c) {
    const edit = !!c;
    document.getElementById('modal-title').textContent = edit ? 'Editar cliente' : 'Novo cliente';
    document.getElementById('f-submit').textContent    = edit ? 'Salvar alterações' : 'Cadastrar cliente';
    document.getElementById('f-senha-label').textContent = edit ? 'Nova senha (deixe em branco para manter)' : 'Senha provisória *';
    document.getElementById('f-senha').required = !edit;
    document.getElementById('f-action').value = edit ? 'editar' : 'criar';
    document.getElementById('f-id').value     = edit ? c.id : '';
    document.getElementById('f-nome').value   = edit ? c.nome : '';
    document.getElementById('f-cpf').value    = edit ? mascaraCpf(c.cpf) : '';
    document.getElementById('f-tel').value    = edit ? mascaraTel(c.telefone || '') : '';
    document.getElementById('f-email').value  = edit ? c.email : '';
    document.getElementById('f-end').value    = edit ? c.endereco : '';
    document.getElementById('f-senha').value  = '';
    abrirModal('modal');
  }

  <?php if ($busca !== ''): ?>filterTable(<?= json_encode($busca) ?>);<?php endif; ?>
  <?php if (isset($_GET['novo'])): ?>openModal();<?php endif; ?>
</script>
</body>
</html>
