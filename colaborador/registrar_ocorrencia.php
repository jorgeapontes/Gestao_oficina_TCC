<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

const COMPONENTES = ['Freios', 'Motor', 'Suspensão', 'Embreagem', 'Transmissão', 'Sistema elétrico', 'Arrefecimento', 'Pneus', 'Outros'];

function buscar_veiculo(string $placa): ?array {
    return db_one("SELECT v.id, v.placa, v.ano, v.cor, v.idCliente, mo.nome AS modelo, ma.nome AS marca,
                          c.nome AS cliente, " . SQL_STATUS_ATIVO . " AS statusAtivo,
                          " . SQL_PROTOCOLO_ATIVO . " AS protocoloAtivo,
                          (SELECT MAX(p.kmEntrada) FROM protocolo p WHERE p.idVeiculo = v.id) AS ultimoKm
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
    $km          = so_digitos(post('kmEntrada'));
    $previsao    = post('previsaoEntrega');
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
    if ($previsao !== '' && !DateTime::createFromFormat('Y-m-d', $previsao)) {
        flash('err', 'Data de previsão inválida.');
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
        db_exec("INSERT INTO protocolo (status, kmEntrada, previsaoEntrega, responsavel, idOcorrencia, idVeiculo)
                 VALUES ('ABERTO',?,?,?,?,?)",
                [$km !== '' ? (int)$km : null, $previsao !== '' ? $previsao : null, $u['id'], $idOc, $v['id']]);
        $numero = $conn->insert_id;
        registrar_etapa($numero, 'ABERTO', $u['id']);
        notificar_cliente($v['idCliente'], $v['placa'] . ' · Atendimento ' . proto_num($numero) . ' aberto',
                          'A oficina registrou uma ocorrência no seu veículo. Acompanhe o andamento pelo painel.',
                          'cliente/visualizar_ocorrencias.php?numero=' . $numero);
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
$podeRegistrar = $veiculo && !$veiculo['protocoloAtivo'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Registrar Ocorrência'); ?>
  <style>
    .vehicle-found {
      border: 1px solid var(--border); border-radius: 8px;
      padding: 14px 16px; background: var(--bg);
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    }
    .vf-plate { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; background: var(--surface); border: 1px solid var(--border); border-radius: 5px; padding: 4px 10px; }
    .vf-info { flex: 1; min-width: 150px; }
    .vf-name { font-size: 13.5px; font-weight: 600; }
    .vf-client { font-size: 12px; color: var(--muted); margin-top: 2px; }

    .items-section { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .items-header { padding: 12px 16px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .items-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; }
    .btn-add-item { display: flex; align-items: center; gap: 5px; border: none; background: none; font-size: 12.5px; font-weight: 500; color: var(--muted); cursor: pointer; }
    .btn-add-item:hover { color: var(--text); }
    .items-list { padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .item-row { display: flex; align-items: flex-start; gap: 8px; }
    .item-row .item-num { padding-top: 12px; width: 22px; }
    .item-input { flex: 1; min-width: 0; border: 1px solid var(--border); border-radius: 7px; padding: 9px 12px; font-size: 14px; background: var(--surface); color: var(--text); outline: none; resize: vertical; min-height: 42px; transition: border-color 0.15s; }
    .item-input::placeholder { color: var(--muted); }
    .item-input:focus { border-color: var(--text); }
    .btn-remove-item { width: 30px; height: 30px; border: 1px solid var(--border); border-radius: 6px; background: transparent; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); margin-top: 6px; flex-shrink: 0; }
    .btn-remove-item:hover { border-color: var(--danger); color: var(--danger); }

    .success-modal { max-width: 380px; text-align: center; }
    .success-modal .modal-body { padding: 36px 32px 28px; gap: 0; }
    .success-icon { font-size: 40px; margin-bottom: 14px; }
    .success-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
    .success-sub { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
    .success-code { font-family: 'DM Mono', monospace; font-size: 22px; font-weight: 500; letter-spacing: 0.05em; margin-bottom: 22px; }
    .success-modal .btn-primary, .success-modal .btn-outline { width: 100%; margin-bottom: 8px; }
    .instructions p { margin-bottom: 10px; }
    .instructions p:last-child { margin-bottom: 0; }
    @media (max-width: 760px) { .item-input { font-size: 16px; } }
  </style>
</head>
<body>
<?php sidebar('ocorrencias'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="veiculos.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" placeholder="Buscar cliente, veículo, placa..."/>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Registrar Ocorrência</div>
      <div class="page-sub">Registre os problemas identificados no veículo do cliente.</div>
    </div>
  </div>

  <div class="two-col side-360">

    <!-- FORMULÁRIO -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Nova ocorrência</div>
      </div>
      <div class="form-body">

        <!-- BUSCA DO VEÍCULO -->
        <form class="field" method="GET">
          <label for="plate-input">Placa do veículo</label>
          <div class="inline-form">
            <input type="text" name="placa" id="plate-input" value="<?= e($placaBusca) ?>" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase" data-mask="placa" autocomplete="off" required/>
            <button type="submit" class="btn-primary">Buscar</button>
          </div>
        </form>

        <?php if ($placaBusca !== '' && !$veiculo): ?>
          <div class="notice err">
            Veículo com placa "<?= e($placaBusca) ?>" não encontrado. Verifique a placa ou
            <a href="veiculos.php?novo=1&amp;placa=<?= urlencode($placaBusca) ?>">cadastre o veículo</a>.
          </div>
        <?php endif; ?>

        <?php if ($veiculo): ?>
          <div class="vehicle-found">
            <span class="vf-plate"><?= e($veiculo['placa']) ?></span>
            <div class="vf-info">
              <div class="vf-name"><?= e($veiculo['marca'] . ' ' . $veiculo['modelo']) ?></div>
              <div class="vf-client">Proprietário: <?= e($veiculo['cliente']) ?></div>
            </div>
            <span class="badge open">Encontrado</span>
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
            <label for="componentes">Componente afetado</label>
            <select name="componentes" id="componentes">
              <option value="">— selecione o componente —</option>
              <?php foreach (COMPONENTES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </div>

          <div class="field-row">
            <div class="field">
              <label for="km">KM de entrada</label>
              <input id="km" type="text" inputmode="numeric" name="kmEntrada" placeholder="<?= $veiculo && $veiculo['ultimoKm'] ? 'Último: ' . e(number_format((int)$veiculo['ultimoKm'], 0, ',', '.')) : 'Ex.: 54000' ?>"/>
            </div>
            <div class="field">
              <label for="previsao">Previsão de entrega</label>
              <input id="previsao" type="date" name="previsaoEntrega" min="<?= date('Y-m-d') ?>"/>
            </div>
          </div>

          <!-- ITENS -->
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
                <button type="button" class="btn-remove-item" onclick="removeItem(this)" title="Remover" aria-label="Remover item">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
              </div>
            </div>
          </div>

          <div class="field">
            <label for="obs">Resumo / observações gerais (opcional)</label>
            <textarea id="obs" name="observacoes" placeholder="Informações adicionais, recomendações ao cliente..." rows="3" maxlength="200"></textarea>
          </div>
        </form>

      </div>
      <div class="form-actions">
        <a class="btn-outline" href="dashboard.php">Cancelar</a>
        <button type="submit" form="occ-form" class="btn-primary" <?= $podeRegistrar ? '' : 'disabled' ?>>Registrar ocorrência</button>
      </div>
    </div>

    <!-- LATERAL -->
    <div class="stack">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Dados do veículo</div>
        </div>
        <div class="kv-list">
          <?php
            $info = $veiculo ? [
              'Placa' => $veiculo['placa'], 'Modelo' => $veiculo['modelo'], 'Marca' => $veiculo['marca'],
              'Ano' => $veiculo['ano'], 'Cor' => $veiculo['cor'], 'Proprietário' => $veiculo['cliente'],
            ] : array_fill_keys(['Placa', 'Modelo', 'Marca', 'Ano', 'Cor', 'Proprietário'], null);
          ?>
          <?php foreach ($info as $k => $val): ?>
            <div class="kv-row"><span class="kv-key"><?= e($k) ?></span><span class="kv-val<?= $val === null ? ' text-muted' : '' ?>"><?= e($val ?? '—') ?></span></div>
          <?php endforeach; ?>
          <div class="kv-row"><span class="kv-key">Status atual</span>
            <?php if ($veiculo): ?><?= badge(status_veiculo($veiculo['statusAtivo'])) ?><?php else: ?><span class="kv-val text-muted">—</span><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Instruções</div>
        </div>
        <div class="card-text instructions">
          <p>1. Busque o veículo pela placa para vinculá-lo à ocorrência.</p>
          <p>2. Informe a quilometragem e, se possível, a previsão de entrega.</p>
          <p>3. Adicione um item por problema ou componente identificado.</p>
          <p>4. Ao registrar, um protocolo é gerado e o cliente é notificado para acompanhar pelo painel.</p>
        </div>
      </div>
    </div>

  </div>
</main>

<?php if ($sucesso): ?>
<div class="modal-backdrop open" id="success-overlay">
  <div class="modal success-modal">
    <div class="modal-body">
      <div class="success-icon">✅</div>
      <div class="success-title">Ocorrência registrada</div>
      <div class="success-sub">A ocorrência foi salva, o protocolo de atendimento foi gerado e o cliente foi notificado.</div>
      <div class="success-code"><?= proto_num($sucesso) ?></div>
      <a class="btn-primary" href="atendimento.php?numero=<?= $sucesso ?>">Abrir atendimento</a>
      <a class="btn-outline" href="registrar_ocorrencia.php">Registrar nova ocorrência</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php flash_html(); ?>

<script>
  const ITEM_HTML = `<textarea class="item-input" name="itens[]" placeholder="Descreva o problema ou componente afetado..." rows="2" maxlength="200"></textarea><button type="button" class="btn-remove-item" onclick="removeItem(this)" title="Remover" aria-label="Remover item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>`;

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
