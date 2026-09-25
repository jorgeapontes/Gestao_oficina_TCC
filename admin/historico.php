<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

// ─── FILTROS ─────────────────────────────────────────────────────────────────
$periodos = [
    'todos'  => ['Todos',            null],
    'mes'    => ['Este mês',         "p.dataFinalizacao >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"],
    '3meses' => ['Últimos 3 meses',  'p.dataFinalizacao >= CURDATE() - INTERVAL 3 MONTH'],
    'ano'    => ['Este ano',         'YEAR(p.dataFinalizacao) = YEAR(CURDATE())'],
];
$periodo = array_key_exists($_GET['periodo'] ?? '', $periodos) ? $_GET['periodo'] : 'todos';
$colab   = $_GET['colab'] ?? '';
$busca   = trim($_GET['q'] ?? '');

$where  = ["p.status = 'FINALIZADO'"];
$params = [];
if ($periodos[$periodo][1]) $where[] = $periodos[$periodo][1];
if ($colab !== '') { $where[] = 'p.responsavel = ?'; $params[] = $colab; }
if ($busca !== '') {
    $where[] = '(c.nome LIKE ? OR v.placa LIKE ? OR REPLACE(v.placa, \'-\', \'\') LIKE ? OR p.numero = ?)';
    array_push($params, "%$busca%", "%$busca%", '%' . str_replace('-', '', $busca) . '%', (int)ltrim($busca, '#0'));
}
$joins = "FROM protocolo p
          JOIN ocorrencia o    ON o.id = p.idOcorrencia
          JOIN veiculo v       ON v.id = p.idVeiculo
          JOIN modelo mo       ON mo.id = v.idModelo
          JOIN marca ma        ON ma.id = mo.idMarca
          JOIN cliente c       ON c.id = v.idCliente
          JOIN colaborador col ON col.id = p.responsavel ";
$from = $joins . 'WHERE ' . implode(' AND ', $where);
$select = "SELECT p.numero, p.dataFinalizacao, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                  c.nome AS cliente, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                  col.nome AS colaborador, col.cargo,
                  (SELECT COUNT(*) FROM itemocorrencia i WHERE i.idOcorrencia = o.id) AS itens ";

// ─── EXPORTAR CSV (respeita os filtros) ──────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all($select . $from . ' ORDER BY p.numero DESC', $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historico_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
    fputcsv($out, ['Protocolo', 'Cliente', 'Placa', 'Veículo', 'Colaborador', 'Aberto em', 'Finalizado em', 'Itens', 'Descrição'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [proto_num($r['numero']), $r['cliente'], $r['placa'], $r['marca'] . ' ' . $r['modelo'],
                       $r['colaborador'], fmt_data_hora($r['dataRegistro']), fmt_data($r['dataFinalizacao']),
                       $r['itens'], $r['descricao']], ';');
    }
    exit;
}

// ─── PAGINAÇÃO ───────────────────────────────────────────────────────────────
$porPagina = 8;
$total     = (int)db_val('SELECT COUNT(*) ' . $from, $params);
$paginas   = max(1, (int)ceil($total / $porPagina));
$pagina    = min(max(1, (int)($_GET['page'] ?? 1)), $paginas);
$offset    = ($pagina - 1) * $porPagina;
$protocolos = db_all($select . $from . " ORDER BY p.numero DESC LIMIT $porPagina OFFSET $offset", $params);

// ─── DETALHE (protocolo selecionado ou o primeiro da lista) ──────────────────
$numSel = (int)($_GET['numero'] ?? ($protocolos[0]['numero'] ?? 0));
$sel = $numSel ? db_one($select . $joins . "WHERE p.status = 'FINALIZADO' AND p.numero = ?", [$numSel]) : null;
$itensSel = $sel ? db_all('SELECT i.descricao, i.dataHora, c.nome AS colaborador
                           FROM itemocorrencia i JOIN colaborador c ON c.id = i.idColaborador
                           WHERE i.idOcorrencia = ? ORDER BY i.dataHora, i.id', [$sel['idOcorrencia']]) : [];

$colaboradores = db_all('SELECT id, nome FROM colaborador ORDER BY nome');

// Monta a query string atual trocando/removendo parâmetros.
function qs(array $troca): string {
    $q = array_filter(array_merge($_GET, $troca), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>IMM – Histórico de Atendimentos</title>
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet" />
  <link rel="stylesheet" href="admin.css">
</head>

<body>
<style>
  a.filter-chip, a.pag-btn, a.export-btn, a.td-action, a.btn-primary, a.btn-secondary { text-decoration: none; }
  a.filter-chip, a.pag-btn { color: inherit; }
  tbody tr[data-href] { cursor: pointer; }
  tbody tr.selected { background: var(--bg); }
  .empty-row td { text-align: center; color: var(--muted); padding: 40px 22px; }
</style>

<?php sidebar('historico'); ?>

<!-- MAIN -->
<main>

  <!-- topbar -->
  <div class="topbar">
    <form class="search-wrap" method="GET">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por protocolo, cliente, placa…"/>
      <?php if ($periodo !== 'todos'): ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>"/><?php endif; ?>
      <?php if ($colab !== ''): ?><input type="hidden" name="colab" value="<?= e($colab) ?>"/><?php endif; ?>
    </form>
  </div>

  <!-- header -->
  <div class="page-header">
    <div>
      <div class="page-title">Histórico de Atendimentos</div>
      <div class="page-sub">Todos os protocolos finalizados — imutáveis e rastreáveis</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <!-- filters -->
  <div class="filters-row">
    <?php foreach ($periodos as $chave => [$rotulo]): ?>
      <a class="filter-chip<?= $chave === $periodo ? ' active' : '' ?>" href="<?= e(qs(['periodo' => $chave === 'todos' ? null : $chave, 'page' => null, 'numero' => null])) ?>"><span class="chip-dot"></span><?= e($rotulo) ?></a>
    <?php endforeach; ?>

    <form method="GET" style="display:contents">
      <?php foreach (['periodo' => $periodo !== 'todos' ? $periodo : '', 'q' => $busca] as $k => $val): if ($val !== ''): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= e($val) ?>"/>
      <?php endif; endforeach; ?>
      <select class="filter-select" name="colab" onchange="this.form.submit()">
        <option value="">Todos os colaboradores</option>
        <?php foreach ($colaboradores as $c): ?>
          <option value="<?= e($c['id']) ?>" <?= $colab === $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="filters-right">
      <a class="export-btn" href="<?= e(qs(['export' => 'csv', 'page' => null, 'numero' => null])) ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" />
        </svg>
        Exportar CSV
      </a>
    </div>
  </div>

  <!-- layout with detail panel -->
  <div class="layout">

    <!-- table -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Protocolos finalizados</div>
        <div class="card-meta"><?= plural($total, 'registro', 'registros') ?></div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Protocolo</th>
            <th>Cliente</th>
            <th>Veículo</th>
            <th>Colaborador</th>
            <th>Data</th>
            <th>Itens</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$protocolos): ?>
            <tr class="empty-row"><td colspan="7">Nenhum protocolo finalizado encontrado.</td></tr>
          <?php endif; ?>
          <?php foreach ($protocolos as $p): $href = qs(['numero' => $p['numero']]); ?>
          <tr data-href="<?= e($href) ?>"<?= $sel && $sel['numero'] == $p['numero'] ? ' class="selected"' : '' ?>>
            <td><span class="td-proto"><?= proto_num($p['numero']) ?></span></td>
            <td><?= e($p['cliente']) ?></td>
            <td><span class="td-plate"><?= e($p['placa']) ?></span></td>
            <td><?= e($p['colaborador']) ?></td>
            <td><span style="font-size:12.5px;color:var(--muted);font-family:'DM Mono',monospace"><?= fmt_data($p['dataFinalizacao']) ?></span></td>
            <td><span style="font-size:12.5px"><?= plural((int)$p['itens'], 'item', 'itens') ?></span></td>
            <td><a class="td-action" href="<?= e($href) ?>">Ver detalhes →</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- pagination -->
      <div class="pagination">
        <div class="pag-info">
          <?php if ($total): ?>Mostrando <?= $offset + 1 ?>–<?= $offset + count($protocolos) ?> de <?= $total ?> protocolos<?php else: ?>Nenhum registro<?php endif; ?>
        </div>
        <div class="pag-controls">
          <a class="pag-btn" href="<?= e(qs(['page' => max(1, $pagina - 1), 'numero' => null])) ?>">‹</a>
          <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
            <a class="pag-btn<?= $i === $pagina ? ' active' : '' ?>" href="<?= e(qs(['page' => $i, 'numero' => null])) ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a class="pag-btn" href="<?= e(qs(['page' => min($paginas, $pagina + 1), 'numero' => null])) ?>">›</a>
        </div>
      </div>
    </div>

    <!-- detail panel -->
    <div class="detail-panel">
      <?php if (!$sel): ?>
        <div class="detail-header">
          <div class="detail-title">Nenhum protocolo selecionado</div>
          <div class="detail-proto" style="margin-top:6px">Selecione um protocolo na lista para ver os detalhes.</div>
        </div>
      <?php else: ?>
      <div class="detail-header">
        <div class="detail-proto"><?= proto_num($sel['numero']) ?> · finalizado em <?= fmt_data_curta($sel['dataFinalizacao']) ?></div>
        <div class="detail-title"><?= e($sel['cliente']) ?></div>
        <div style="margin-top:8px"><span class="badge done">Finalizado</span></div>
      </div>

      <div class="detail-body">
        <div>
          <div class="detail-section-label">Veículo</div>
          <div class="detail-row"><span class="detail-row-label">Placa</span><span class="detail-row-value mono"><?= e($sel['placa']) ?></span></div>
          <div class="detail-row"><span class="detail-row-label">Modelo</span><span class="detail-row-value"><?= e($sel['marca'] . ' ' . $sel['modelo']) ?></span></div>
          <div class="detail-row"><span class="detail-row-label">Ano</span><span class="detail-row-value"><?= e($sel['ano']) ?></span></div>
          <div class="detail-row"><span class="detail-row-label">Cor</span><span class="detail-row-value"><?= e($sel['cor']) ?></span></div>
        </div>

        <div>
          <div class="detail-section-label">Colaborador responsável</div>
          <div class="detail-row"><span class="detail-row-label">Nome</span><span class="detail-row-value"><?= e($sel['colaborador']) ?></span></div>
          <div class="detail-row"><span class="detail-row-label">Cargo</span><span class="detail-row-value"><?= e($sel['cargo']) ?></span></div>
        </div>

        <div>
          <div class="detail-section-label">Ocorrência<?= $sel['componentes'] ? ' · ' . e($sel['componentes']) : '' ?></div>
          <div class="detail-row"><span class="detail-row-label">Aberta em</span><span class="detail-row-value"><?= fmt_data_hora($sel['dataRegistro']) ?></span></div>
          <div style="font-size:13px;line-height:1.5;margin-top:6px"><?= e($sel['descricao']) ?></div>
        </div>

        <div>
          <div class="detail-section-label">Itens registrados (<?= count($itensSel) ?>)</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ($itensSel as $i => $it): ?>
            <div class="ocorrencia-item">
              <?= e($it['descricao']) ?>
              <div class="ocorrencia-meta">Item <?= $i + 1 ?> · <?= e($it['colaborador']) ?> · <?= date('d/m H:i', strtotime($it['dataHora'])) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="detail-footer">
        <a class="btn-primary" href="../comum/protocolo.php?numero=<?= (int)$sel['numero'] ?>" target="_blank" style="text-align:center">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;margin-right:5px;vertical-align:middle">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" />
          </svg>
          Exportar PDF
        </a>
        <a class="btn-secondary" href="../colaborador/atendimento.php?numero=<?= (int)$sel['numero'] ?>" title="Abrir atendimento">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        </a>
      </div>
      <?php endif; ?>
    </div>

  </div>

</main>

<?php flash_html(); ?>

<script>
  document.querySelectorAll('tr[data-href]').forEach(tr => tr.addEventListener('click', () => location.href = tr.dataset.href));
</script>
</body>

</html>
