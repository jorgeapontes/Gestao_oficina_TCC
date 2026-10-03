<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);
$me = $u['id'];

// ─── ESTATÍSTICAS ────────────────────────────────────────────────────────────
$emAberto    = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE responsavel = ? AND status = 'ABERTO'", [$me]);
$emAndamento = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE responsavel = ? AND status IN " . SQL_ATIVOS . " AND status <> 'ABERTO'", [$me]);
$atrasados   = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE responsavel = ? AND status IN " . SQL_ATIVOS . " AND previsaoEntrega < CURDATE()", [$me]);
$finalHoje   = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE responsavel = ? AND status = 'FINALIZADO' AND dataFinalizacao = CURDATE()", [$me]);
$totalOc     = (int)db_val('SELECT COUNT(*) FROM protocolo WHERE responsavel = ?', [$me]);
$ocSemana    = (int)db_val('SELECT COUNT(*) FROM protocolo p JOIN ocorrencia o ON o.id = p.idOcorrencia
                            WHERE p.responsavel = ? AND o.dataRegistro >= NOW() - INTERVAL 7 DAY', [$me]);
$finalMes    = (int)db_val("SELECT COUNT(*) FROM protocolo WHERE responsavel = ? AND status = 'FINALIZADO'
                            AND YEAR(dataFinalizacao) = YEAR(CURDATE()) AND MONTH(dataFinalizacao) = MONTH(CURDATE())", [$me]);
$minhaNota   = db_one('SELECT AVG(a.nota) AS media, COUNT(*) AS total FROM avaliacao a
                       JOIN protocolo p ON p.numero = a.numeroProtocolo WHERE p.responsavel = ?', [$me]);

// ─── MEUS ATENDIMENTOS (abas) ────────────────────────────────────────────────
$abas = [
    'aberto'      => ['Em aberto',        "p.status = 'ABERTO'"],
    'andamento'   => ['Em andamento',     "p.status IN " . SQL_ATIVOS . " AND p.status <> 'ABERTO'"],
    'finalizados' => ['Finalizados hoje', "p.status = 'FINALIZADO' AND p.dataFinalizacao = CURDATE()"],
];
$aba = array_key_exists($_GET['tab'] ?? '', $abas) ? $_GET['tab'] : ($emAberto || !$emAndamento ? 'aberto' : 'andamento');
$atendimentos = db_all("SELECT p.numero, p.status, p.previsaoEntrega, c.nome AS cliente, v.placa, o.descricao
                        FROM protocolo p
                        JOIN ocorrencia o ON o.id = p.idOcorrencia
                        JOIN veiculo v    ON v.id = p.idVeiculo
                        JOIN cliente c    ON c.id = v.idCliente
                        WHERE p.responsavel = ? AND {$abas[$aba][1]}
                        ORDER BY p.numero DESC", [$me]);

// ─── VEÍCULO EM FOCO: o do atendimento ativo mais recente ────────────────────
$foco = db_one("SELECT p.numero, p.status, p.previsaoEntrega, v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                       c.nome AS cliente, c.telefone
                FROM protocolo p
                JOIN veiculo v ON v.id = p.idVeiculo
                JOIN modelo mo ON mo.id = v.idModelo
                JOIN marca ma  ON ma.id = mo.idMarca
                JOIN cliente c ON c.id = v.idCliente
                WHERE p.responsavel = ? AND p.status IN " . SQL_ATIVOS . "
                ORDER BY p.status = 'EM_ATENDIMENTO' DESC, p.numero DESC LIMIT 1", [$me]);
$histFoco = $foco ? db_all("SELECT p.numero, p.dataFinalizacao, o.descricao
                            FROM protocolo p JOIN ocorrencia o ON o.id = p.idOcorrencia
                            WHERE p.idVeiculo = ? AND p.status = 'FINALIZADO'
                            ORDER BY p.numero DESC LIMIT 3", [$foco['id']]) : [];

$revisoes = veiculos_para_revisao(null, 5);

$hora = (int)date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$pendentes = $emAberto + $emAndamento;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Meu Painel'); ?>
  <style>
    .hist-list { padding: 0 22px; }
    .hist-item { display: flex; gap: 12px; padding: 13px 0; border-bottom: 1px solid var(--border); align-items: flex-start; }
    .hist-item:last-child { border-bottom: none; }
    .hist-badge { font-family: 'DM Mono', monospace; font-size: 10.5px; background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 2px 6px; white-space: nowrap; margin-top: 1px; flex-shrink: 0; }
    .hist-desc { font-size: 13px; line-height: 1.45; }
    .hist-date { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; margin-top: 2px; }
    @media (max-width: 760px) { .hist-list { padding: 0 16px; } }
  </style>
</head>
<body>
<?php sidebar('dashboard'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="veiculos.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" placeholder="Buscar cliente ou placa…"/>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="page-title">Meu Painel</div>
      <div class="page-sub"><?= $saudacao ?>, <?= e(primeiro_nome($u['nome'])) ?>.
        <?= $pendentes ? 'Você tem ' . plural($pendentes, 'atendimento', 'atendimentos') . ' em aberto.' : 'Nenhum atendimento pendente.' ?></div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Atendimentos ativos</div>
      <div class="stat-value"><?= $pendentes ?></div>
      <div class="stat-delta<?= $atrasados ? ' down' : '' ?>"><?= $atrasados ? plural($atrasados, 'atrasado', 'atrasados') . ' · ' : '' ?><?= $emAberto ?> aberto<?= $emAberto === 1 ? '' : 's' ?> · <?= $emAndamento ?> em andamento</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Ocorrências registradas</div>
      <div class="stat-value"><?= $totalOc ?></div>
      <div class="stat-delta<?= $ocSemana ? ' up' : '' ?>">+<?= $ocSemana ?> esta semana</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Finalizados no mês</div>
      <div class="stat-value"><?= $finalMes ?></div>
      <div class="stat-delta"><?= $finalHoje ?> hoje · <?= ucfirst(MESES[(int)date('n')]) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Minha avaliação</div>
      <div class="stat-value"><?= $minhaNota['total'] ? number_format((float)$minhaNota['media'], 1, ',', '') : '—' ?></div>
      <div class="stat-delta"><?= $minhaNota['total'] ? estrelas((int)round($minhaNota['media'])) . ' ' . plural((int)$minhaNota['total'], 'avaliação', 'avaliações') : 'Sem avaliações ainda' ?></div>
    </div>
  </div>

  <div class="two-col">

    <div class="stack">
      <!-- Meus atendimentos -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Meus atendimentos</div>
          <a class="card-action" href="../admin/historico.php">Ver histórico →</a>
        </div>

        <div class="tab-bar">
          <?php foreach ($abas as $chave => [$rotulo]): ?>
            <a class="tab<?= $chave === $aba ? ' active' : '' ?>" href="?tab=<?= $chave ?>"><?= e($rotulo) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if (!$atendimentos): ?>
          <div class="empty-state">
            <div class="empty-icon">📋</div>
            Nenhum atendimento nesta aba.
          </div>
        <?php else: ?>
        <table class="table-stack">
          <thead>
            <tr>
              <th>Protocolo</th>
              <th>Cliente</th>
              <th>Veículo</th>
              <th>Ocorrência</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($atendimentos as $a): ?>
            <tr data-href="atendimento.php?numero=<?= (int)$a['numero'] ?>">
              <td data-label="Protocolo"><span class="td-proto"><?= proto_num($a['numero']) ?></span></td>
              <td data-label="Cliente"><?= e($a['cliente']) ?></td>
              <td data-label="Veículo"><span class="td-plate"><?= e($a['placa']) ?></span></td>
              <td data-label="Ocorrência"><?= e(mb_strimwidth($a['descricao'], 0, 48, '…')) ?></td>
              <td data-label="Status">
                <?= badge(status_protocolo($a['status'])) ?>
                <?php if (atrasado($a['previsaoEntrega'], $a['status'])): ?><span class="badge late">Atrasado</span><?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- Registrar ocorrência rápida -->
      <form class="card" method="POST" action="registrar_ocorrencia.php">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="registrar"/>
        <div class="card-header">
          <div class="card-title">Registrar ocorrência rápida</div>
          <a class="card-action" href="registrar_ocorrencia.php">Formulário completo →</a>
        </div>

        <div class="form-body">
          <div class="field-row">
            <div class="field">
              <label for="q-placa">Placa do veículo</label>
              <input id="q-placa" type="text" name="placa" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase" data-mask="placa" required/>
            </div>
            <div class="field">
              <label for="q-comp">Componente afetado</label>
              <select id="q-comp" name="componentes">
                <option value="">— selecione —</option>
                <option>Freios</option><option>Motor</option><option>Suspensão</option><option>Embreagem</option>
                <option>Transmissão</option><option>Sistema elétrico</option><option>Outros</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label for="q-desc">Descrição do problema</label>
            <textarea id="q-desc" name="itens[]" maxlength="200" required placeholder="Descreva o problema identificado, sintomas observados e condição do componente…"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="reset" class="btn-outline">Limpar</button>
          <button type="submit" class="btn-primary">Registrar ocorrência</button>
        </div>
      </form>
    </div>

    <div class="stack">
      <!-- Veículo em foco -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Veículo em foco</div>
          <?php if ($foco): ?><a class="card-action" href="atendimento.php?numero=<?= (int)$foco['numero'] ?>">Abrir →</a><?php endif; ?>
        </div>
        <?php if (!$foco): ?>
          <div class="empty-state">Nenhum atendimento ativo no momento.</div>
        <?php else: ?>
        <div class="kv-list">
          <div class="kv-row"><span class="kv-key">Placa</span><span class="kv-val mono"><?= e($foco['placa']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Status</span><?= badge(status_protocolo($foco['status'])) ?></div>
          <div class="kv-divider"></div>
          <div class="kv-row"><span class="kv-key">Modelo</span><span class="kv-val"><?= e($foco['marca'] . ' ' . $foco['modelo']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Ano / Cor</span><span class="kv-val"><?= e($foco['ano'] . ' · ' . $foco['cor']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Previsão</span><span class="kv-val<?= atrasado($foco['previsaoEntrega'], $foco['status']) ? ' text-danger' : '' ?>"><?= fmt_data($foco['previsaoEntrega']) ?></span></div>
          <div class="kv-divider"></div>
          <div class="kv-row"><span class="kv-key">Proprietário</span><span class="kv-val"><?= e($foco['cliente']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Contato</span><span class="kv-val mono" style="font-size:12px"><?= e(fmt_tel($foco['telefone'])) ?></span></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($foco): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Histórico do veículo</div>
          <a class="card-action" href="../admin/historico.php?q=<?= urlencode($foco['placa']) ?>">Ver tudo →</a>
        </div>
        <?php if (!$histFoco): ?>
          <div class="empty-state">Nenhum atendimento finalizado para este veículo.</div>
        <?php else: ?>
        <div class="hist-list">
          <?php foreach ($histFoco as $h): ?>
          <div class="hist-item">
            <div class="hist-badge"><?= proto_num($h['numero']) ?></div>
            <div>
              <div class="hist-desc"><?= e($h['descricao']) ?></div>
              <div class="hist-date"><?= fmt_data_curta($h['dataFinalizacao']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Revisões pendentes -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Revisão recomendada</div>
          <span class="card-meta">+<?= MESES_REVISAO ?> meses</span>
        </div>
        <?php if (!$revisoes): ?>
          <div class="empty-state">Nenhum veículo com revisão pendente.</div>
        <?php else: ?>
        <div class="reminder-list">
          <?php foreach ($revisoes as $r): ?>
          <div class="reminder-item">
            <span class="td-plate"><?= e($r['placa']) ?></span>
            <div class="grow">
              <div><?= e($r['cliente']) ?></div>
              <div class="reminder-sub">Último serviço há <?= plural(meses_desde($r['ultimo']), 'mês', 'meses') ?> · <?= e(fmt_tel($r['telefone'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Cadastrar veículo</div>
        </div>
        <div class="card-text">
          Veículo ainda não cadastrado? Cadastre-o vinculado ao cliente proprietário antes de registrar a ocorrência.
        </div>
        <div class="card-footer" style="border-top:none;padding-top:0">
          <a class="btn-primary btn-block" href="veiculos.php?novo=1">Cadastrar veículo</a>
        </div>
      </div>
    </div>
  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
