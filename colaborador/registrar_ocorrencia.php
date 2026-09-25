<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

const COMPONENTES = ['Freios', 'Motor', 'Suspensão', 'Embreagem', 'Transmissão', 'Sistema elétrico', 'Arrefecimento', 'Pneus', 'Outros'];

function buscar_veiculo(string $placa): ?array {
    return db_one("SELECT v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                          c.nome AS cliente, " . SQL_STATUS_ATIVO . " AS statusAtivo,
                          (SELECT p.numero FROM protocolo p WHERE p.idVeiculo = v.id
                           AND p.status IN ('ABERTO','EM_ATENDIMENTO') ORDER BY p.numero DESC LIMIT 1) AS protocoloAtivo
                   FROM veiculo v
                   JOIN modelo mo ON mo.id = v.idModelo
                   JOIN marca ma  ON ma.id = mo.idMarca
                   JOIN cliente c ON c.id = v.idCliente
                   WHERE REPLACE(v.placa, '-', '') = ?", [str_replace('-', '', normalizar_placa($placa))]);
}

// ─── REGISTRAR OCORRÊNCIA ────────────────────────────────────────────────────
// Cria a ocorrência, seus itens e o protocolo de atendimento (status ABERTO),
// tendo o colaborador logado como responsável.
if (acao() === 'registrar') {
    $placa       = post('placa');
    $componentes = post('componentes');
    $observacoes = post('observacoes');
    $itens       = array_values(array_filter(array_map('trim', (array)($_POST['itens'] ?? [])), 'strlen'));
    $v           = $placa !== '' ? buscar_veiculo($placa) : null;
    $voltar      = 'registrar_ocorrencia.php' . ($placa !== '' ? '?placa=' . urlencode($placa) : '');

    if (!$v) {
        flash('err', 'Veículo não encontrado. Busque pela placa antes de registrar.');
        redirecionar($voltar);
    }
    if ($v['protocoloAtivo']) {
        flash('err', 'Este veículo já possui o atendimento ' . proto_num($v['protocoloAtivo']) . ' em aberto. Adicione os itens nele.');
        redirecionar('atendimento.php?numero=' . $v['protocoloAtivo']);
    }
    if (!$itens) {
        flash('err', 'Descreva ao menos um problema identificado.');
        redirecionar($voltar);
    }

    // A descrição da ocorrência é o resumo informado ou, na falta dele, o primeiro item.
    $descricao = mb_substr($observacoes !== '' ? $observacoes : $itens[0], 0, 200);

    $conn->begin_transaction();
    try {
        $idOc = uuid();
        db_exec("INSERT INTO ocorrencia (id, descricao, componentes, status, idVeiculo) VALUES (?,?,?,'ABERTA',?)",
                [$idOc, $descricao, $componentes !== '' ? mb_substr($componentes, 0, 100) : null, $v['id']]);
        foreach ($itens as $item) {
            db_exec('INSERT INTO itemocorrencia (id, descricao, idOcorrencia, idColaborador) VALUES (?,?,?,?)',
                    [uuid(), mb_substr($item, 0, 200), $idOc, $u['id']]);
        }
        db_exec("INSERT INTO protocolo (status, responsavel, idOcorrencia, idVeiculo) VALUES ('ABERTO',?,?,?)",
                [$u['id'], $idOc, $v['id']]);
        $numero = $conn->insert_id;
        $conn->commit();
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        flash('err', erro_db($ex));
        redirecionar($voltar);
    }
    redirecionar('registrar_ocorrencia.php?sucesso=' . $numero);
}

// ─── LEITURA ─────────────────────────────────────────────────────────────────
$placaBusca = trim($_GET['placa'] ?? '');
$veiculo    = $placaBusca !== '' ? buscar_veiculo($placaBusca) : null;
$sucesso    = (int)($_GET['sucesso'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Registrar Ocorrência</title>
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
    .notif-dot { width: 7px; height: 7px; background: #C0392B; border-radius: 50%; position: absolute; top: 7px; right: 7px; border: 1.5px solid white; }

    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 6px; }
    .page-sub { font-size: 13px; color: var(--muted); margin-bottom: 32px; }

    .two-col { display: grid; grid-template-columns: 1fr 360px; gap: 24px; align-items: start; }

    /* FORM CARD */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
    .card-header { padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .card-title { font-size: 14px; font-weight: 600; }

    .form-body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label { font-size: 12.5px; font-weight: 500; color: var(--muted); letter-spacing: 0.02em; }
    .field input, .field select, .field textarea {
      width: 100%; border: 1px solid var(--border); border-radius: 8px;
      padding: 10px 14px; font-size: 13.5px; font-family: inherit;
      background: var(--surface); color: var(--text); outline: none;
      transition: border-color 0.15s; -webkit-appearance: none;
    }
    .field input::placeholder, .field textarea::placeholder { color: var(--muted); }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--text); }
    .field input[readonly] { background: var(--bg); }
    .field textarea { resize: vertical; min-height: 90px; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* VEHICLE FOUND */
    .vehicle-found {
      border: 1px solid var(--border); border-radius: 8px;
      padding: 14px 16px; background: var(--bg);
      display: flex; align-items: center; gap: 14px;
      display: none;
    }
    .vehicle-found.show { display: flex; }
    .vf-plate { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; background: var(--surface); border: 1px solid var(--border); border-radius: 5px; padding: 4px 10px; }
    .vf-info { flex: 1; }
    .vf-name { font-size: 13.5px; font-weight: 600; }
    .vf-client { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .vf-badge { font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; background: var(--tag-open); color: var(--tag-open-t); }

    /* ITEMS */
    .items-section { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .items-header { padding: 12px 16px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .items-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; }
    .btn-add-item { display: flex; align-items: center; gap: 5px; border: none; background: none; font-family: inherit; font-size: 12.5px; font-weight: 500; color: var(--muted); cursor: pointer; }
    .btn-add-item:hover { color: var(--text); }
    .items-list { padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .item-row { display: flex; align-items: flex-start; gap: 8px; }
    .item-num { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); padding-top: 11px; width: 24px; flex-shrink: 0; }
    .item-input { flex: 1; border: 1px solid var(--border); border-radius: 7px; padding: 9px 12px; font-size: 13px; font-family: inherit; background: var(--surface); color: var(--text); outline: none; resize: none; min-height: 40px; transition: border-color 0.15s; }
    .item-input::placeholder { color: var(--muted); }
    .item-input:focus { border-color: var(--text); }
    .btn-remove-item { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 6px; background: transparent; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); margin-top: 6px; flex-shrink: 0; }
    .btn-remove-item:hover { border-color: #C0392B; color: #C0392B; }

    .form-actions { padding: 18px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }
    .btn-primary { padding: 9px 20px; background: var(--text); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: opacity 0.15s; }
    .btn-primary:hover { opacity: 0.85; }
    .btn-outline { padding: 9px 20px; background: transparent; color: var(--muted); border: 1px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13.5px; font-weight: 500; cursor: pointer; }
    .btn-outline:hover { border-color: var(--text); color: var(--text); }

    /* SIDE CARD */
    .side-card { display: flex; flex-direction: column; gap: 16px; }
    .info-list { padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
    .info-item label { font-size: 11px; color: var(--muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; display: block; margin-bottom: 3px; }
    .info-item span { font-size: 13.5px; font-weight: 500; }
    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.open { background: var(--tag-open); color: var(--tag-open-t); }
    .badge.prog { background: var(--tag-prog); color: var(--tag-prog-t); }

    /* SUCCESS */
    .success-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(26,25,23,0.35);
      align-items: center; justify-content: center; z-index: 100;
    }
    .success-overlay.show { display: flex; }
    .success-modal { background: var(--surface); border-radius: 14px; padding: 40px 44px; text-align: center; max-width: 380px; }
    .success-icon { font-size: 40px; margin-bottom: 16px; }
    .success-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
    .success-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }
    .success-code { font-family: 'DM Mono', monospace; font-size: 22px; font-weight: 500; letter-spacing: 0.05em; margin-bottom: 24px; }
  </style>
</head>
<body>
<style>
  .notice { border: 1px solid #F3D9A4; background: var(--tag-prog); color: var(--tag-prog-t); border-radius: 8px; padding: 12px 14px; font-size: 13px; line-height: 1.5; }
  .notice.err { border-color: #FBC8C3; background: #FEF0EE; color: #9B2C2C; }
  .notice a { color: inherit; font-weight: 600; }
  .field select { cursor: pointer; }
</style>

<?php sidebar('ocorrencias'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="veiculos.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" placeholder="Buscar cliente, veículo, placa..."/>
    </form>
  </div>

  <div class="page-title">Registrar Ocorrência</div>
  <div class="page-sub">Registre os problemas identificados no veículo do cliente.</div>

  <div class="two-col">

    <!-- MAIN FORM -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Nova ocorrência</div>
      </div>
      <div class="form-body">

        <!-- VEHICLE LOOKUP -->
        <form class="field" method="GET">
          <label>Placa do veículo</label>
          <div style="display:flex;gap:8px;">
            <input type="text" name="placa" id="plate-input" value="<?= e($placaBusca) ?>" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()" required/>
            <button type="submit" class="btn-primary" style="flex-shrink:0;padding:10px 16px;">Buscar</button>
          </div>
        </form>

        <?php if ($placaBusca !== '' && !$veiculo): ?>
          <div class="notice err">
            Veículo com placa "<?= e($placaBusca) ?>" não encontrado. Verifique a placa ou
            <a href="veiculos.php?novo=1&amp;placa=<?= urlencode($placaBusca) ?>">cadastre o veículo</a>.
          </div>
        <?php endif; ?>

        <?php if ($veiculo): ?>
          <div class="vehicle-found show">
            <span class="vf-plate"><?= e($veiculo['placa']) ?></span>
            <div class="vf-info">
              <div class="vf-name"><?= e($veiculo['marca'] . ' ' . $veiculo['modelo']) ?></div>
              <div class="vf-client">Proprietário: <?= e($veiculo['cliente']) ?></div>
            </div>
            <span class="vf-badge">Encontrado</span>
          </div>
          <?php if ($veiculo['protocoloAtivo']): ?>
            <div class="notice">
              Este veículo já possui o atendimento <strong><?= proto_num($veiculo['protocoloAtivo']) ?></strong> em aberto.
              <a href="atendimento.php?numero=<?= (int)$veiculo['protocoloAtivo'] ?>">Abrir atendimento →</a>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <form method="POST" id="occ-form" style="display:flex;flex-direction:column;gap:16px;">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="registrar"/>
          <input type="hidden" name="placa" value="<?= e($veiculo['placa'] ?? '') ?>"/>

          <div class="field">
            <label>Componente afetado</label>
            <select name="componentes">
              <option value="">— selecione o componente —</option>
              <?php foreach (COMPONENTES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </div>

          <!-- ITEMS -->
          <div>
            <div style="font-size:12.5px;font-weight:500;color:var(--muted);margin-bottom:10px;">Itens da ocorrência</div>
            <div class="items-section">
              <div class="items-header">
                <span class="items-label">Problemas identificados</span>
                <button type="button" class="btn-add-item" onclick="addItem()">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                  Adicionar item
                </button>
              </div>
              <div class="items-list" id="items-list">
                <div class="item-row">
                  <span class="item-num">01</span>
                  <textarea class="item-input" name="itens[]" placeholder="Descreva o problema ou componente afetado..." rows="2" maxlength="200" required></textarea>
                  <button type="button" class="btn-remove-item" onclick="removeItem(this)" title="Remover">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- NOTES -->
          <div class="field">
            <label>Resumo / observações gerais (opcional)</label>
            <textarea name="observacoes" placeholder="Informações adicionais, recomendações ao cliente..." rows="3" maxlength="200"></textarea>
          </div>
        </form>

      </div>
      <div class="form-actions">
        <a class="btn-outline" href="dashboard.php" style="text-decoration:none">Cancelar</a>
        <button type="submit" form="occ-form" class="btn-primary" <?= (!$veiculo || $veiculo['protocoloAtivo']) ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>>Registrar ocorrência</button>
      </div>
    </div>

    <!-- SIDE INFO -->
    <div class="side-card">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Dados do veículo</div>
        </div>
        <div class="info-list">
          <?php
            $info = $veiculo ? [
              'Placa' => $veiculo['placa'], 'Modelo' => $veiculo['modelo'], 'Marca' => $veiculo['marca'],
              'Ano' => $veiculo['ano'], 'Cor' => $veiculo['cor'], 'Proprietário' => $veiculo['cliente'],
            ] : array_fill_keys(['Placa', 'Modelo', 'Marca', 'Ano', 'Cor', 'Proprietário'], null);
          ?>
          <?php foreach ($info as $k => $val): ?>
            <div class="info-item"><label><?= e($k) ?></label><span<?= $val === null ? ' style="color:var(--muted)"' : '' ?>><?= e($val ?? '—') ?></span></div>
          <?php endforeach; ?>
          <div class="info-item"><label>Status atual</label>
            <?php if ($veiculo): ?><?= badge(status_veiculo($veiculo['statusAtivo'])) ?><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Instruções</div>
        </div>
        <div style="padding:18px 22px;font-size:13px;color:var(--muted);line-height:1.6;">
          <p style="margin-bottom:10px;">1. Busque o veículo pela placa para vinculá-lo à ocorrência.</p>
          <p style="margin-bottom:10px;">2. Adicione um item por problema ou componente identificado.</p>
          <p style="margin-bottom:10px;">3. Seja específico nas descrições para facilitar o orçamento.</p>
          <p>4. Ao registrar, um protocolo de atendimento é gerado e o cliente passa a acompanhá-lo pelo painel.</p>
        </div>
      </div>
    </div>

  </div>
</main>

<?php if ($sucesso): ?>
<!-- SUCCESS MODAL -->
<div class="success-overlay show" id="success-overlay">
  <div class="success-modal">
    <div class="success-icon">✅</div>
    <div class="success-title">Ocorrência registrada</div>
    <div class="success-sub">A ocorrência foi salva e o protocolo de atendimento foi gerado.</div>
    <div class="success-code"><?= proto_num($sucesso) ?></div>
    <a class="btn-primary" style="display:block;width:100%;text-decoration:none;margin-bottom:8px;" href="atendimento.php?numero=<?= $sucesso ?>">Abrir atendimento</a>
    <a class="btn-outline" style="display:block;width:100%;text-decoration:none;" href="registrar_ocorrencia.php">Registrar nova ocorrência</a>
  </div>
</div>
<?php endif; ?>

<?php flash_html(); ?>

<script>
  const ITEM_HTML = `<textarea class="item-input" name="itens[]" placeholder="Descreva o problema ou componente afetado..." rows="2" maxlength="200"></textarea><button type="button" class="btn-remove-item" onclick="removeItem(this)" title="Remover"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>`;

  function renumber() {
    document.querySelectorAll('#items-list .item-num').forEach((n, i) => n.textContent = String(i + 1).padStart(2, '0'));
  }
  function addItem() {
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = '<span class="item-num"></span>' + ITEM_HTML;
    document.getElementById('items-list').appendChild(row);
    renumber();
    row.querySelector('textarea').focus();
  }
  function removeItem(btn) {
    const list = document.getElementById('items-list');
    if (list.children.length <= 1) return;
    btn.closest('.item-row').remove();
    renumber();
  }
</script>
</body>
</html>
