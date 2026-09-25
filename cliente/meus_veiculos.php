<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

// ─── CRUD (sempre restrito aos veículos do próprio cliente) ──────────────────
if (in_array(acao(), ['criar', 'editar'], true)) {
    $id      = post('id');
    $placa   = normalizar_placa(post('placa'));
    $idMarca = post('idMarca');
    $modelo  = post('modelo');
    $ano     = (int)post('ano');
    $cor     = post('cor');

    if (!$placa || !$idMarca || !$modelo || !$cor || !$ano) {
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
                        [uuid(), $placa, $ano, mb_substr($cor, 0, 10), $idModelo, $u['id']]);
                flash('ok', "Veículo $placa cadastrado com sucesso!");
            } else {
                db_exec('UPDATE veiculo SET placa=?, ano=?, cor=?, idModelo=? WHERE id=? AND idCliente=?',
                        [$placa, $ano, mb_substr($cor, 0, 10), $idModelo, $id, $u['id']]);
                flash('ok', "Veículo $placa atualizado.");
            }
        } catch (mysqli_sql_exception $ex) {
            flash('err', erro_db($ex));
        }
    }
    redirecionar('meus_veiculos.php');
}

if (acao() === 'excluir') {
    $id = post('id');
    if (db_val("SELECT 1 FROM protocolo WHERE idVeiculo = ? AND status IN ('ABERTO','EM_ATENDIMENTO')", [$id])) {
        flash('err', 'Este veículo possui um atendimento em andamento e não pode ser removido agora.');
    } else {
        db_exec('DELETE FROM veiculo WHERE id = ? AND idCliente = ?', [$id, $u['id']]);
        flash('ok', 'Veículo removido.');
    }
    redirecionar('meus_veiculos.php');
}

// ─── LEITURA ─────────────────────────────────────────────────────────────────
$veiculos = db_all("SELECT v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.id AS idMarca, ma.nome AS marca,
                           " . SQL_STATUS_ATIVO . " AS statusAtivo,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id) AS ocorrencias
                    FROM veiculo v
                    JOIN modelo mo ON mo.id = v.idModelo
                    JOIN marca ma  ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa", [$u['id']]);
$marcas = db_all('SELECT id, nome FROM marca ORDER BY nome');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Meus Veículos</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #F5F4F2;
      --surface:   #FFFFFF;
      --border:    #E4E2DE;
      --text:      #1A1917;
      --muted:     #7A7872;
      --tag-open:  #E8F2EC;
      --tag-open-t:#2D7A4F;
      --tag-prog:  #FEF4E4;
      --tag-prog-t:#A05A00;
      --tag-done:  #EDECEA;
      --tag-done-t:#5A5750;
      --sidebar-w: 230px;
    }

    body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

    aside {
      width: var(--sidebar-w); min-height: 100vh;
      background: var(--surface); border-right: 1px solid var(--border);
      display: flex; flex-direction: column; padding: 28px 0;
      position: fixed; top: 0; left: 0;
    }
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
    .notif-btn { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }
    .notif-dot { width: 7px; height: 7px; background: #C0392B; border-radius: 50%; position: absolute; top: 7px; right: 7px; border: 1.5px solid white; }

    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }

    .btn-primary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 18px; background: var(--text); color: #fff;
      border: none; border-radius: 8px; font-family: inherit;
      font-size: 13.5px; font-weight: 500; cursor: pointer;
      transition: opacity 0.15s;
    }
    .btn-primary:hover { opacity: 0.85; }
    .btn-primary svg { width: 15px; height: 15px; }

    /* VEHICLE CARDS GRID */
    .vehicles-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 16px;
    }

    .vehicle-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      transition: border-color 0.15s;
    }
    .vehicle-card:hover { border-color: #C8C6C2; }

    .vehicle-card-top {
      padding: 20px 22px 16px;
      border-bottom: 1px solid var(--border);
    }
    .vehicle-plate {
      font-family: 'DM Mono', monospace;
      font-size: 16px;
      font-weight: 500;
      letter-spacing: 0.06em;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 7px;
      padding: 6px 12px;
      display: inline-block;
      margin-bottom: 12px;
    }
    .vehicle-name { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
    .vehicle-year { font-size: 12px; color: var(--muted); }

    .vehicle-card-body { padding: 14px 22px; }
    .vehicle-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .meta-item label { display: block; font-size: 10.5px; color: var(--muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px; }
    .meta-item span { font-size: 13px; font-weight: 500; }

    .vehicle-status-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }
    .badge {
      display: inline-flex; align-items: center;
      font-size: 11.5px; font-weight: 500;
      padding: 3px 9px; border-radius: 20px;
    }
    .badge.open  { background: var(--tag-open);  color: var(--tag-open-t); }
    .badge.prog  { background: var(--tag-prog);  color: var(--tag-prog-t); }
    .badge.done  { background: var(--tag-done);  color: var(--tag-done-t); }

    .vehicle-actions { display: flex; gap: 8px; }
    .btn-sm {
      flex: 1; padding: 7px 12px;
      border: 1px solid var(--border);
      border-radius: 7px; background: transparent;
      font-family: inherit; font-size: 12.5px; font-weight: 500;
      color: var(--muted); cursor: pointer;
      transition: border-color 0.15s, color 0.15s, background 0.15s;
      text-align: center;
    }
    .btn-sm:hover { border-color: var(--text); color: var(--text); background: var(--bg); }
    .btn-sm.danger:hover { border-color: #C0392B; color: #C0392B; background: #FEF0EE; }

    /* ADD CARD */
    .vehicle-card.add-card {
      border-style: dashed;
      display: flex; align-items: center; justify-content: center;
      min-height: 200px;
      cursor: pointer;
    }
    .vehicle-card.add-card:hover { background: #fafafa; border-color: var(--text); }
    .add-card-inner { text-align: center; color: var(--muted); }
    .add-card-inner svg { width: 28px; height: 28px; margin-bottom: 10px; opacity: 0.4; }
    .add-card-label { font-size: 13.5px; font-weight: 500; color: var(--muted); }
    .add-card-sub { font-size: 12px; color: var(--muted); opacity: 0.7; margin-top: 3px; }

    /* MODAL */
    .modal-backdrop {
      position: fixed; inset: 0;
      background: rgba(26,25,23,0.35);
      display: flex; align-items: center; justify-content: center;
      z-index: 100;
      opacity: 0; pointer-events: none;
      transition: opacity 0.2s;
    }
    .modal-backdrop.open { opacity: 1; pointer-events: all; }
    .modal {
      background: var(--surface);
      border-radius: 14px;
      width: 100%;
      max-width: 460px;
      padding: 28px 32px;
      transform: translateY(10px);
      transition: transform 0.2s;
    }
    .modal-backdrop.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .modal-title { font-size: 16px; font-weight: 600; }
    .modal-close { border: none; background: none; font-size: 20px; color: var(--muted); cursor: pointer; line-height: 1; padding: 2px 6px; border-radius: 6px; }
    .modal-close:hover { background: var(--bg); color: var(--text); }

    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 12.5px; font-weight: 500; color: var(--muted); margin-bottom: 6px; letter-spacing: 0.02em; }
    .field input, .field select {
      width: 100%; border: 1px solid var(--border); border-radius: 8px;
      padding: 10px 14px; font-size: 14px; font-family: inherit;
      background: var(--surface); color: var(--text); outline: none;
      transition: border-color 0.15s; -webkit-appearance: none;
    }
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
  a.btn-sm { text-decoration: none; text-align: center; color: inherit; }
  .user-info { min-width: 0; }
</style>

<?php sidebar('veiculos'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Meus Veículos</div>
      <div class="page-sub">Gerencie os veículos vinculados à sua conta.</div>
    </div>
    <button class="btn-primary" onclick="openModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Novo veículo
    </button>
  </div>

  <div class="vehicles-grid">

    <?php foreach ($veiculos as $v): $st = status_veiculo($v['statusAtivo']); ?>
    <div class="vehicle-card">
      <div class="vehicle-card-top">
        <div class="vehicle-plate"><?= e($v['placa']) ?></div>
        <div class="vehicle-name"><?= e($v['marca'] . ' ' . $v['modelo']) ?></div>
        <div class="vehicle-year"><?= e($v['ano'] . ' · ' . $v['cor']) ?></div>
      </div>
      <div class="vehicle-card-body">
        <div class="vehicle-meta">
          <div class="meta-item"><label>Marca</label><span><?= e($v['marca']) ?></span></div>
          <div class="meta-item"><label>Modelo</label><span><?= e($v['modelo']) ?></span></div>
          <div class="meta-item"><label>Ano</label><span><?= e($v['ano']) ?></span></div>
          <div class="meta-item"><label>Cor</label><span><?= e($v['cor']) ?></span></div>
        </div>
        <div class="vehicle-status-row">
          <?= badge($st) ?>
          <span style="font-size:12px;color:var(--muted);font-family:'DM Mono',monospace"><?= plural((int)$v['ocorrencias'], 'ocorrência', 'ocorrências') ?></span>
        </div>
        <div class="vehicle-actions">
          <a class="btn-sm" href="visualizar_ocorrencias.php?v=<?= urlencode($v['id']) ?>">Ver ocorrências</a>
          <button class="btn-sm" onclick='openModal(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Editar</button>
          <form method="POST" style="display:contents" onsubmit="return confirm('Remover o veículo <?= e($v['placa']) ?>? O histórico de ocorrências dele também será apagado.')">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="excluir"/>
            <input type="hidden" name="id" value="<?= e($v['id']) ?>"/>
            <button class="btn-sm danger" type="submit">Remover</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Add card -->
    <div class="vehicle-card add-card" onclick="openModal()">
      <div class="add-card-inner">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 5v14M5 12h14"/></svg>
        <div class="add-card-label">Adicionar veículo</div>
        <div class="add-card-sub">Placa, modelo, marca e ano</div>
      </div>
    </div>

  </div>
</main>

<!-- MODAL CADASTRAR / EDITAR VEÍCULO -->
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
  function openModal(v) {
    const edit = !!v;
    document.getElementById('modal-title').textContent = edit ? 'Editar veículo' : 'Cadastrar veículo';
    document.getElementById('f-submit').textContent   = edit ? 'Salvar alterações' : 'Cadastrar veículo';
    document.getElementById('f-action').value = edit ? 'editar' : 'criar';
    document.getElementById('f-id').value     = edit ? v.id : '';
    document.getElementById('f-placa').value  = edit ? v.placa : '';
    document.getElementById('f-marca').value  = edit ? v.idMarca : '';
    document.getElementById('f-modelo').value = edit ? v.modelo : '';
    document.getElementById('f-ano').value    = edit ? v.ano : '';
    document.getElementById('f-cor').value    = edit ? v.cor : '';
    document.getElementById('modal').classList.add('open');
  }
  function closeModal() {
    document.getElementById('modal').classList.remove('open');
  }
  function closeModalOutside(e) {
    if (e.target === document.getElementById('modal')) closeModal();
  }
  <?php if (isset($_GET['novo'])): ?>openModal();<?php endif; ?>
</script>
</body>
</html>
