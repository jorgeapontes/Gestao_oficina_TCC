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
    $email  = post('email');
    $cargo  = post('cargo');
    $setor  = post('setor');
    $perfil = post('perfil') === 'ADMIN' ? 'ADMIN' : 'COLABORADOR';
    $senha  = $_POST['senha'] ?? '';

    if (!$nome || !$cpf || !$email || !$cargo || !$setor || (acao() === 'criar' && $senha === '')) {
        flash('err', 'Preencha todos os campos obrigatórios.');
    } elseif (strlen($cpf) !== 11) {
        flash('err', 'CPF inválido: informe os 11 dígitos.');
    } elseif ($senha !== '' && strlen($senha) < 8) {
        flash('err', 'A senha deve ter ao menos 8 caracteres.');
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
                if ($senha !== '') {
                    db_exec('UPDATE colaborador SET senha=? WHERE id=?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
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
$filtro_cargo  = $_GET['cargo']  ?? '';
$filtro_setor  = $_GET['setor']  ?? '';
$busca         = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filtro_cargo)  { $where[] = 'cargo = ?';  $params[] = $filtro_cargo; }
if ($filtro_setor)  { $where[] = 'setor = ?';  $params[] = $filtro_setor; }
if ($busca) {
    $like = "%$busca%";
    $where[] = '(nome LIKE ? OR cpf LIKE ? OR cargo LIKE ?)';
    array_push($params, $like, $like, $like);
}
$sql = 'SELECT id, nome, cpf, email, cargo, setor, perfil FROM colaborador'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY nome ASC';
$colaboradores = db_all($sql, $params);
$total = count($colaboradores);

// Valores únicos para os selects de filtro
$cargos  = db_all("SELECT DISTINCT cargo FROM colaborador WHERE cargo != '' ORDER BY cargo");
$setores = db_all("SELECT DISTINCT setor FROM colaborador WHERE setor != '' ORDER BY setor");

// Dados para edição via JS
$collab_json = json_encode($colaboradores, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Gerenciar Colaboradores</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="admin.css">
  <style>
    /* ── toast ── */
    .toast {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
      padding: .75rem 1.25rem; border-radius: 8px; font-size: .875rem;
      font-weight: 500; box-shadow: 0 4px 20px rgba(0,0,0,.18);
      display: flex; align-items: center; gap: .5rem;
      animation: slideIn .25s ease; pointer-events: none;
    }
    .toast.ok  { background: #1a7f4b; color: #fff; }
    .toast.err { background: #c0392b; color: #fff; }
    @keyframes slideIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }

    /* ── loading state ── */
    .collab-grid.loading { opacity: .45; pointer-events: none; transition: opacity .2s; }

    /* ── empty state ── */
    .empty-state {
      grid-column: 1/-1; text-align: center; padding: 4rem 2rem;
      color: var(--text-muted, #888);
    }
    .empty-state svg { margin-bottom: 1rem; opacity: .35; }
    .empty-state p { font-size: .95rem; margin: 0; }

    /* ── confirm overlay ── */
    .confirm-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.5);
      z-index: 2000; display: none; align-items: center; justify-content: center;
    }
    .confirm-overlay.open { display: flex; }
    .confirm-box {
      background: var(--card-bg, #1e2025); border-radius: 12px;
      padding: 2rem; max-width: 360px; width: 90%; text-align: center;
      box-shadow: 0 8px 40px rgba(0,0,0,.4);
    }
    .confirm-box h3 { margin: 0 0 .5rem; font-size: 1.1rem; }
    .confirm-box p  { margin: 0 0 1.5rem; font-size: .875rem; opacity: .7; }
    .confirm-actions { display: flex; gap: .75rem; justify-content: center; }
  </style>
</head>
<body>

<?php sidebar('colaboradores'); ?>

<!-- MAIN -->
<main>
  <div class="topbar">
    <form method="GET" style="display:contents">
      <div class="search-wrap">
        <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input class="search-input" type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome, CPF ou cargo…"/>
      </div>
      <!-- preserva filtros ao buscar -->
      <?php if($filtro_cargo): ?><input type="hidden" name="cargo" value="<?= e($filtro_cargo) ?>"><?php endif; ?>
      <?php if($filtro_setor): ?><input type="hidden" name="setor" value="<?= e($filtro_setor) ?>"><?php endif; ?>
    </form>
    <div class="notif-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
      <div class="notif-dot"></div>
    </div>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Gerenciar Colaboradores</div>
      <div class="page-sub">Cadastro, edição e remoção de colaboradores da oficina</div>
    </div>
    <button class="btn-primary" onclick="openModal('novo')">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo colaborador
    </button>
  </div>

  <!-- FILTROS -->
  <form method="GET">
    <?php if($busca): ?><input type="hidden" name="q" value="<?= e($busca) ?>"><?php endif; ?>
    <div class="controls-row">
      <select class="filter-select" name="cargo" onchange="this.form.submit()">
        <option value="">Todos os cargos</option>
        <?php foreach ($cargos as $c): ?>
          <option value="<?= e($c['cargo']) ?>" <?= $filtro_cargo===$c['cargo']?'selected':'' ?>><?= e($c['cargo']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="filter-select" name="setor" onchange="this.form.submit()">
        <option value="">Todos os setores</option>
        <?php foreach ($setores as $s): ?>
          <option value="<?= e($s['setor']) ?>" <?= $filtro_setor===$s['setor']?'selected':'' ?>><?= e($s['setor']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="results-count"><?= $total ?> colaborador<?= $total!==1?'es':'' ?> encontrado<?= $total!==1?'s':'' ?></div>
    </div>
  </form>

  <!-- GRID -->
  <div class="collab-grid" id="collab-grid">
    <?php if (empty($colaboradores)): ?>
      <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <p>Nenhum colaborador encontrado.<br>Clique em <strong>Novo colaborador</strong> para começar.</p>
      </div>
    <?php else: ?>
      <?php foreach ($colaboradores as $c):
        $ini   = initials($c['nome']);
        $avcls = avatarColor($c['id']);
        $cpfFmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $c['cpf']);
      ?>
      <div class="collab-card">
        <div class="collab-top">
          <div class="collab-avatar <?= e($avcls) ?>">
            <?= e($ini) ?>
          </div>
          <div class="collab-info">
            <div class="collab-name"><?= e($c['nome']) ?></div>
            <div class="collab-cargo">
              <?= e($c['cargo'] ?: '—') ?><?= $c['setor'] ? ' · ' . e($c['setor']) : '' ?>
            </div>
            <span class="badge active"><?= $c['perfil'] === 'ADMIN' ? 'Administrador' : 'Colaborador' ?></span>
          </div>
        </div>
        <div class="collab-meta">
          <div class="collab-meta-row">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span class="collab-meta-val"><?= e($c['email']) ?></span>
          </div>
          <div class="collab-meta-row">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>CPF: </span>
            <span class="collab-meta-val"><?= e($cpfFmt) ?></span>
          </div>
          <div class="collab-meta-row">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <span>Setor: </span>
            <span class="collab-meta-val"><?= e($c['setor'] ?: '—') ?></span>
          </div>
        </div>
        <div class="collab-footer">
          <button class="btn-outline" onclick="openModal('editar','<?= e($c['id']) ?>')">Editar</button>
          <button class="btn-outline danger" onclick="confirmarRemocao('<?= e($c['id']) ?>','<?= e(addslashes($c['nome'])) ?>')">Remover</button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<!-- ── MODAL CRIAR / EDITAR ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-overlay" onclick="handleOverlayClick(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modal-title">Novo Colaborador</div>
      <button class="modal-close" onclick="closeModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST" id="collab-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" id="form-action" value="criar"/>
      <input type="hidden" name="id"      id="form-id"     value=""/>
      <div class="modal-body">
        <div class="two-col-fields">
          <div class="field-group" style="grid-column:1/-1">
            <div class="field-label">Nome completo *</div>
            <input class="field-input" type="text" name="nome" id="modal-nome" placeholder="Ex.: João da Silva" required/>
          </div>
          <div class="field-group">
            <div class="field-label">CPF *</div>
            <input class="field-input mono" type="text" name="cpf" id="modal-cpf" placeholder="000.000.000-00" required/>
          </div>
          <div class="field-group">
            <div class="field-label">Cargo *</div>
            <select class="field-input" name="cargo" id="modal-cargo" style="cursor:pointer" required>
              <option value="">Selecionar cargo</option>
              <option>Mecânico</option>
              <option>Eletricista</option>
              <option>Funileiro</option>
              <option>Administrativo</option>
            </select>
          </div>
          <div class="field-group">
            <div class="field-label">Setor</div>
            <select class="field-input" name="setor" id="modal-setor" style="cursor:pointer" required>
              <option value="">Selecionar setor</option>
              <option>Mecânica Geral</option>
              <option>Elétrica</option>
              <option>Funilaria</option>
              <option>Gestão</option>
            </select>
          </div>
          <div class="field-group" style="grid-column:1/-1">
            <div class="field-label">Perfil de acesso *</div>
            <select class="field-input" name="perfil" id="modal-perfil" style="cursor:pointer">
              <option value="COLABORADOR">Colaborador — registra ocorrências e atendimentos</option>
              <option value="ADMIN">Administrador — acesso total, incluindo colaboradores</option>
            </select>
          </div>
          <div class="field-group" style="grid-column:1/-1">
            <div class="field-label">E-mail de acesso *</div>
            <input class="field-input" type="email" name="email" id="modal-email" placeholder="colaborador@imm.com" required/>
          </div>
          <div class="field-group" style="grid-column:1/-1" id="senha-group">
            <div class="field-label">Senha provisória *</div>
            <input class="field-input" type="password" name="senha" id="modal-senha" placeholder="Mínimo 8 caracteres"/>
            <div class="field-hint">O colaborador deverá alterar a senha no primeiro acesso.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal()">Cancelar</button>
        <button type="submit" class="btn-modal-primary" id="modal-submit">Cadastrar colaborador</button>
      </div>
    </form>
  </div>
</div>

<!-- ── CONFIRM DELETE ─────────────────────────────────────────────────────── -->
<div class="confirm-overlay" id="confirm-overlay">
  <div class="confirm-box">
    <h3>Remover colaborador?</h3>
    <p id="confirm-msg">Esta ação não pode ser desfeita.</p>
    <div class="confirm-actions">
      <button class="btn-ghost" onclick="closeConfirm()">Cancelar</button>
      <form method="POST" style="display:contents">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="excluir"/>
        <input type="hidden" name="id"      id="confirm-id" value=""/>
        <button type="submit" class="btn-modal-primary" style="background:#c0392b;border-color:#c0392b">Remover</button>
      </form>
    </div>
  </div>
</div>

<?php flash_html(); ?>

<script>
const COLABORADORES = <?= $collab_json ?>;

function openModal(mode, id) {
  const overlay = document.getElementById('modal-overlay');
  const title   = document.getElementById('modal-title');
  const submit  = document.getElementById('modal-submit');
  const action  = document.getElementById('form-action');
  const senhaI  = document.getElementById('modal-senha');
  const senhaLabel = document.querySelector('#senha-group .field-label');

  overlay.classList.add('open');

  if (mode === 'editar' && id) {
    const c = COLABORADORES.find(x => x.id === id);
    if (!c) return;

    title.textContent  = 'Editar Colaborador';
    submit.textContent = 'Salvar alterações';
    action.value       = 'editar';
    senhaLabel.textContent = 'Nova senha (deixe em branco para manter)';
    senhaI.required = false;

    document.getElementById('form-id').value     = c.id;
    document.getElementById('modal-nome').value  = c.nome;
    document.getElementById('modal-cpf').value   = c.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    document.getElementById('modal-cargo').value = c.cargo  || '';
    document.getElementById('modal-setor').value = c.setor  || '';
    document.getElementById('modal-email').value = c.email;
    document.getElementById('modal-perfil').value = c.perfil;
    document.getElementById('modal-senha').value = '';
  } else {
    title.textContent  = 'Novo Colaborador';
    submit.textContent = 'Cadastrar colaborador';
    action.value       = 'criar';
    senhaLabel.textContent = 'Senha provisória *';
    senhaI.required = true;

    document.getElementById('form-id').value     = '';
    document.getElementById('modal-nome').value  = '';
    document.getElementById('modal-cpf').value   = '';
    document.getElementById('modal-cargo').value = '';
    document.getElementById('modal-setor').value = '';
    document.getElementById('modal-email').value = '';
    document.getElementById('modal-perfil').value = 'COLABORADOR';
    document.getElementById('modal-senha').value = '';
  }
}

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modal-overlay')) closeModal();
}

function confirmarRemocao(id, nome) {
  document.getElementById('confirm-id').value  = id;
  document.getElementById('confirm-msg').textContent = `"${nome}" será removido permanentemente.`;
  document.getElementById('confirm-overlay').classList.add('open');
}

function closeConfirm() {
  document.getElementById('confirm-overlay').classList.remove('open');
}

// Máscara CPF
document.getElementById('modal-cpf').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'').substring(0,11);
  if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4');
  else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d+)/,'$1.$2.$3');
  else if (v.length > 3) v = v.replace(/(\d{3})(\d+)/,'$1.$2');
  this.value = v;
});

// Busca ao pressionar Enter
document.querySelector('.search-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    const params = new URLSearchParams(window.location.search);
    params.set('q', this.value);
    window.location.search = params.toString();
  }
});
</script>
</body>
</html>