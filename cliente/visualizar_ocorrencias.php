<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

$veiculos = db_all('SELECT v.id, v.placa, mo.nome AS modelo, ma.nome AS marca
                    FROM veiculo v JOIN modelo mo ON mo.id = v.idModelo JOIN marca ma ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa', [$u['id']]);

$sel = $veiculos[0] ?? null;
foreach ($veiculos as $v) if ($v['id'] === ($_GET['v'] ?? '')) $sel = $v;

// Ocorrências (com protocolo) do veículo selecionado, mais recentes primeiro.
$ocorrencias = $sel ? db_all("SELECT p.numero, p.status, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                                     col.nome AS colaborador, col.cargo
                              FROM protocolo p
                              JOIN ocorrencia o    ON o.id = p.idOcorrencia
                              JOIN colaborador col ON col.id = p.responsavel
                              WHERE p.idVeiculo = ? ORDER BY p.numero DESC", [$sel['id']]) : [];

// Itens de todas as ocorrências listadas, agrupados por ocorrência.
$itens = [];
if ($ocorrencias) {
    $ids = array_column($ocorrencias, 'idOcorrencia');
    $rows = db_all('SELECT idOcorrencia, descricao FROM itemocorrencia
                    WHERE idOcorrencia IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                    ORDER BY dataHora, id', $ids);
    foreach ($rows as $r) $itens[$r['idOcorrencia']][] = $r['descricao'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Ocorrências</title>
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
    .notif-btn { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }
    .notif-dot { width: 7px; height: 7px; background: #C0392B; border-radius: 50%; position: absolute; top: 7px; right: 7px; border: 1.5px solid white; }
    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }

    /* VEHICLE TABS */
    .vehicle-tabs {
      display: flex; gap: 8px; margin-bottom: 28px;
      border-bottom: 1px solid var(--border); padding-bottom: 0;
    }
    .vtab {
      padding: 10px 18px; font-size: 13.5px; font-weight: 500;
      color: var(--muted); border: none; background: none;
      font-family: inherit; cursor: pointer; border-bottom: 2px solid transparent;
      margin-bottom: -1px; transition: color 0.15s;
    }
    .vtab.active { color: var(--text); border-bottom-color: var(--text); }
    .vtab:hover { color: var(--text); }

    /* OCCURRENCE CARD */
    .occurrence-list { display: flex; flex-direction: column; gap: 14px; }
    .occ-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; overflow: hidden;
    }
    .occ-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 22px; border-bottom: 1px solid var(--border);
      cursor: pointer; user-select: none;
    }
    .occ-header:hover { background: #fafaf9; }
    .occ-meta { display: flex; align-items: center; gap: 14px; }
    .occ-id { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }
    .occ-date { font-size: 13px; color: var(--muted); }
    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.open  { background: var(--tag-open);  color: var(--tag-open-t); }
    .badge.prog  { background: var(--tag-prog);  color: var(--tag-prog-t); }
    .badge.done  { background: var(--tag-done);  color: var(--tag-done-t); }
    .occ-right { display: flex; align-items: center; gap: 12px; }
    .occ-toggle { color: var(--muted); transition: transform 0.2s; }
    .occ-card.expanded .occ-toggle { transform: rotate(180deg); }

    .occ-body { display: none; padding: 20px 22px; }
    .occ-card.expanded .occ-body { display: block; }

    .occ-section-label { font-size: 11px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 10px; }
    .occ-collab { font-size: 13px; margin-bottom: 20px; }
    .occ-collab strong { font-weight: 600; }

    .item-list { display: flex; flex-direction: column; gap: 10px; }
    .item-row {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 12px 16px; background: var(--bg);
      border-radius: 9px; border: 1px solid var(--border);
    }
    .item-num {
      font-family: 'DM Mono', monospace; font-size: 11px;
      color: var(--muted); padding-top: 1px; flex-shrink: 0;
    }
    .item-desc { font-size: 13.5px; line-height: 1.5; }

    .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
    .empty-icon { font-size: 32px; margin-bottom: 12px; }
    .empty-title { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
    .empty-sub { font-size: 13px; }
  </style>
</head>
<body>
<style>
  a.vtab { text-decoration: none; }
  .occ-summary { font-size: 13px; color: var(--muted); line-height: 1.5; margin-bottom: 18px; }
</style>

<?php sidebar('ocorrencias'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Ocorrências</div>
      <div class="page-sub">Acompanhe os problemas registrados nos seus veículos.</div>
    </div>
  </div>

  <?php if (!$veiculos): ?>
    <div class="empty-state">
      <div class="empty-icon">🚗</div>
      <div class="empty-title">Nenhum veículo cadastrado</div>
      <div class="empty-sub"><a href="meus_veiculos.php?novo=1" style="color:var(--text)">Cadastre um veículo</a> para acompanhar as ocorrências.</div>
    </div>
  <?php else: ?>

  <!-- VEHICLE TABS -->
  <div class="vehicle-tabs">
    <?php foreach ($veiculos as $v): ?>
      <a class="vtab<?= $v['id'] === $sel['id'] ? ' active' : '' ?>" href="?v=<?= urlencode($v['id']) ?>"><?= e($v['placa']) ?> — <?= e($v['modelo']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$ocorrencias): ?>
    <div class="empty-state">
      <div class="empty-icon">📋</div>
      <div class="empty-title">Nenhuma ocorrência registrada</div>
      <div class="empty-sub">Seu <?= e($sel['marca'] . ' ' . $sel['modelo']) ?> (<?= e($sel['placa']) ?>) não possui ocorrências.</div>
    </div>
  <?php else: ?>
  <div class="occurrence-list">
    <?php foreach ($ocorrencias as $i => $o): ?>
    <div class="occ-card<?= $i === 0 ? ' expanded' : '' ?>">
      <div class="occ-header" onclick="this.parentElement.classList.toggle('expanded')">
        <div class="occ-meta">
          <span class="occ-id"><?= proto_num($o['numero']) ?></span>
          <span class="occ-date"><?= fmt_data_curta($o['dataRegistro']) ?><?= $o['componentes'] ? ' · ' . e($o['componentes']) : '' ?></span>
        </div>
        <div class="occ-right">
          <?= badge(status_protocolo($o['status'])) ?>
          <svg class="occ-toggle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
      </div>
      <div class="occ-body">
        <div class="occ-section-label">Responsável</div>
        <div class="occ-collab" style="margin-bottom:18px;"><strong><?= e($o['colaborador']) ?></strong> · <?= e($o['cargo']) ?></div>
        <div class="occ-section-label">Resumo</div>
        <div class="occ-summary"><?= e($o['descricao']) ?></div>
        <div class="occ-section-label">Itens da ocorrência</div>
        <div class="item-list">
          <?php foreach ($itens[$o['idOcorrencia']] ?? [] as $n => $desc): ?>
          <div class="item-row">
            <span class="item-num"><?= str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) ?></span>
            <span class="item-desc"><?= e($desc) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</main>

<?php flash_html(); ?>
</body>
</html>
