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
          JOIN colaborador col ON col.id = p.responsavel
          LEFT JOIN avaliacao a ON a.numeroProtocolo = p.numero ";
$from = $joins . 'WHERE ' . implode(' AND ', $where);
$select = "SELECT p.numero, p.dataFinalizacao, p.kmEntrada, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                  c.nome AS cliente, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                  col.nome AS colaborador, col.cargo, a.nota, a.comentario,
                  (SELECT COUNT(*) FROM itemocorrencia i WHERE i.idOcorrencia = o.id) AS itens ";

// ─── EXPORTAR CSV (respeita os filtros) ──────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all($select . $from . ' ORDER BY p.numero DESC', $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historico_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
    fputcsv($out, ['Protocolo', 'Cliente', 'Placa', 'Veículo', 'KM entrada', 'Colaborador', 'Aberto em', 'Finalizado em', 'Itens', 'Avaliação', 'Descrição'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [proto_num($r['numero']), $r['cliente'], $r['placa'], $r['marca'] . ' ' . $r['modelo'], $r['kmEntrada'],
                       $r['colaborador'], fmt_data_hora($r['dataRegistro']), fmt_data($r['dataFinalizacao']),
                       $r['itens'], $r['nota'], $r['descricao']], ';');
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
$fotosSel  = $sel ? fotos_protocolo((int)$sel['numero']) : [];
$etapasSel = $sel ? etapas_protocolo((int)$sel['numero']) : [];

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
  <?php head_comum('Histórico de Atendimentos'); ?>
  <style>
    .layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; }
    .detail-panel { position: sticky; top: 24px; max-height: calc(100vh - 48px); overflow-y: auto; }
    .detail-header { padding: 18px 22px; border-bottom: 1px solid var(--border); }
    .detail-proto { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); margin-bottom: 6px; }
    .detail-title { font-size: 15px; font-weight: 600; }
    .detail-body { padding: 20px 22px; display: flex; flex-direction: column; gap: 20px; }
    .detail-body .kv-list { padding: 0; gap: 0; }
    .detail-body .kv-row { padding: 6px 0; border-bottom: 1px solid var(--border); }
    .detail-body .kv-row:last-child { border-bottom: none; }
    .detail-body .timeline-steps { padding: 0; }
    .detail-body .photo-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .rating-comment { font-size: 13px; line-height: 1.5; margin-top: 6px; }
    @media (max-width: 1200px) {
      .layout { grid-template-columns: minmax(0, 1fr); }
      .detail-panel { position: static; max-height: none; }
    }
    @media (max-width: 760px) {
      .detail-header, .detail-body { padding-left: 16px; padding-right: 16px; }
    }
  </style>
</head>
<body>
<?php sidebar('historico'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar por protocolo, cliente, placa…"/>
      <?php if ($periodo !== 'todos'): ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>"/><?php endif; ?>
      <?php if ($colab !== ''): ?><input type="hidden" name="colab" value="<?= e($colab) ?>"/><?php endif; ?>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Histórico de Atendimentos</div>
      <div class="page-sub">Todos os protocolos finalizados — imutáveis e rastreáveis</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <div class="filters-row">
    <?php foreach ($periodos as $chave => [$rotulo]): ?>
      <a class="filter-chip<?= $chave === $periodo ? ' active' : '' ?>" href="<?= e(qs(['periodo' => $chave === 'todos' ? null : $chave, 'page' => null, 'numero' => null])) ?>"><span class="chip-dot"></span><?= e($rotulo) ?></a>
    <?php endforeach; ?>

    <form method="GET" style="display:contents">
      <?php foreach (['periodo' => $periodo !== 'todos' ? $periodo : '', 'q' => $busca] as $k => $val): if ($val !== ''): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= e($val) ?>"/>
      <?php endif; endforeach; ?>
      <select class="filter-select" name="colab" onchange="this.form.submit()" aria-label="Filtrar por colaborador">
        <option value="">Todos os colaboradores</option>
        <?php foreach ($colaboradores as $c): ?>
          <option value="<?= e($c['id']) ?>" <?= $colab === $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="filters-right">
      <a class="btn-outline btn-sm" href="<?= e(qs(['export' => 'csv', 'page' => null, 'numero' => null])) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Exportar CSV
      </a>
    </div>
  </div>

  <div class="layout">
    <div class="card">
      <div class="card-header">
        <div class="card-title">Protocolos finalizados</div>
        <div class="card-meta"><?= plural($total, 'registro', 'registros') ?></div>
      </div>
      <table class="table-stack">
        <thead>
          <tr>
            <th>Protocolo</th>
            <th>Cliente</th>
            <th>Veículo</th>
            <th>Colaborador</th>
            <th>Data</th>
            <th>Avaliação</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$protocolos): ?>
            <tr class="empty-row"><td colspan="7">Nenhum protocolo finalizado encontrado.</td></tr>
          <?php endif; ?>
          <?php foreach ($protocolos as $p): $href = qs(['numero' => $p['numero']]) . '#detalhe'; ?>
          <tr data-href="<?= e($href) ?>"<?= $sel && $sel['numero'] == $p['numero'] ? ' class="selected"' : '' ?>>
            <td data-label="Protocolo"><span class="td-proto"><?= proto_num($p['numero']) ?></span></td>
            <td data-label="Cliente"><?= e($p['cliente']) ?></td>
            <td data-label="Veículo"><span class="td-plate"><?= e($p['placa']) ?></span></td>
            <td data-label="Colaborador"><?= e($p['colaborador']) ?></td>
            <td data-label="Data" class="td-mono text-muted"><?= fmt_data($p['dataFinalizacao']) ?></td>
            <td data-label="Avaliação"><?= $p['nota'] ? estrelas((int)$p['nota']) : '<span class="text-muted">—</span>' ?></td>
            <td class="cell-actions"><a class="act-btn" href="<?= e($href) ?>">Ver detalhes →</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pagination">
        <div class="pag-info">
          <?php if ($total): ?>Mostrando <?= $offset + 1 ?>–<?= $offset + count($protocolos) ?> de <?= $total ?> protocolos<?php else: ?>Nenhum registro<?php endif; ?>
        </div>
        <div class="pag-controls">
          <a class="pag-btn" href="<?= e(qs(['page' => max(1, $pagina - 1), 'numero' => null])) ?>" aria-label="Página anterior">‹</a>
          <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
            <a class="pag-btn<?= $i === $pagina ? ' active' : '' ?>" href="<?= e(qs(['page' => $i, 'numero' => null])) ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a class="pag-btn" href="<?= e(qs(['page' => min($paginas, $pagina + 1), 'numero' => null])) ?>" aria-label="Próxima página">›</a>
        </div>
      </div>
    </div>

    <div class="card detail-panel" id="detalhe">
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
        <?php if ($sel['nota']): ?>
        <div>
          <div class="section-label">Avaliação do cliente</div>
          <?= estrelas((int)$sel['nota']) ?>
          <?php if ($sel['comentario']): ?><div class="rating-comment">“<?= e($sel['comentario']) ?>”</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div>
          <div class="section-label">Veículo</div>
          <div class="kv-list">
            <div class="kv-row"><span class="kv-key">Placa</span><span class="kv-val mono"><?= e($sel['placa']) ?></span></div>
            <div class="kv-row"><span class="kv-key">Modelo</span><span class="kv-val"><?= e($sel['marca'] . ' ' . $sel['modelo']) ?></span></div>
            <div class="kv-row"><span class="kv-key">Ano / Cor</span><span class="kv-val"><?= e($sel['ano'] . ' · ' . $sel['cor']) ?></span></div>
            <div class="kv-row"><span class="kv-key">KM de entrada</span><span class="kv-val"><?= fmt_km($sel['kmEntrada']) ?></span></div>
          </div>
        </div>

        <div>
          <div class="section-label">Colaborador responsável</div>
          <div class="kv-list">
            <div class="kv-row"><span class="kv-key">Nome</span><span class="kv-val"><?= e($sel['colaborador']) ?></span></div>
            <div class="kv-row"><span class="kv-key">Cargo</span><span class="kv-val"><?= e($sel['cargo']) ?></span></div>
          </div>
        </div>

        <div>
          <div class="section-label">Ocorrência<?= $sel['componentes'] ? ' · ' . e($sel['componentes']) : '' ?></div>
          <div style="font-size:13px;line-height:1.5"><?= e($sel['descricao']) ?></div>
        </div>

        <div>
          <div class="section-label">Itens registrados (<?= count($itensSel) ?>)</div>
          <div class="item-list">
            <?php foreach ($itensSel as $i => $it): ?>
            <div class="item-view">
              <span class="item-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <div>
                <div class="item-desc" style="font-size:12.5px"><?= e($it['descricao']) ?></div>
                <div class="item-meta"><?= e($it['colaborador']) ?> · <?= date('d/m H:i', strtotime($it['dataHora'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($fotosSel): ?>
        <div>
          <div class="section-label">Fotos (<?= count($fotosSel) ?>)</div>
          <div class="photo-grid">
            <?php foreach ($fotosSel as $f): $src = '../' . PASTA_FOTOS . $f['arquivo']; ?>
              <div class="photo"><a href="<?= e($src) ?>" data-lightbox data-caption="<?= e($f['legenda'] ?? '') ?>"><img src="<?= e($src) ?>" alt="<?= e($f['legenda'] ?: 'Foto') ?>" loading="lazy"/></a></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div>
          <div class="section-label">Linha do tempo</div>
          <?php linha_do_tempo($etapasSel); ?>
        </div>
      </div>

      <div class="card-footer" style="justify-content:stretch">
        <a class="btn-primary" style="flex:1" href="../comum/protocolo.php?numero=<?= (int)$sel['numero'] ?>" target="_blank">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Exportar PDF
        </a>
        <a class="btn-outline" href="../colaborador/atendimento.php?numero=<?= (int)$sel['numero'] ?>" title="Abrir atendimento">Abrir</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php flash_html(); ?>
<script>
  // No desktop o painel fica ao lado da lista: não precisa rolar até ele.
  if (location.hash === '#detalhe' && window.innerWidth > 1200) {
    window.addEventListener('load', () => setTimeout(() => window.scrollTo(0, 0), 0));
  }
</script>
</body>
</html>
