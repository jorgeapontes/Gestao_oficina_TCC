<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['ADMIN']);

// ─── HELPERS ─────────────────────────────────────────────────────────────────
$avatarColors = ['ca-blue','ca-green','ca-amber','ca-rose','ca-slate'];
function avatarColor(string $id): string {
    global $avatarColors;
    return $avatarColors[hexdec(substr(md5($id),0,2)) % count($avatarColors)];
}

// ─── CRUD ────────────────────────────────────────────────────────────────────
if (in_array(acao(), ['criar', 'editar'], true)) {
    $id     = post('id');
    $nome   = post('nome');
    $cpf    = so_digitos(post('cpf'));
    $email  = mb_strtolower(post('email'));
    $cargo  = post('cargo');
    $setor  = post('setor');
    $perfil = post('perfil') === 'ADMIN' ? 'ADMIN' : 'COLABORADOR';
    $senha  = post_senha('senha');
    $anterior = acao() === 'editar' ? db_one('SELECT id, cpf FROM colaborador WHERE id = ?', [$id]) : null;

    if (!$nome || !$cpf || !$email || !$cargo || !$setor || (acao() === 'criar' && $senha === '')) {
        flash('err', 'Preencha todos os campos obrigatórios.');
    } elseif (acao() === 'editar' && !$anterior) {
        flash('err', 'Colaborador não encontrado.');
    } elseif (mb_strlen($nome) > 100 || mb_strlen($cargo) > 50 || mb_strlen($setor) > 50) {
        flash('err', 'Nome (até 100), cargo ou setor (até 50 caracteres) muito longo.');
    } elseif (strlen($cpf) !== 11 || ($cpf !== ($anterior['cpf'] ?? null) && !cpf_valido($cpf))) {
        // CPF novo ou alterado precisa ser válido (cadastros antigos continuam editáveis).
        flash('err', 'CPF inválido.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        flash('err', 'E-mail inválido.');
    } elseif ($senha !== '' && ($msg = erro_senha($senha, $email))) {
        flash('err', $msg);
    } elseif (db_val('SELECT 1 FROM cliente WHERE email = ?', [$email])) {
        flash('err', 'Este e-mail já pertence a um cliente.');
    } elseif (acao() === 'editar' && $id === $u['id'] && $perfil !== 'ADMIN') {
        flash('err', 'Você não pode remover o seu próprio perfil de administrador.');
    } else {
        try {
            if (acao() === 'criar') {
                db_exec('INSERT INTO colaborador (id,nome,cpf,email,senha,cargo,setor,perfil) VALUES (?,?,?,?,?,?,?,?)',
                        [uuid(), $nome, $cpf, $email, password_hash($senha, PASSWORD_DEFAULT), $cargo, $setor, $perfil]);
                flash('ok', 'Colaborador cadastrado com sucesso!');
            } else {
                db_exec('UPDATE colaborador SET nome=?,cpf=?,email=?,cargo=?,setor=?,perfil=? WHERE id=?',
                        [$nome, $cpf, $email, $cargo, $setor, $perfil, $id]);
                // Atualiza senha apenas se foi informada
                // Trocar a senha encerra as sessões abertas daquele colaborador
                // (exceto a do próprio administrador que está fazendo a troca).
                if ($senha !== '') {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    db_exec('UPDATE colaborador SET senha=? WHERE id=?', [$hash, $id]);
                    if ($id === $u['id']) atualizar_digital_senha($hash);
                }
                if ($id === $u['id']) $_SESSION['user']['nome'] = $nome;
                flash('ok', 'Colaborador atualizado com sucesso!');
            }
        } catch (mysqli_sql_exception $ex) {
            flash('err', erro_db($ex));
        }
    }
    redirecionar('gerenciar_colaboradores.php');
}

// EXCLUIR — colaboradores com atendimentos/itens registrados não podem ser removidos (histórico imutável).
if (acao() === 'excluir') {
    $id = post('id');
    if ($id === $u['id']) {
        flash('err', 'Você não pode remover a si mesmo.');
    } else {
        try {
            db_exec('DELETE FROM colaborador WHERE id=?', [$id]);
            flash('ok', 'Colaborador removido.');
        } catch (mysqli_sql_exception $ex) {
            flash('err', $ex->getCode() === 1451
                ? 'Não é possível remover: este colaborador possui atendimentos registrados no histórico.'
                : erro_db($ex));
        }
    }
    redirecionar('gerenciar_colaboradores.php');
}

// ─── LEITURA + FILTROS ───────────────────────────────────────────────────────
$filtro_cargo  = get('cargo');
$filtro_setor  = get('setor');
$busca         = get('q');

$where = [];
$params = [];
if ($filtro_cargo)  { $where[] = 'cargo = ?';  $params[] = $filtro_cargo; }
if ($filtro_setor)  { $where[] = 'setor = ?';  $params[] = $filtro_setor; }
if ($busca) {
    $like = "%$busca%";
    $where[] = '(nome LIKE ? OR cpf LIKE ? OR cargo LIKE ?)';
    array_push($params, $like, $like, $like);
}
$sql = "SELECT col.id, col.nome, col.cpf, col.email, col.cargo, col.setor, col.perfil,
               (SELECT COUNT(*) FROM protocolo p WHERE p.responsavel = col.id AND p.status IN " . SQL_ATIVOS . ") AS ativos,
               (SELECT COUNT(*) FROM protocolo p WHERE p.responsavel = col.id AND p.status = 'FINALIZADO') AS finalizados,
               (SELECT AVG(a.nota) FROM avaliacao a JOIN protocolo p ON p.numero = a.numeroProtocolo WHERE p.responsavel = col.id) AS media
        FROM colaborador col"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY nome ASC';
$colaboradores = db_all($sql, $params);
$total = count($colaboradores);

// Valores únicos para os selects de filtro
$cargos  = db_all("SELECT DISTINCT cargo FROM colaborador WHERE cargo != '' ORDER BY cargo");
$setores = db_all("SELECT DISTINCT setor FROM colaborador WHERE setor != '' ORDER BY setor");

// Dados para edição via JS
$collab_json = json_encode($colaboradores, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Gerenciar Colaboradores'); ?>
  <style>
    .collab-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
    .collab-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: border-color 0.15s; display: flex; flex-direction: column; }
    .collab-card:hover { border-color: var(--border-strong); }
    .collab-top { padding: 20px 20px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; gap: 14px; }
    .collab-avatar { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 600; flex-shrink: 0; color: #fff; }
    .ca-blue { background: #3B5BDB; } .ca-green { background: #2D9D6E; } .ca-amber { background: #C08A00; }
    .ca-rose { background: #C0392B; } .ca-slate { background: #5A5750; }
    .collab-info { flex: 1; min-width: 0; }
    .collab-name { font-size: 14.5px; font-weight: 600; margin-bottom: 2px; }
    .collab-cargo { font-size: 12.5px; color: var(--muted); margin-bottom: 6px; }
    .collab-meta { padding: 14px 20px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .collab-meta-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); min-width: 0; }
    .collab-meta-row svg { flex-shrink: 0; }
    .collab-meta-val { color: var(--text); overflow-wrap: anywhere; }
    .collab-kpis { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid var(--border); }
    .collab-kpi { padding: 10px 6px; text-align: center; border-right: 1px solid var(--border); }
    .collab-kpi:last-child { border-right: none; }
    .collab-kpi-val { font-size: 15px; font-weight: 600; }
    .collab-kpi-label { font-size: 10.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }
    .collab-footer { padding: 12px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; }
    .collab-footer > * { flex: 1; }
    .collab-grid .empty-state { grid-column: 1 / -1; }
    @media (max-width: 760px) { .collab-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<?php sidebar('colaboradores'); ?>

<main>
  <div class="topbar">
    <form method="GET" class="search-wrap">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome, CPF ou cargo…"/>
      <?php if ($filtro_cargo): ?><input type="hidden" name="cargo" value="<?= e($filtro_cargo) ?>"><?php endif; ?>
      <?php if ($filtro_setor): ?><input type="hidden" name="setor" value="<?= e($filtro_setor) ?>"><?php endif; ?>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Gerenciar Colaboradores</div>
      <div class="page-sub">Cadastro, edição e remoção de colaboradores da oficina</div>
    </div>
    <button class="btn-primary" onclick="openModal('novo')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo colaborador
    </button>
  </div>

  <form method="GET">
    <?php if ($busca): ?><input type="hidden" name="q" value="<?= e($busca) ?>"><?php endif; ?>
    <div class="controls-row">
      <select class="filter-select" name="cargo" onchange="this.form.submit()" aria-label="Filtrar por cargo">
        <option value="">Todos os cargos</option>
        <?php foreach ($cargos as $c): ?>
          <option value="<?= e($c['cargo']) ?>" <?= $filtro_cargo === $c['cargo'] ? 'selected' : '' ?>><?= e($c['cargo']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="filter-select" name="setor" onchange="this.form.submit()" aria-label="Filtrar por setor">
        <option value="">Todos os setores</option>
        <?php foreach ($setores as $s): ?>
          <option value="<?= e($s['setor']) ?>" <?= $filtro_setor === $s['setor'] ? 'selected' : '' ?>><?= e($s['setor']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="results-count"><?= $total ?> colaborador<?= $total !== 1 ? 'es' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?></div>
    </div>
  </form>

  <div class="collab-grid">
    <?php if (empty($colaboradores)): ?>
      <div class="empty-state lg">
        <div class="empty-icon">👥</div>
        Nenhum colaborador encontrado.<br>Clique em <strong>Novo colaborador</strong> para começar.
      </div>
    <?php else: ?>
      <?php foreach ($colaboradores as $c): ?>
      <div class="collab-card">
        <div class="collab-top">
          <div class="collab-avatar <?= e(avatarColor($c['id'])) ?>"><?= e(initials($c['nome'])) ?></div>
          <div class="collab-info">
            <div class="collab-name"><?= e($c['nome']) ?></div>
            <div class="collab-cargo"><?= e($c['cargo'] ?: '—') ?><?= $c['setor'] ? ' · ' . e($c['setor']) : '' ?></div>
            <span class="badge <?= $c['perfil'] === 'ADMIN' ? 'admin' : 'active' ?>"><?= $c['perfil'] === 'ADMIN' ? 'Administrador' : 'Colaborador' ?></span>
          </div>
        </div>
        <div class="collab-meta">
          <div class="collab-meta-row">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>
            <span class="collab-meta-val"><?= e($c['email']) ?></span>
          </div>
          <div class="collab-meta-row">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h4M7 13h10M7 17h6"/></svg>
            <span>CPF:</span><span class="collab-meta-val mono"><?= e(fmt_cpf($c['cpf'])) ?></span>
          </div>
        </div>
        <div class="collab-kpis">
          <div class="collab-kpi"><div class="collab-kpi-val"><?= (int)$c['ativos'] ?></div><div class="collab-kpi-label">Ativos</div></div>
          <div class="collab-kpi"><div class="collab-kpi-val"><?= (int)$c['finalizados'] ?></div><div class="collab-kpi-label">Finalizados</div></div>
          <div class="collab-kpi"><div class="collab-kpi-val"><?= $c['media'] !== null ? number_format((float)$c['media'], 1, ',', '') . ' ★' : '—' ?></div><div class="collab-kpi-label">Avaliação</div></div>
        </div>
        <div class="collab-footer">
          <button class="btn-outline btn-sm" onclick="openModal('editar', '<?= e($c['id']) ?>')">Editar</button>
          <?php if ($c['id'] !== $u['id']): ?>
          <form method="POST" data-confirm="&quot;<?= e($c['nome']) ?>&quot; será removido permanentemente. Esta ação não pode ser desfeita."
                data-confirm-title="Remover colaborador?" data-confirm-ok="Remover" data-confirm-danger>
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="excluir"/>
            <input type="hidden" name="id" value="<?= e($c['id']) ?>"/>
            <button type="submit" class="btn-danger-outline btn-sm btn-block">Remover</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<!-- MODAL CRIAR / EDITAR -->
<div class="modal-backdrop" id="modal-overlay">
  <form class="modal" method="POST" id="collab-form">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="form-action" value="criar"/>
    <input type="hidden" name="id"      id="form-id"     value=""/>
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Novo Colaborador</div>
      <button type="button" class="modal-close" data-close-modal aria-label="Fechar">×</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="modal-nome">Nome completo *</label>
        <input type="text" name="nome" id="modal-nome" placeholder="Ex.: João da Silva" required/>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="modal-cpf">CPF *</label>
          <input class="mono" type="text" name="cpf" id="modal-cpf" placeholder="000.000.000-00" inputmode="numeric" data-mask="cpf" maxlength="14" required/>
        </div>
        <div class="field">
          <label for="modal-cargo">Cargo *</label>
          <select name="cargo" id="modal-cargo" required>
            <option value="">Selecionar cargo</option>
            <option>Mecânico</option>
            <option>Eletricista</option>
            <option>Funileiro</option>
            <option>Administrativo</option>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="modal-setor">Setor *</label>
          <select name="setor" id="modal-setor" required>
            <option value="">Selecionar setor</option>
            <option>Mecânica Geral</option>
            <option>Elétrica</option>
            <option>Funilaria</option>
            <option>Gestão</option>
          </select>
        </div>
        <div class="field">
          <label for="modal-perfil">Perfil de acesso *</label>
          <select name="perfil" id="modal-perfil">
            <option value="COLABORADOR">Colaborador</option>
            <option value="ADMIN">Administrador</option>
          </select>
        </div>
      </div>
      <div class="field-hint" style="margin-top:-8px">Administradores têm acesso total, incluindo colaboradores e relatórios.</div>
      <div class="field">
        <label for="modal-email">E-mail de acesso *</label>
        <input type="email" name="email" id="modal-email" placeholder="colaborador@imm.com" required/>
      </div>
      <div class="field">
        <label for="modal-senha" id="senha-label">Senha provisória *</label>
        <input type="password" name="senha" id="modal-senha" placeholder="Mín. 8 caracteres, com letras e números" minlength="8" maxlength="72" autocomplete="new-password"/>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-outline" data-close-modal>Cancelar</button>
      <button type="submit" class="btn-primary" id="modal-submit">Cadastrar colaborador</button>
    </div>
  </form>
</div>

<?php flash_html(); ?>

<script>
const COLABORADORES = <?= $collab_json ?>;

function openModal(mode, id) {
  const c = mode === 'editar' ? COLABORADORES.find(x => x.id === id) : null;
  const edit = !!c;
  document.getElementById('modal-title').textContent  = edit ? 'Editar Colaborador' : 'Novo Colaborador';
  document.getElementById('modal-submit').textContent = edit ? 'Salvar alterações' : 'Cadastrar colaborador';
  document.getElementById('form-action').value        = edit ? 'editar' : 'criar';
  document.getElementById('senha-label').textContent  = edit ? 'Nova senha (deixe em branco para manter)' : 'Senha provisória *';
  document.getElementById('modal-senha').required     = !edit;
  document.getElementById('form-id').value      = edit ? c.id : '';
  document.getElementById('modal-nome').value   = edit ? c.nome : '';
  document.getElementById('modal-cpf').value    = edit ? mascaraCpf(c.cpf) : '';
  document.getElementById('modal-cargo').value  = edit ? (c.cargo || '') : '';
  document.getElementById('modal-setor').value  = edit ? (c.setor || '') : '';
  document.getElementById('modal-email').value  = edit ? c.email : '';
  document.getElementById('modal-perfil').value = edit ? c.perfil : 'COLABORADOR';
  document.getElementById('modal-senha').value  = '';
  abrirModal('modal-overlay');
}
</script>
</body>
</html>
