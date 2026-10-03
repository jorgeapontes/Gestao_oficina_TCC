<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['ADMIN']);

// ─── ESTATÍSTICAS ────────────────────────────────────────────────────────────
$hoje      = (int)db_val('SELECT COUNT(*) FROM ocorrencia WHERE DATE(dataRegistro) = CURDATE()');
$ontem     = (int)db_val('SELECT COUNT(*) FROM ocorrencia WHERE DATE(dataRegistro) = CURDATE() - INTERVAL 1 DAY');
$ativos    = (int)db_val('SELECT COUNT(*) FROM protocolo WHERE status IN ' . SQL_ATIVOS);
$abertas   = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'ABERTO'");
$prontos   = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'PRONTO'");
$atrasados = (int)db_val('SELECT COUNT(*) FROM protocolo WHERE status IN ' . SQL_ATIVOS . ' AND previsaoEntrega < CURDATE()');
$veiculos  = (int)db_val('SELECT COUNT(*) FROM veiculo');
$clientes  = (int)db_val('SELECT COUNT(*) FROM cliente');
$satisf    = db_one('SELECT AVG(nota) AS media, COUNT(*) AS total FROM avaliacao');
$difHoje   = $hoje - $ontem;

// ─── ATENDIMENTOS ATIVOS ─────────────────────────────────────────────────────
$verTodos = isset($_GET['todos']);
$atendimentos = db_all("SELECT p.numero, p.status, p.previsaoEntrega, c.nome AS cliente, v.placa, col.nome AS colaborador
                        FROM protocolo p
                        JOIN veiculo v       ON v.id = p.idVeiculo
                        JOIN cliente c       ON c.id = v.idCliente
                        JOIN colaborador col ON col.id = p.responsavel
                        WHERE p.status IN " . SQL_ATIVOS . "
                        ORDER BY (p.previsaoEntrega < CURDATE()) DESC, p.numero DESC" . ($verTodos ? '' : ' LIMIT 6'));

// ─── ATIVIDADE RECENTE: últimas mudanças de status e itens registrados ───────
$atividades = db_all("(SELECT s.dataHora, s.id AS ordem, 'status' AS tipo, s.status AS detalhe, col.nome AS colaborador, v.placa, p.numero, p.status
                       FROM protocolo_status s
                       JOIN protocolo p ON p.numero = s.numeroProtocolo
                       JOIN veiculo v   ON v.id = p.idVeiculo
                       LEFT JOIN colaborador col ON col.id = s.idColaborador)
                      UNION ALL
                      (SELECT i.dataHora, 0 AS ordem, 'item' AS tipo, i.descricao AS detalhe, col.nome AS colaborador, v.placa, p.numero, p.status
                       FROM itemocorrencia i
                       JOIN colaborador col ON col.id = i.idColaborador
                       JOIN ocorrencia o    ON o.id = i.idOcorrencia
                       JOIN veiculo v       ON v.id = o.idVeiculo
                       LEFT JOIN protocolo p ON p.idOcorrencia = o.id)
                      ORDER BY dataHora DESC, ordem DESC LIMIT 6");

$revisoes = veiculos_para_revisao(null, 5);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Visão Geral'); ?>
  <style>
    .actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 18px 22px; }
    .action-btn {
      display: flex; flex-direction: column; gap: 6px;
      padding: 14px;
      border: 1px solid var(--border); border-radius: 10px;
      background: var(--bg);
      text-align: left; text-decoration: none;
      transition: border-color 0.15s, background 0.15s;
    }
    .action-btn:hover { border-color: var(--text); background: var(--surface); }
    .a-icon { font-size: 18px; }
    .a-label { font-size: 12.5px; font-weight: 500; }
    .a-desc { font-size: 11px; color: var(--muted); }

    .activity-list { padding: 0 22px; }
    .activity-item { display: flex; gap: 12px; padding: 14px 0; border-bottom: 1px solid var(--border); align-items: flex-start; }
    .activity-item:last-child { border-bottom: none; }
    .activity-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border-strong); flex-shrink: 0; margin-top: 6px; }
    .activity-dot.open { background: var(--tag-open-t); } .activity-dot.prog { background: var(--tag-prog-t); }
    .activity-dot.wait { background: var(--tag-wait-t); } .activity-dot.ready { background: var(--tag-ready-t); }
    .activity-dot.cancel { background: var(--danger); }
    .activity-text { font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
    .activity-time { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; margin-top: 2px; }
    @media (max-width: 760px) { .actions-grid, .activity-list { padding-left: 16px; padding-right: 16px; } }
  </style>
</head>
<body>
<?php sidebar('dashboard'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="historico.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="search" name="q" placeholder="Buscar no histórico: cliente, placa, protocolo…"/>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Visão Geral</div>
      <div class="page-sub">Situação atual da oficina</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Ocorrências hoje</div>
      <div class="stat-value"><?= $hoje ?></div>
      <div class="stat-delta<?= $difHoje > 0 ? ' up' : '' ?>">
        <?php if ($difHoje > 0): ?><?= $difHoje ?> a mais que ontem
        <?php elseif ($difHoje < 0): ?><?= -$difHoje ?> a menos que ontem
        <?php else: ?>Igual a ontem<?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Atendimentos ativos</div>
      <div class="stat-value"><?= $ativos ?></div>
      <div class="stat-delta<?= $atrasados ? ' down' : '' ?>">
        <?= $atrasados ? plural($atrasados, 'atrasado', 'atrasados') . ' · ' : '' ?><?= $abertas ?> aguardando avaliação · <?= $prontos ?> pronto<?= $prontos === 1 ? '' : 's' ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Veículos cadastrados</div>
      <div class="stat-value"><?= number_format($veiculos, 0, ',', '.') ?></div>
      <div class="stat-delta"><?= plural($clientes, 'cliente', 'clientes') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Satisfação dos clientes</div>
      <div class="stat-value"><?= $satisf['total'] ? number_format((float)$satisf['media'], 1, ',', '') : '—' ?></div>
      <div class="stat-delta"><?= $satisf['total'] ? estrelas((int)round($satisf['media'])) . ' ' . plural((int)$satisf['total'], 'avaliação', 'avaliações') : 'Sem avaliações ainda' ?></div>
    </div>
  </div>

  <div class="two-col">

    <div class="stack">
      <div class="card" id="atendimentos">
        <div class="card-header">
          <div class="card-title">Atendimentos ativos</div>
          <?php if ($verTodos): ?>
            <a class="card-action" href="dashboard.php">Ver menos ←</a>
          <?php else: ?>
            <a class="card-action" href="?todos=1#atendimentos">Ver todos →</a>
          <?php endif; ?>
        </div>
        <?php if (!$atendimentos): ?>
          <div class="empty-state">Nenhum atendimento em andamento.</div>
        <?php else: ?>
        <table class="table-stack">
          <thead>
            <tr>
              <th>Protocolo</th>
              <th>Cliente</th>
              <th>Veículo</th>
              <th>Colaborador</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($atendimentos as $a): $late = atrasado($a['previsaoEntrega'], $a['status']); ?>
            <tr data-href="../colaborador/atendimento.php?numero=<?= (int)$a['numero'] ?>">
              <td data-label="Protocolo"><span class="td-proto"><?= proto_num($a['numero']) ?></span></td>
              <td data-label="Cliente"><?= e($a['cliente']) ?></td>
              <td data-label="Veículo"><span class="td-plate"><?= e($a['placa']) ?></span></td>
              <td data-label="Colaborador"><?= e($a['colaborador']) ?></td>
              <td data-label="Status"><?= badge(status_protocolo($a['status'])) ?><?= $late ? ' <span class="badge late" title="Previsão: ' . fmt_data($a['previsaoEntrega']) . '">Atrasado</span>' : '' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Revisão recomendada</div>
          <span class="card-meta">último serviço há +<?= MESES_REVISAO ?> meses</span>
        </div>
        <?php if (!$revisoes): ?>
          <div class="empty-state">Nenhum veículo com revisão pendente.</div>
        <?php else: ?>
        <div class="reminder-list">
          <?php foreach ($revisoes as $r): $tel = so_digitos($r['telefone']); ?>
          <div class="reminder-item">
            <span class="td-plate"><?= e($r['placa']) ?></span>
            <div class="grow">
              <div><?= e($r['cliente']) ?> · <?= e($r['marca'] . ' ' . $r['modelo']) ?></div>
              <div class="reminder-sub">Último serviço em <?= fmt_data($r['ultimo']) ?> (há <?= plural(meses_desde($r['ultimo']), 'mês', 'meses') ?>)</div>
            </div>
            <?php if ($tel !== ''): ?><a class="act-btn" href="tel:<?= e($tel) ?>">Ligar</a><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Ações rápidas</div>
        </div>
        <div class="actions-grid">
          <a class="action-btn" href="../colaborador/registrar_ocorrencia.php">
            <span class="a-icon">🔧</span>
            <span class="a-label">Nova ocorrência</span>
            <span class="a-desc">Registrar problema</span>
          </a>
          <a class="action-btn" href="../colaborador/veiculos.php?novo=1">
            <span class="a-icon">🚗</span>
            <span class="a-label">Novo veículo</span>
            <span class="a-desc">Cadastrar veículo</span>
          </a>
          <a class="action-btn" href="../colaborador/visualizar_clientes.php?novo=1">
            <span class="a-icon">👤</span>
            <span class="a-label">Novo cliente</span>
            <span class="a-desc">Cadastrar cliente</span>
          </a>
          <a class="action-btn" href="relatorios.php">
            <span class="a-icon">📊</span>
            <span class="a-label">Relatórios</span>
            <span class="a-desc">Indicadores da oficina</span>
          </a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Atividade recente</div>
          <a class="card-action" href="historico.php">Ver histórico →</a>
        </div>
        <div class="activity-list">
          <?php if (!$atividades): ?>
            <div class="empty-state">Nenhuma atividade registrada ainda.</div>
          <?php endif; ?>
          <?php foreach ($atividades as $at): $st = status_protocolo($at['tipo'] === 'status' ? $at['detalhe'] : $at['status']); ?>
          <div class="activity-item">
            <div class="activity-dot <?= e($st[0]) ?>"></div>
            <div>
              <div class="activity-text">
                <strong><?= e($at['colaborador'] ?? 'Sistema') ?></strong>
                <?php if ($at['tipo'] === 'status'): ?>
                  moveu <strong><?= e($at['placa']) ?></strong> (<?= proto_num($at['numero']) ?>) para <strong><?= e($st[1]) ?></strong>
                <?php else: ?>
                  registrou em <strong><?= e($at['placa']) ?></strong><?= $at['numero'] ? ' (' . proto_num($at['numero']) . ')' : '' ?>:
                  <?= e(mb_strimwidth($at['detalhe'], 0, 70, '…')) ?>
                <?php endif; ?>
              </div>
              <div class="activity-time"><?= tempo_relativo($at['dataHora']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
