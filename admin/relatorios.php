<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['ADMIN']);

// ─── PERÍODO ─────────────────────────────────────────────────────────────────
$periodos = ['30' => 'Últimos 30 dias', '90' => 'Últimos 3 meses', '365' => 'Últimos 12 meses', 'tudo' => 'Todo o período'];
$periodo  = array_key_exists($_GET['periodo'] ?? '', $periodos) ? $_GET['periodo'] : '365';
$desde    = $periodo === 'tudo' ? '1970-01-01' : date('Y-m-d', strtotime("-$periodo days"));

// ─── INDICADORES DO PERÍODO ──────────────────────────────────────────────────
$kpi = db_one("SELECT COUNT(*) AS finalizados,
                      AVG(DATEDIFF(p.dataFinalizacao, DATE(o.dataRegistro))) AS tempoMedio,
                      SUM(p.previsaoEntrega IS NOT NULL) AS comPrevisao,
                      SUM(p.previsaoEntrega IS NOT NULL AND p.dataFinalizacao <= p.previsaoEntrega) AS noPrazo
               FROM protocolo p JOIN ocorrencia o ON o.id = p.idOcorrencia
               WHERE p.status = 'FINALIZADO' AND p.dataFinalizacao >= ?", [$desde]);
$cancelados = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'CANCELADO' AND dataFinalizacao >= ?", [$desde]);
$aval = db_one("SELECT AVG(a.nota) AS media, COUNT(*) AS total FROM avaliacao a
                JOIN protocolo p ON p.numero = a.numeroProtocolo WHERE p.dataFinalizacao >= ?", [$desde]);
$finalizados = (int)$kpi['finalizados'];
$taxaCancel  = $finalizados + $cancelados ? round($cancelados * 100 / ($finalizados + $cancelados)) : 0;
$pctPrazo    = (int)$kpi['comPrevisao'] ? round($kpi['noPrazo'] * 100 / $kpi['comPrevisao']) : null;

// ─── ATENDIMENTOS POR MÊS (sempre os últimos 12 meses) ───────────────────────
$meses = [];
for ($i = 11; $i >= 0; $i--) {
    $t = strtotime(date('Y-m-01') . " -$i months");
    $meses[date('Y-m', $t)] = ucfirst(mb_substr(MESES[(int)date('n', $t)], 0, 3)) . '/' . date('y', $t);
}
$inicio12 = array_key_first($meses) . '-01';
$abertosMes = array_column(db_all("SELECT DATE_FORMAT(o.dataRegistro, '%Y-%m') AS ym, COUNT(*) AS n
                                   FROM protocolo p JOIN ocorrencia o ON o.id = p.idOcorrencia
                                   WHERE o.dataRegistro >= ? GROUP BY ym", [$inicio12]), 'n', 'ym');
$finalMes   = array_column(db_all("SELECT DATE_FORMAT(dataFinalizacao, '%Y-%m') AS ym, COUNT(*) AS n
                                   FROM protocolo WHERE status = 'FINALIZADO' AND dataFinalizacao >= ? GROUP BY ym", [$inicio12]), 'n', 'ym');

// ─── DESEMPENHO POR COLABORADOR ──────────────────────────────────────────────
$porColab = db_all("SELECT col.nome,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.responsavel = col.id AND p.status IN " . SQL_ATIVOS . ") AS ativos,
                           COUNT(p.numero) AS finalizados,
                           AVG(DATEDIFF(p.dataFinalizacao, DATE(o.dataRegistro))) AS tempoMedio,
                           AVG(a.nota) AS nota, COUNT(a.nota) AS avaliacoes
                    FROM colaborador col
                    LEFT JOIN protocolo p  ON p.responsavel = col.id AND p.status = 'FINALIZADO' AND p.dataFinalizacao >= ?
                    LEFT JOIN ocorrencia o ON o.id = p.idOcorrencia
                    LEFT JOIN avaliacao a  ON a.numeroProtocolo = p.numero
                    GROUP BY col.id, col.nome
                    ORDER BY finalizados DESC, col.nome", [$desde]);

// ─── COMPONENTES MAIS FREQUENTES ─────────────────────────────────────────────
$componentes = db_all("SELECT COALESCE(NULLIF(o.componentes, ''), 'Não informado') AS nome, COUNT(*) AS n
                       FROM ocorrencia o JOIN protocolo p ON p.idOcorrencia = o.id
                       WHERE o.dataRegistro >= ? GROUP BY nome ORDER BY n DESC LIMIT 8", [$desde]);

// ─── SITUAÇÃO ATUAL (atendimentos ativos) ────────────────────────────────────
$statusAtual = array_column(db_all('SELECT status, COUNT(*) AS n FROM protocolo WHERE status IN ' . SQL_ATIVOS . ' GROUP BY status'), 'n', 'status');

// ─── AVALIAÇÕES ──────────────────────────────────────────────────────────────
$notas = array_column(db_all('SELECT a.nota, COUNT(*) AS n FROM avaliacao a JOIN protocolo p ON p.numero = a.numeroProtocolo
                              WHERE p.dataFinalizacao >= ? GROUP BY a.nota', [$desde]), 'n', 'nota');
$comentarios = db_all("SELECT a.nota, a.comentario, a.dataHora, c.nome AS cliente, col.nome AS colaborador, p.numero
                       FROM avaliacao a
                       JOIN protocolo p     ON p.numero = a.numeroProtocolo
                       JOIN veiculo v       ON v.id = p.idVeiculo
                       JOIN cliente c       ON c.id = v.idCliente
                       JOIN colaborador col ON col.id = p.responsavel
                       WHERE a.comentario IS NOT NULL AND a.comentario <> '' AND p.dataFinalizacao >= ?
                       ORDER BY a.dataHora DESC LIMIT 5", [$desde]);

// Dados enviados aos gráficos. Cada gráfico também tem uma visão em tabela.
$graficos = [
    'mensal' => [
        'tipo' => 'line', 'rotulos' => array_values($meses),
        'series' => [
            ['nome' => 'Abertos',     'cor' => 1, 'dados' => array_map(fn($k) => (int)($abertosMes[$k] ?? 0), array_keys($meses))],
            ['nome' => 'Finalizados', 'cor' => 2, 'dados' => array_map(fn($k) => (int)($finalMes[$k] ?? 0), array_keys($meses))],
        ],
    ],
    'colaboradores' => [
        'tipo' => 'hbar', 'rotulos' => array_column($porColab, 'nome'),
        'series' => [['nome' => 'Finalizados', 'cor' => 1, 'dados' => array_map('intval', array_column($porColab, 'finalizados'))]],
    ],
    'componentes' => [
        'tipo' => 'hbar', 'rotulos' => array_column($componentes, 'nome'),
        'series' => [['nome' => 'Ocorrências', 'cor' => 1, 'dados' => array_map('intval', array_column($componentes, 'n'))]],
    ],
    'status' => [
        'tipo' => 'hbar', 'rotulos' => array_map(fn($s) => status_protocolo($s)[1], STATUS_ATIVOS),
        'coresStatus' => array_map(fn($s) => status_protocolo($s)[0], STATUS_ATIVOS),
        'series' => [['nome' => 'Atendimentos', 'cor' => 1, 'dados' => array_map(fn($s) => (int)($statusAtual[$s] ?? 0), STATUS_ATIVOS)]],
    ],
    'notas' => [
        'tipo' => 'bar', 'rotulos' => ['1 ★', '2 ★', '3 ★', '4 ★', '5 ★'],
        'series' => [['nome' => 'Avaliações', 'cor' => 'star', 'dados' => array_map(fn($n) => (int)($notas[$n] ?? 0), range(1, 5))]],
    ],
];
$temDados = fn(string $g) => array_sum(array_merge(...array_column($graficos[$g]['series'], 'dados'))) > 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Relatórios'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    /* Cores das séries (validadas para contraste e daltonismo nos dois temas). */
    :root { --series-1: #2a78d6; --series-2: #eb6834; }
    @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --series-1: #3987e5; --series-2: #d95926; } }
    :root[data-theme="dark"] { --series-1: #3987e5; --series-2: #d95926; }

    .report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; margin-bottom: 20px; }
    .report-grid .wide { grid-column: 1 / -1; }
    .view-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 7px; overflow: hidden; }
    .view-toggle button { border: none; background: none; padding: 4px 10px; font-size: 11.5px; font-weight: 500; color: var(--muted); cursor: pointer; }
    .view-toggle button.active { background: var(--accent); color: var(--on-accent); }
    .data-table { display: none; max-height: 320px; overflow: auto; }
    .card.show-table .data-table { display: block; }
    .card.show-table .chart-box { display: none; }
    .data-table table td, .data-table table th { padding: 8px 22px; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .legend { display: flex; gap: 16px; padding: 14px 22px 0; font-size: 12px; color: var(--muted); }
    .legend span { display: inline-flex; align-items: center; gap: 6px; }
    .legend i { width: 14px; height: 3px; border-radius: 2px; display: inline-block; }
    .comment { padding: 14px 22px; border-bottom: 1px solid var(--border); }
    .comment:last-child { border-bottom: none; }
    .comment-text { font-size: 13px; line-height: 1.5; margin: 6px 0 4px; }
    .comment-meta { font-size: 11.5px; color: var(--muted); }
    .chart-empty { height: 100%; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 13px; text-align: center; }
    @media (max-width: 1100px) { .report-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 760px) {
      .legend, .comment { padding-left: 16px; padding-right: 16px; }
      .data-table table td, .data-table table th { padding: 8px 16px; }
    }
  </style>
</head>
<body>
<?php sidebar('relatorios'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Relatórios</div>
      <div class="page-sub">Indicadores de produtividade, prazos e satisfação da oficina</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <div class="filters-row">
    <?php foreach ($periodos as $chave => $rotulo): ?>
      <a class="filter-chip<?= $chave === $periodo ? ' active' : '' ?>" href="?periodo=<?= $chave ?>"><span class="chip-dot"></span><?= e($rotulo) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Atendimentos finalizados</div>
      <div class="stat-value"><?= $finalizados ?></div>
      <div class="stat-delta"><?= plural($cancelados, 'cancelado', 'cancelados') ?> · <?= $taxaCancel ?>% de cancelamento</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Tempo médio de atendimento</div>
      <div class="stat-value"><?= $kpi['tempoMedio'] !== null ? number_format((float)$kpi['tempoMedio'], 1, ',', '') : '—' ?></div>
      <div class="stat-delta">dias, da abertura à finalização</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Entregas no prazo</div>
      <div class="stat-value"><?= $pctPrazo !== null ? $pctPrazo . '%' : '—' ?></div>
      <div class="stat-delta"><?= $pctPrazo !== null ? (int)$kpi['noPrazo'] . ' de ' . (int)$kpi['comPrevisao'] . ' com previsão' : 'Nenhum atendimento com previsão' ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Satisfação média</div>
      <div class="stat-value"><?= $aval['total'] ? number_format((float)$aval['media'], 1, ',', '') : '—' ?></div>
      <div class="stat-delta"><?= $aval['total'] ? estrelas((int)round($aval['media'])) . ' ' . plural((int)$aval['total'], 'avaliação', 'avaliações') : 'Sem avaliações no período' ?></div>
    </div>
  </div>

  <div class="report-grid">
    <?php
    // Card de gráfico com alternância gráfico/tabela.
    $card = function (string $id, string $titulo, string $meta, bool $alto = false, bool $largo = false, string $vazio = 'Sem dados no período.') use ($graficos, $temDados) {
        $g = $graficos[$id];
        ?>
      <div class="card<?= $largo ? ' wide' : '' ?>" id="card-<?= $id ?>">
        <div class="card-header">
          <div>
            <div class="card-title"><?= e($titulo) ?></div>
            <div class="card-meta" style="margin-top:2px"><?= e($meta) ?></div>
          </div>
          <div class="view-toggle" role="group" aria-label="Visualização">
            <button type="button" class="active" data-view="chart">Gráfico</button>
            <button type="button" data-view="table">Tabela</button>
          </div>
        </div>
        <?php if (count($g['series']) > 1): ?>
          <div class="legend"><?php foreach ($g['series'] as $s): ?><span><i style="background:var(--series-<?= $s['cor'] ?>)"></i><?= e($s['nome']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="chart-box<?= $alto ? ' tall' : '' ?>">
          <?php if ($temDados($id)): ?><canvas id="chart-<?= $id ?>" role="img" aria-label="<?= e($titulo) ?>"></canvas>
          <?php else: ?><div class="chart-empty"><?= e($vazio) ?></div><?php endif; ?>
        </div>
        <div class="data-table">
          <table>
            <thead><tr><th></th><?php foreach ($g['series'] as $s): ?><th class="num"><?= e($s['nome']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php foreach ($g['rotulos'] as $i => $r): ?>
                <tr><td><?= e($r) ?></td><?php foreach ($g['series'] as $s): ?><td class="num"><?= (int)$s['dados'][$i] ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
              <?php if (!$g['rotulos']): ?><tr class="empty-row"><td colspan="3"><?= e($vazio) ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
        <?php
    };
    $card('mensal', 'Atendimentos por mês', 'Últimos 12 meses · abertos × finalizados', true, true);
    $card('status', 'Situação atual', 'Atendimentos ativos agora, por etapa', false, false, 'Nenhum atendimento ativo.');
    $card('componentes', 'Componentes mais frequentes', $periodos[$periodo] . ' · por ocorrência');
    $card('colaboradores', 'Finalizados por colaborador', $periodos[$periodo]);
    $card('notas', 'Distribuição das avaliações', $periodos[$periodo], false, false, 'Nenhuma avaliação no período.');
    ?>
  </div>

  <div class="two-col">
    <div class="card">
      <div class="card-header">
        <div class="card-title">Desempenho por colaborador</div>
        <span class="card-meta"><?= e($periodos[$periodo]) ?></span>
      </div>
      <table class="table-stack">
        <thead>
          <tr><th>Colaborador</th><th class="num">Ativos</th><th class="num">Finalizados</th><th class="num">Tempo médio</th><th>Avaliação</th></tr>
        </thead>
        <tbody>
          <?php foreach ($porColab as $c): ?>
          <tr>
            <td class="cell-main"><?= e($c['nome']) ?></td>
            <td data-label="Ativos" class="num"><?= (int)$c['ativos'] ?></td>
            <td data-label="Finalizados" class="num"><?= (int)$c['finalizados'] ?></td>
            <td data-label="Tempo médio" class="num"><?= $c['tempoMedio'] !== null ? number_format((float)$c['tempoMedio'], 1, ',', '') . ' dias' : '—' ?></td>
            <td data-label="Avaliação"><?= $c['avaliacoes'] ? estrelas((int)round($c['nota'])) . ' <span class="text-muted" style="font-size:12px">' . number_format((float)$c['nota'], 1, ',', '') . ' (' . (int)$c['avaliacoes'] . ')</span>' : '<span class="text-muted">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Comentários recentes</div></div>
      <?php if (!$comentarios): ?>
        <div class="empty-state">Nenhum comentário de cliente no período.</div>
      <?php endif; ?>
      <?php foreach ($comentarios as $cm): ?>
        <div class="comment">
          <?= estrelas((int)$cm['nota']) ?>
          <div class="comment-text">“<?= e($cm['comentario']) ?>”</div>
          <div class="comment-meta"><?= e($cm['cliente']) ?> · atendido por <?= e($cm['colaborador']) ?> · <a href="historico.php?numero=<?= (int)$cm['numero'] ?>#detalhe"><?= proto_num($cm['numero']) ?></a></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<?php flash_html(); ?>

<script>
  const GRAFICOS = <?= json_encode($graficos, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
  const instancias = {};

  // Alterna entre gráfico e tabela em cada card.
  document.addEventListener('click', e => {
    const b = e.target.closest('.view-toggle button');
    if (!b) return;
    const card = b.closest('.card');
    card.querySelectorAll('.view-toggle button').forEach(x => x.classList.toggle('active', x === b));
    card.classList.toggle('show-table', b.dataset.view === 'table');
  });

  function desenhar() {
    if (!window.Chart) return;
    const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const cor = c => c === 'star' ? css('--star') : css('--series-' + c);
    Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = css('--muted');
    Chart.defaults.borderColor = css('--border');

    for (const [id, g] of Object.entries(GRAFICOS)) {
      const canvas = document.getElementById('chart-' + id);
      if (!canvas) continue;
      instancias[id]?.destroy();
      const horizontal = g.tipo === 'hbar';
      const linha = g.tipo === 'line';
      const datasets = g.series.map(s => {
        const c = cor(s.cor);
        const base = { label: s.nome, data: s.dados };
        if (linha) return { ...base, borderColor: c, backgroundColor: c, borderWidth: 2, tension: 0.25,
                             pointRadius: 4, pointHoverRadius: 6, pointBorderColor: css('--surface'), pointBorderWidth: 2,
                             borderCapStyle: 'round', borderJoinStyle: 'round' };
        // Situação atual: cada barra usa a cor do status (mesma dos badges); o rótulo do eixo nomeia a etapa.
        const fundo = g.coresStatus ? g.coresStatus.map(k => css('--tag-' + k + '-t')) : c;
        return { ...base, backgroundColor: fundo, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 24 };
      });
      const eixoValor = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: css('--border') }, border: { display: false } };
      const eixoCategoria = { grid: { display: false }, border: { color: css('--border') }, ticks: { color: css('--text') } };
      instancias[id] = new Chart(canvas, {
        type: linha ? 'line' : 'bar',
        data: { labels: g.rotulos, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          indexAxis: horizontal ? 'y' : 'x',
          interaction: linha ? { mode: 'index', intersect: false } : { mode: 'nearest', intersect: false, axis: horizontal ? 'y' : 'x' },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: css('--surface'), titleColor: css('--text'), bodyColor: css('--text'),
              borderColor: css('--border'), borderWidth: 1, padding: 10, boxPadding: 4, usePointStyle: true,
            },
          },
          scales: horizontal ? { x: eixoValor, y: eixoCategoria } : { x: eixoCategoria, y: eixoValor },
        },
      });
    }
  }

  document.addEventListener('DOMContentLoaded', desenhar);
  // Redesenha quando a fonte termina de carregar, para os rótulos serem medidos com ela.
  document.fonts?.ready.then(desenhar);
  document.addEventListener('temachange', () => setTimeout(desenhar, 0));
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', desenhar);
</script>
</body>
</html>
