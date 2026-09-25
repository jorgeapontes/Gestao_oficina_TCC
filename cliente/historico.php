<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

// Veículos com a contagem de atendimentos finalizados.
$veiculos = db_all("SELECT v.id, v.placa, v.ano, mo.nome AS modelo, ma.nome AS marca,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id AND p.status = 'FINALIZADO') AS atendimentos
                    FROM veiculo v JOIN modelo mo ON mo.id = v.idModelo JOIN marca ma ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa", [$u['id']]);
$totalAtend = array_sum(array_column($veiculos, 'atendimentos'));

$filtroV = $_GET['v'] ?? '';
if (!in_array($filtroV, array_column($veiculos, 'id'), true)) $filtroV = '';

$params = [$u['id']];
$sql = "SELECT p.numero, p.dataFinalizacao, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
               v.placa, mo.nome AS modelo, col.nome AS colaborador
        FROM protocolo p
        JOIN ocorrencia o    ON o.id = p.idOcorrencia
        JOIN veiculo v       ON v.id = p.idVeiculo
        JOIN modelo mo       ON mo.id = v.idModelo
        JOIN colaborador col ON col.id = p.responsavel
        WHERE v.idCliente = ? AND p.status = 'FINALIZADO'";
if ($filtroV !== '') { $sql .= ' AND v.id = ?'; $params[] = $filtroV; }
$protocolos = db_all($sql . ' ORDER BY p.dataFinalizacao DESC, p.numero DESC', $params);

$itens = [];
if ($protocolos) {
    $ids = array_column($protocolos, 'idOcorrencia');
    $rows = db_all('SELECT idOcorrencia, descricao FROM itemocorrencia
                    WHERE idOcorrencia IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                    ORDER BY dataHora, id', $ids);
    foreach ($rows as $r) $itens[$r['idOcorrencia']][] = $r['descricao'];
}

// Protocolo aberto (expandido) por padrão: o pedido na URL ou o mais recente.
$expandido = (int)($_GET['numero'] ?? ($protocolos[0]['numero'] ?? 0));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Meu Histórico</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #F5F4F2;
      --surface:   #FFFFFF;
      --border:    #E4E2DE;
      --text:      #1A1917;
      --muted:     #7A7872;
      --accent:    #1A1917;
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
      width: var(--sidebar-w); min-height: 100vh; background: var(--surface);
      border-right: 1px solid var(--border); display: flex; flex-direction: column;
      padding: 28px 0; position: fixed; top: 0; left: 0;
    }
    .logo { padding: 0 24px 28px; border-bottom: 1px solid var(--border); }
    .logo-mark { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; letter-spacing: 0.08em; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
    .logo-name { font-size: 15px; font-weight: 600; color: var(--text); line-height: 1.3; }
    nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 2px; }
    .nav-label { font-size: 10px; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); padding: 14px 12px 6px; }
    .nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; font-size: 14px; font-weight: 400; color: var(--muted); cursor: pointer; text-decoration: none; transition: background 0.15s, color 0.15s; }
    .nav-item:hover { background: var(--bg); color: var(--text); }
    .nav-item.active { background: var(--text); color: #fff; }
    .nav-item .icon { width: 18px; height: 18px; opacity: 0.7; flex-shrink: 0; }
    .nav-item.active .icon { opacity: 1; }
    .sidebar-footer { padding: 20px 24px 0; border-top: 1px solid var(--border); }
    .user-chip { display: flex; align-items: center; gap: 10px; }
    .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--text); color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .user-info { min-width: 0; }
    .user-name { font-size: 13px; font-weight: 500; }
    .user-role { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }

    main { margin-left: var(--sidebar-w); flex: 1; padding: 40px 48px; max-width: calc(100% - var(--sidebar-w)); }

    .topbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-bottom: 32px; }
    .notif-btn { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }
    .notif-dot { width: 7px; height: 7px; background: #C0392B; border-radius: 50%; position: absolute; top: 7px; right: 7px; border: 1.5px solid white; }

    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .page-date { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }

    /* vehicle selector */
    .vehicle-tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }
    .vtab {
      display: flex;
      flex-direction: column;
      gap: 3px;
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--surface);
      cursor: pointer;
      transition: all 0.15s;
      min-width: 130px;
    }
    .vtab:hover { border-color: var(--text); }
    .vtab.active { border-color: var(--text); background: var(--text); color: #fff; }
    .vtab-plate { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; }
    .vtab-model { font-size: 11.5px; opacity: 0.7; }
    .vtab-count { font-size: 11px; opacity: 0.6; margin-top: 2px; }

    /* timeline */
    .timeline { display: flex; flex-direction: column; gap: 0; }

    .timeline-month {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 16px 0 10px;
    }
    .timeline-month-label {
      font-size: 11px;
      font-weight: 500;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
      white-space: nowrap;
    }
    .timeline-month-line { flex: 1; height: 1px; background: var(--border); }

    .protocol-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 12px;
      transition: border-color 0.15s;
      cursor: pointer;
    }
    .protocol-card:hover { border-color: #ccc; }
    .protocol-card.expanded { border-color: var(--text); }

    .protocol-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
    }
    .protocol-left { display: flex; align-items: center; gap: 14px; }
    .protocol-icon {
      width: 36px; height: 36px;
      border-radius: 8px;
      background: var(--bg);
      border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .protocol-proto {
      font-family: 'DM Mono', monospace;
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 3px;
    }
    .protocol-date { font-size: 13.5px; font-weight: 500; }
    .protocol-right { display: flex; align-items: center; gap: 12px; }
    .protocol-items { font-size: 12px; color: var(--muted); }

    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.done { background: var(--tag-done); color: var(--tag-done-t); }

    .chevron {
      width: 16px; height: 16px;
      color: var(--muted);
      transition: transform 0.2s;
    }
    .protocol-card.expanded .chevron { transform: rotate(180deg); }

    .protocol-body {
      border-top: 1px solid var(--border);
      padding: 18px 20px;
      display: none;
    }
    .protocol-card.expanded .protocol-body { display: block; }

    .ocor-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .ocor-item {
      display: flex;
      gap: 12px;
      padding: 12px 14px;
      background: var(--bg);
      border-radius: 8px;
      border: 1px solid var(--border);
      font-size: 13px;
      line-height: 1.5;
    }
    .ocor-num {
      font-family: 'DM Mono', monospace;
      font-size: 11px;
      color: var(--muted);
      width: 18px;
      flex-shrink: 0;
      padding-top: 2px;
    }

    .protocol-meta-row {
      display: flex;
      gap: 24px;
      padding-top: 14px;
      border-top: 1px solid var(--border);
      font-size: 12px;
    }
    .pmeta-item { display: flex; flex-direction: column; gap: 2px; }
    .pmeta-label { color: var(--muted); }
    .pmeta-value { font-weight: 500; }

    .export-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 12px;
      color: var(--muted);
      text-decoration: none;
      margin-left: auto;
      padding: 4px 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.15s;
    }
    .export-link:hover { color: var(--text); border-color: var(--text); }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted);
    }
    .empty-state-icon { font-size: 36px; margin-bottom: 12px; }
    .empty-state-title { font-size: 15px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
    .empty-state-sub { font-size: 13px; }
  </style>
</head>
<body>
<style>
  a.vtab, a.export-link { text-decoration: none; color: inherit; }
  a.vtab { display: block; }
</style>

<?php sidebar('historico'); ?>

<!-- MAIN -->
<main>
  <div class="page-header">
    <div>
      <div class="page-title">Histórico de Atendimentos</div>
      <div class="page-sub">Todos os protocolos finalizados dos seus veículos</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <!-- vehicle selector -->
  <?php if ($veiculos): ?>
  <div class="vehicle-tabs">
    <?php foreach ($veiculos as $v): ?>
    <a class="vtab<?= $filtroV === $v['id'] ? ' active' : '' ?>" href="?v=<?= urlencode($v['id']) ?>">
      <div class="vtab-plate"><?= e($v['placa']) ?></div>
      <div class="vtab-model"><?= e($v['marca'] . ' ' . $v['modelo']) ?> · <?= e($v['ano']) ?></div>
      <div class="vtab-count"><?= plural((int)$v['atendimentos'], 'atendimento', 'atendimentos') ?></div>
    </a>
    <?php endforeach; ?>
    <a class="vtab<?= $filtroV === '' ? ' active' : '' ?>" href="historico.php">
      <div class="vtab-plate">Todos</div>
      <div class="vtab-model">Todos os veículos</div>
      <div class="vtab-count"><?= plural($totalAtend, 'atendimento', 'atendimentos') ?></div>
    </a>
  </div>
  <?php endif; ?>

  <!-- timeline -->
  <div class="timeline">
    <?php if (!$protocolos): ?>
      <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <div class="empty-state-title">Nenhum atendimento finalizado</div>
        <div class="empty-state-sub">Quando um atendimento for concluído pela oficina, ele aparecerá aqui.</div>
      </div>
    <?php endif; ?>

    <?php $mesAtual = null; foreach ($protocolos as $p):
      $mes = mes_ano($p['dataFinalizacao']);
      $lista = $itens[$p['idOcorrencia']] ?? [];
    ?>
      <?php if ($mes !== $mesAtual): $mesAtual = $mes; ?>
      <div class="timeline-month">
        <div class="timeline-month-label"><?= e($mes) ?></div>
        <div class="timeline-month-line"></div>
      </div>
      <?php endif; ?>

      <div class="protocol-card<?= (int)$p['numero'] === $expandido ? ' expanded' : '' ?>" id="p<?= (int)$p['numero'] ?>">
        <div class="protocol-header" onclick="this.closest('.protocol-card').classList.toggle('expanded')">
          <div class="protocol-left">
            <div class="protocol-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div>
              <div class="protocol-proto"><?= proto_num($p['numero']) ?></div>
              <div class="protocol-date"><?= fmt_data_extenso($p['dataFinalizacao']) ?></div>
            </div>
          </div>
          <div class="protocol-right">
            <span class="protocol-items"><?= plural(count($lista), 'item', 'itens') ?></span>
            <span class="badge done">Finalizado</span>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </div>
        </div>
        <div class="protocol-body">
          <div class="ocor-list">
            <?php foreach ($lista as $n => $desc): ?>
            <div class="ocor-item">
              <div class="ocor-num"><?= str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) ?></div>
              <div><?= e($desc) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="protocol-meta-row">
            <div class="pmeta-item">
              <div class="pmeta-label">Colaborador</div>
              <div class="pmeta-value"><?= e($p['colaborador']) ?></div>
            </div>
            <div class="pmeta-item">
              <div class="pmeta-label">Veículo</div>
              <div class="pmeta-value"><?= e($p['placa'] . ' · ' . $p['modelo']) ?></div>
            </div>
            <div class="pmeta-item">
              <div class="pmeta-label">Aberto em</div>
              <div class="pmeta-value"><?= fmt_data($p['dataRegistro']) ?></div>
            </div>
            <a class="export-link" href="../comum/protocolo.php?numero=<?= (int)$p['numero'] ?>" target="_blank">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
              PDF
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
