<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

$numero = (int)($_GET['numero'] ?? $_POST['numero'] ?? 0);

function carregar_protocolo(int $numero): ?array {
    return db_one("SELECT p.numero, p.status, p.dataFinalizacao, p.responsavel,
                          o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                          v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                          c.nome AS cliente, c.telefone, c.email AS clienteEmail,
                          col.nome AS responsavelNome, col.cargo AS responsavelCargo
                   FROM protocolo p
                   JOIN ocorrencia o   ON o.id = p.idOcorrencia
                   JOIN veiculo v      ON v.id = p.idVeiculo
                   JOIN modelo mo      ON mo.id = v.idModelo
                   JOIN marca ma       ON ma.id = mo.idMarca
                   JOIN cliente c      ON c.id = v.idCliente
                   JOIN colaborador col ON col.id = p.responsavel
                   WHERE p.numero = ?", [$numero]);
}

$p = carregar_protocolo($numero);
if (!$p) {
    flash('err', 'Atendimento não encontrado.');
    redirecionar('dashboard.php');
}
$ativo = in_array($p['status'], ['ABERTO', 'EM_ATENDIMENTO'], true);
$self  = 'atendimento.php?numero=' . $numero;

// ─── AÇÕES ───────────────────────────────────────────────────────────────────
// Protocolos finalizados/cancelados são imutáveis: nenhuma ação é aceita.
if (is_post() && !$ativo) {
    flash('err', 'Este atendimento já foi encerrado e não pode ser alterado.');
    redirecionar($self);
}

if (acao() === 'item') {
    $desc = post('descricao');
    if ($desc === '') {
        flash('err', 'Descreva o item antes de adicionar.');
    } else {
        db_exec('INSERT INTO itemocorrencia (id, descricao, idOcorrencia, idColaborador) VALUES (?,?,?,?)',
                [uuid(), mb_substr($desc, 0, 200), $p['idOcorrencia'], $u['id']]);
        flash('ok', 'Item adicionado à ocorrência.');
    }
    redirecionar($self);
}

if (acao() === 'status') {
    $novo = post('status');
    $permitidos = $p['status'] === 'ABERTO' ? ['EM_ATENDIMENTO', 'FINALIZADO', 'CANCELADO'] : ['FINALIZADO', 'CANCELADO'];
    if (!in_array($novo, $permitidos, true)) {
        flash('err', 'Mudança de status inválida.');
    } else {
        mudar_status_protocolo($numero, $novo);
        // Quem inicia um atendimento aberto passa a ser o responsável por ele.
        if ($novo === 'EM_ATENDIMENTO' && $p['responsavel'] !== $u['id']) {
            db_exec('UPDATE protocolo SET responsavel = ? WHERE numero = ?', [$u['id'], $numero]);
        }
        flash('ok', match ($novo) {
            'EM_ATENDIMENTO' => 'Atendimento iniciado.',
            'FINALIZADO'     => 'Atendimento finalizado. O protocolo foi para o histórico.',
            'CANCELADO'      => 'Atendimento cancelado.',
        });
    }
    redirecionar($self);
}

$itens = db_all('SELECT i.descricao, i.dataHora, c.nome AS colaborador
                 FROM itemocorrencia i JOIN colaborador c ON c.id = i.idColaborador
                 WHERE i.idOcorrencia = ? ORDER BY i.dataHora, i.id', [$p['idOcorrencia']]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Atendimento</title>
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
  .badge.done { background: var(--tag-done); color: var(--tag-done-t); }
  .page-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 32px; gap: 16px; }
  .page-head .page-sub { margin-bottom: 0; }
  .proto-code { font-family: 'DM Mono', monospace; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
  .occ-summary { padding: 18px 22px; border-bottom: 1px solid var(--border); display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .occ-summary .info-item.full { grid-column: 1 / -1; }
  .item-view { display: flex; gap: 12px; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: 9px; }
  .item-view .item-num { padding-top: 1px; width: auto; }
  .item-desc { font-size: 13.5px; line-height: 1.5; }
  .item-meta { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; margin-top: 4px; }
  .status-actions { display: flex; gap: 10px; flex-wrap: wrap; }
  .btn-danger-outline { padding: 9px 20px; background: transparent; color: #C0392B; border: 1px solid #F0C4BF; border-radius: 8px; font-family: inherit; font-size: 13.5px; font-weight: 500; cursor: pointer; }
  .btn-danger-outline:hover { background: #FEF0EE; }
  .closed-note { padding: 14px 22px; font-size: 13px; color: var(--muted); border-top: 1px solid var(--border); }
</style>

<?php sidebar('ocorrencias'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="veiculos.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" placeholder="Buscar cliente, veículo, placa..."/>
    </form>
  </div>

  <div class="page-head">
    <div>
      <div class="proto-code">Protocolo <?= proto_num($p['numero']) ?> · aberto em <?= fmt_data_hora($p['dataRegistro']) ?></div>
      <div class="page-title">Atendimento — <?= e($p['placa']) ?></div>
      <div class="page-sub"><?= e($p['marca'] . ' ' . $p['modelo']) ?> · <?= e($p['cliente']) ?></div>
    </div>
    <?= badge(status_protocolo($p['status'])) ?>
  </div>

  <div class="two-col">

    <div class="card">
      <div class="card-header">
        <div class="card-title">Ocorrência</div>
        <span style="font-size:12px;color:var(--muted)"><?= plural(count($itens), 'item', 'itens') ?></span>
      </div>

      <div class="occ-summary">
        <div class="info-item"><label>Componente</label><span><?= e($p['componentes'] ?: '—') ?></span></div>
        <div class="info-item"><label>Responsável</label><span><?= e($p['responsavelNome']) ?></span></div>
        <div class="info-item full"><label>Resumo</label><span style="font-weight:400"><?= e($p['descricao']) ?></span></div>
      </div>

      <div class="form-body">
        <div class="items-list" style="padding:0">
          <?php foreach ($itens as $i => $it): ?>
            <div class="item-view">
              <span class="item-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <div>
                <div class="item-desc"><?= e($it['descricao']) ?></div>
                <div class="item-meta"><?= e($it['colaborador']) ?> · <?= fmt_data_hora($it['dataHora']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($ativo): ?>
          <form method="POST" class="field">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="item"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <label>Adicionar item</label>
            <div style="display:flex;gap:8px;align-items:flex-start">
              <textarea name="descricao" rows="2" maxlength="200" placeholder="Novo problema identificado ou serviço realizado..." required></textarea>
              <button type="submit" class="btn-primary" style="flex-shrink:0">Adicionar</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($ativo): ?>
        <div class="form-actions" style="justify-content:space-between">
          <form method="POST" onsubmit="return confirm('Cancelar este atendimento? Esta ação não pode ser desfeita.')">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="status"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <button type="submit" name="status" value="CANCELADO" class="btn-danger-outline">Cancelar atendimento</button>
          </form>
          <form method="POST" class="status-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="status"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <?php if ($p['status'] === 'ABERTO'): ?>
              <button type="submit" name="status" value="EM_ATENDIMENTO" class="btn-outline">Iniciar atendimento</button>
            <?php endif; ?>
            <button type="submit" name="status" value="FINALIZADO" class="btn-primary"
                    onclick="return confirm('Finalizar o atendimento? O protocolo ficará imutável no histórico.')">Finalizar atendimento</button>
          </form>
        </div>
      <?php else: ?>
        <div class="closed-note">
          Atendimento <?= $p['status'] === 'FINALIZADO' ? 'finalizado' : 'cancelado' ?> em <?= fmt_data($p['dataFinalizacao']) ?>.
          Registros encerrados são imutáveis.
          <?php if ($p['status'] === 'FINALIZADO'): ?>
            <a href="../admin/historico.php?numero=<?= $numero ?>" style="color:var(--text);font-weight:500">Ver no histórico →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="side-card">
      <div class="card">
        <div class="card-header"><div class="card-title">Dados do veículo</div></div>
        <div class="info-list">
          <div class="info-item"><label>Placa</label><span style="font-family:'DM Mono',monospace"><?= e($p['placa']) ?></span></div>
          <div class="info-item"><label>Marca / Modelo</label><span><?= e($p['marca'] . ' ' . $p['modelo']) ?></span></div>
          <div class="info-item"><label>Ano / Cor</label><span><?= e($p['ano'] . ' · ' . $p['cor']) ?></span></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Proprietário</div></div>
        <div class="info-list">
          <div class="info-item"><label>Nome</label><span><?= e($p['cliente']) ?></span></div>
          <div class="info-item"><label>Telefone</label><span><?= e(fmt_tel($p['telefone'])) ?></span></div>
          <div class="info-item"><label>E-mail</label><span><?= e($p['clienteEmail']) ?></span></div>
        </div>
      </div>
    </div>

  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
