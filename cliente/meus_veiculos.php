<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

// ─── CRUD (sempre restrito aos veículos do próprio cliente) ──────────────────
if (in_array(acao(), ['criar', 'editar'], true)) {
    $id      = post('id');
    $placa   = normalizar_placa(post('placa'));
    $cor     = post('cor');

    if (!$placa || !$cor) {
        flash('err', 'Preencha todos os campos.');
    } elseif (!preg_match('/^[A-Z]{3}-?\d[A-Z0-9]\d{2}$/', $placa)) {
        flash('err', 'Placa inválida. Use o formato ABC-1234 ou ABC1D23.');
    } else {
        try {
            $d = dados_veiculo_do_formulario();   // marca (lista ou digitada), modelo e ano
            if (isset($d['erro'])) {
                flash('err', $d['erro']);
            } elseif (acao() === 'criar') {
                db_exec('INSERT INTO veiculo (id, placa, ano, cor, idModelo, idCliente) VALUES (?,?,?,?,?,?)',
                        [uuid(), $placa, $d['ano'], mb_substr($cor, 0, 10), $d['idModelo'], $u['id']]);
                flash('ok', "Veículo $placa cadastrado com sucesso!");
            } else {
                db_exec('UPDATE veiculo SET placa=?, ano=?, cor=?, idModelo=? WHERE id=? AND idCliente=?',
                        [$placa, $d['ano'], mb_substr($cor, 0, 10), $d['idModelo'], $id, $u['id']]);
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
    if (!db_val('SELECT 1 FROM veiculo WHERE id = ? AND idCliente = ?', [$id, $u['id']])) {
        flash('err', 'Veículo não encontrado.');
    } elseif (db_val('SELECT 1 FROM protocolo WHERE idVeiculo = ? AND status IN ' . SQL_ATIVOS, [$id])) {
        flash('err', 'Este veículo possui um atendimento em andamento e não pode ser removido agora.');
    } else {
        $arquivosFotos = arquivos_fotos('v.id = ? AND v.idCliente = ?', [$id, $u['id']]);
        db_exec('DELETE FROM veiculo WHERE id = ? AND idCliente = ?', [$id, $u['id']]);
        apagar_arquivos_fotos($arquivosFotos);
        flash('ok', 'Veículo removido.');
    }
    redirecionar('meus_veiculos.php');
}

// ─── LEITURA ─────────────────────────────────────────────────────────────────
$veiculos = db_all("SELECT v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.id AS idMarca, ma.nome AS marca,
                           " . SQL_STATUS_ATIVO . " AS statusAtivo,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id) AS ocorrencias,
                           (SELECT MAX(p.dataFinalizacao) FROM protocolo p WHERE p.idVeiculo = v.id AND p.status = 'FINALIZADO') AS ultimo,
                           (SELECT MAX(p.kmEntrada) FROM protocolo p WHERE p.idVeiculo = v.id) AS km
                    FROM veiculo v
                    JOIN modelo mo ON mo.id = v.idModelo
                    JOIN marca ma  ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa", [$u['id']]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Meus Veículos'); ?>
  <style>
    .vehicles-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .vehicle-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: border-color 0.15s; }
    .vehicle-card:hover { border-color: var(--border-strong); }
    .vehicle-card-top { padding: 20px 22px 16px; border-bottom: 1px solid var(--border); }
    .vehicle-plate { font-family: 'DM Mono', monospace; font-size: 16px; font-weight: 500; letter-spacing: 0.06em; background: var(--bg); border: 1px solid var(--border); border-radius: 7px; padding: 6px 12px; display: inline-block; margin-bottom: 12px; }
    .vehicle-name { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
    .vehicle-year { font-size: 12px; color: var(--muted); }
    .vehicle-card-body { padding: 14px 22px 18px; }
    .vehicle-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .vehicle-meta .info-item span { font-size: 13px; }
    .vehicle-status-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 14px; }
    .vehicle-actions { display: flex; gap: 8px; }
    .vehicle-actions > * { flex: 1; }
    .vehicle-actions .act-btn { justify-content: center; width: 100%; padding: 7px 10px; }
    .add-card { border: 1px dashed var(--border-strong); background: none; display: flex; align-items: center; justify-content: center; min-height: 200px; cursor: pointer; width: 100%; border-radius: 12px; color: var(--muted); }
    .add-card:hover { border-color: var(--text); color: var(--text); }
    .add-card svg { width: 28px; height: 28px; margin: 0 auto 10px; opacity: 0.5; display: block; }
    .add-card-label { font-size: 13.5px; font-weight: 500; }
    .add-card-sub { font-size: 12px; opacity: 0.7; margin-top: 3px; }
    @media (max-width: 760px) {
      .vehicles-grid { grid-template-columns: 1fr; }
      .vehicle-card-top, .vehicle-card-body { padding-left: 16px; padding-right: 16px; }
      .add-card { min-height: 120px; }
    }
  </style>
</head>
<body>
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
          <div class="info-item"><label>Último serviço</label><span><?= fmt_data($v['ultimo']) ?></span></div>
          <div class="info-item"><label>Última km</label><span><?= fmt_km($v['km']) ?></span></div>
        </div>
        <div class="vehicle-status-row">
          <?= badge($st) ?>
          <span class="mono text-muted" style="font-size:12px"><?= plural((int)$v['ocorrencias'], 'ocorrência', 'ocorrências') ?></span>
        </div>
        <div class="vehicle-actions">
          <a class="act-btn" href="visualizar_ocorrencias.php?v=<?= urlencode($v['id']) ?>">Ocorrências</a>
          <button class="act-btn" onclick='openModal(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Editar</button>
          <form method="POST" data-confirm="Remover o veículo <?= e($v['placa']) ?>? O histórico de ocorrências dele também será apagado." data-confirm-title="Remover veículo" data-confirm-ok="Remover" data-confirm-danger>
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="excluir"/>
            <input type="hidden" name="id" value="<?= e($v['id']) ?>"/>
            <button class="act-btn danger" type="submit">Remover</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <button type="button" class="add-card" onclick="openModal()">
      <span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 5v14M5 12h14"/></svg>
        <span class="add-card-label" style="display:block">Adicionar veículo</span>
        <span class="add-card-sub" style="display:block">Placa, modelo, marca e ano</span>
      </span>
    </button>
  </div>
</main>

<!-- MODAL CADASTRAR / EDITAR VEÍCULO -->
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
        <label for="f-placa">Placa</label>
        <input type="text" name="placa" id="f-placa" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase" data-mask="placa" required/>
      </div>
      <?php campos_marca_modelo_veiculo(); ?>
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
  function openModal(v) {
    const edit = !!v;
    document.getElementById('modal-title').textContent = edit ? 'Editar veículo' : 'Cadastrar veículo';
    document.getElementById('f-submit').textContent   = edit ? 'Salvar alterações' : 'Cadastrar veículo';
    document.getElementById('f-action').value = edit ? 'editar' : 'criar';
    document.getElementById('f-id').value     = edit ? v.id : '';
    document.getElementById('f-placa').value  = edit ? v.placa : '';
    preencherMarca(edit ? v.marca : '');
    document.getElementById('f-modelo').value = edit ? v.modelo : '';
    document.getElementById('f-ano').value    = edit ? v.ano : '';
    document.getElementById('f-cor').value    = edit ? v.cor : '';
    abrirModal('modal');
  }
  <?php if (isset($_GET['novo'])): ?>openModal();<?php endif; ?>
</script>
</body>
</html>
