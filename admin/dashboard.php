<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['ADMIN']);

// ─── ESTATÍSTICAS ────────────────────────────────────────────────────────────
$hoje      = (int)db_val('SELECT COUNT(*) FROM ocorrencia WHERE DATE(dataRegistro) = CURDATE()');
$ontem     = (int)db_val('SELECT COUNT(*) FROM ocorrencia WHERE DATE(dataRegistro) = CURDATE() - INTERVAL 1 DAY');
$abertas   = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'ABERTO'");
$andamento = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'EM_ATENDIMENTO'");
$veiculos  = (int)db_val('SELECT COUNT(*) FROM veiculo');
$clientes  = (int)db_val('SELECT COUNT(*) FROM cliente');
$finalizados = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE status = 'FINALIZADO'");
$difHoje   = $hoje - $ontem;

// ─── ATENDIMENTOS EM ANDAMENTO ───────────────────────────────────────────────
$verTodos = isset($_GET['todos']);
$atendimentos = db_all("SELECT p.numero, p.status, c.nome AS cliente, v.placa, col.nome AS colaborador
                        FROM protocolo p
                        JOIN veiculo v       ON v.id = p.idVeiculo
                        JOIN cliente c       ON c.id = v.idCliente
                        JOIN colaborador col ON col.id = p.responsavel
                        WHERE p.status IN ('ABERTO','EM_ATENDIMENTO')
                        ORDER BY p.numero DESC" . ($verTodos ? '' : ' LIMIT 6'));

// ─── ATIVIDADE RECENTE: últimos itens registrados em ocorrências ─────────────
$atividades = db_all("SELECT i.dataHora, i.descricao, col.nome AS colaborador, v.placa, p.numero, p.status
                      FROM itemocorrencia i
                      JOIN colaborador col ON col.id = i.idColaborador
                      JOIN ocorrencia o    ON o.id = i.idOcorrencia
                      JOIN veiculo v       ON v.id = o.idVeiculo
                      LEFT JOIN protocolo p ON p.idOcorrencia = o.id
                      ORDER BY i.dataHora DESC, i.id DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>IMM – Integrated Mechanical Management</title>
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet" />
  <link rel='stylesheet' href='admin.css'>
</head>

<body>
  <style>
    a.action-btn, a.card-action { text-decoration: none; color: inherit; }
    tbody tr[data-href] { cursor: pointer; }
    .empty { padding: 28px 22px; text-align: center; color: var(--muted); font-size: 13px; }
  </style>

  <?php sidebar('dashboard'); ?>

  <!-- MAIN -->
  <main>

    <!-- top bar -->
    <div class="topbar">
      <form class="search-wrap" method="GET" action="historico.php">
        <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2">
          <circle cx="11" cy="11" r="8" />
          <path d="M21 21l-4.35-4.35" />
        </svg>
        <input class="search-input" type="text" name="q" placeholder="Buscar no histórico: cliente, placa, protocolo…" />
      </form>
    </div>

    <!-- header -->
    <div class="page-header">
      <div>
        <div class="page-title">Visão Geral</div>
        <div class="page-sub">Situação atual da oficina</div>
      </div>
      <div class="page-date"><?= data_hoje() ?></div>
    </div>

    <!-- stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Atendimentos hoje</div>
        <div class="stat-value"><?= $hoje ?></div>
        <div class="stat-delta<?= $difHoje > 0 ? ' up' : '' ?>">
          <?php if ($difHoje > 0): ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6" /></svg>
            <?= plural($difHoje, 'a mais', 'a mais') ?> que ontem
          <?php elseif ($difHoje < 0): ?>
            <?= plural(-$difHoje, 'a menos', 'a menos') ?> que ontem
          <?php else: ?>
            Igual a ontem
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Ocorrências abertas</div>
        <div class="stat-value"><?= $abertas ?></div>
        <div class="stat-delta">Aguardando avaliação · <?= $andamento ?> em andamento</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Veículos cadastrados</div>
        <div class="stat-value"><?= number_format($veiculos, 0, ',', '.') ?></div>
        <div class="stat-delta"><?= plural($clientes, 'cliente', 'clientes') ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Protocolos finalizados</div>
        <div class="stat-value"><?= number_format($finalizados, 0, ',', '.') ?></div>
        <div class="stat-delta">Total histórico</div>
      </div>
    </div>

    <!-- two col -->
    <div class="two-col">

      <!-- table -->
      <div class="card" id="atendimentos">
        <div class="card-header">
          <div class="card-title">Atendimentos em andamento</div>
          <?php if ($verTodos): ?>
            <a class="card-action" href="dashboard.php">Ver menos ←</a>
          <?php else: ?>
            <a class="card-action" href="?todos=1#atendimentos">Ver todos →</a>
          <?php endif; ?>
        </div>
        <?php if (!$atendimentos): ?>
          <div class="empty">Nenhum atendimento em andamento.</div>
        <?php else: ?>
        <table>
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
            <?php foreach ($atendimentos as $a): ?>
            <tr data-href="../colaborador/atendimento.php?numero=<?= (int)$a['numero'] ?>">
              <td><span style="font-family:'DM Mono',monospace;font-size:12px;color:var(--muted)"><?= proto_num($a['numero']) ?></span></td>
              <td><?= e($a['cliente']) ?></td>
              <td><span class="td-plate"><?= e($a['placa']) ?></span></td>
              <td><?= e($a['colaborador']) ?></td>
              <td><?= badge(status_protocolo($a['status'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- right column -->
      <div class="right-col">

        <!-- quick actions -->
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
            <a class="action-btn" href="?todos=1#atendimentos">
              <span class="a-icon">📋</span>
              <span class="a-label">Finalizar protocolo</span>
              <span class="a-desc">Encerrar atendimento</span>
            </a>
          </div>
        </div>

        <!-- activity -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Atividade recente</div>
            <a class="card-action" href="historico.php">Ver histórico →</a>
          </div>
          <div class="activity-list">
            <?php if (!$atividades): ?>
              <div class="empty">Nenhuma atividade registrada ainda.</div>
            <?php endif; ?>
            <?php foreach ($atividades as $at): ?>
            <div class="activity-item">
              <div class="activity-dot <?= $at['status'] === 'FINALIZADO' ? '' : ($at['status'] === 'ABERTO' ? 'warn' : 'new') ?>"></div>
              <div>
                <div class="activity-text"><strong><?= e($at['colaborador']) ?></strong> registrou em
                  <strong><?= e($at['placa']) ?></strong><?= $at['numero'] ? ' (' . proto_num($at['numero']) . ')' : '' ?>:
                  <?= e(mb_strimwidth($at['descricao'], 0, 70, '…')) ?></div>
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

  <script>
    document.querySelectorAll('tr[data-href]').forEach(tr => tr.addEventListener('click', () => location.href = tr.dataset.href));
  </script>
</body>

</html>
