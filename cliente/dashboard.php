<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

$cliente = db_one('SELECT nome, cpf, email, telefone, endereco FROM cliente WHERE id = ?', [$u['id']]);

// ─── MEUS VEÍCULOS ───────────────────────────────────────────────────────────
$veiculos = db_all("SELECT v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                           " . SQL_STATUS_ATIVO . " AS statusAtivo,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id AND p.status IN " . SQL_ATIVOS . ") AS abertas,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id) AS protocolos
                    FROM veiculo v
                    JOIN modelo mo ON mo.id = v.idModelo
                    JOIN marca ma  ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY statusAtivo IS NULL, v.placa", [$u['id']]);

// Veículo selecionado (só entre os do próprio cliente).
$sel = $veiculos[0] ?? null;
foreach ($veiculos as $v) if ($v['id'] === ($_GET['v'] ?? '')) $sel = $v;

// ─── OCORRÊNCIAS DO VEÍCULO SELECIONADO ──────────────────────────────────────
$aba = ($_GET['tab'] ?? '') === 'todas' ? 'todas' : 'abertas';
$ocorrencias = $sel ? db_all("SELECT p.numero, p.status, p.previsaoEntrega, o.descricao, o.componentes, o.dataRegistro, col.nome AS colaborador
                              FROM protocolo p
                              JOIN ocorrencia o    ON o.id = p.idOcorrencia
                              JOIN colaborador col ON col.id = p.responsavel
                              WHERE p.idVeiculo = ?" . ($aba === 'abertas' ? ' AND p.status IN ' . SQL_ATIVOS : '') . "
                              ORDER BY p.numero DESC", [$sel['id']]) : [];

// ─── HISTÓRICO (todos os veículos) ───────────────────────────────────────────
$historico = db_all("SELECT p.numero, p.dataFinalizacao, o.descricao, v.placa, col.nome AS colaborador, a.nota
                     FROM protocolo p
                     JOIN ocorrencia o    ON o.id = p.idOcorrencia
                     JOIN veiculo v       ON v.id = p.idVeiculo
                     JOIN colaborador col ON col.id = p.responsavel
                     LEFT JOIN avaliacao a ON a.numeroProtocolo = p.numero
                     WHERE v.idCliente = ? AND p.status = 'FINALIZADO'
                     ORDER BY p.numero DESC LIMIT 4", [$u['id']]);

// ─── AVISOS: revisão recomendada e atendimentos aguardando avaliação ─────────
$revisoes = veiculos_para_revisao($u['id']);
$semAvaliacao = db_one("SELECT p.numero, v.placa FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                        LEFT JOIN avaliacao a ON a.numeroProtocolo = p.numero
                        WHERE v.idCliente = ? AND p.status = 'FINALIZADO' AND a.nota IS NULL
                          AND p.dataFinalizacao >= CURDATE() - INTERVAL 60 DAY
                        ORDER BY p.numero DESC LIMIT 1", [$u['id']]);
$prontos = array_filter($veiculos, fn($v) => $v['statusAtivo'] === 'PRONTO');

function qs_dash(array $troca): string {
    return '?' . http_build_query(array_filter(array_merge($_GET, $troca), fn($v) => $v !== null && $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Meu Painel'); ?>
  <style>
    .vehicles-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 28px; }
    .vehicle-card {
      display: block; position: relative;
      background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
      padding: 18px 20px; text-decoration: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .vehicle-card:hover { border-color: var(--border-strong); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .vehicle-card.selected { border-color: var(--accent); }
    .vc-plate { font-family: 'DM Mono', monospace; font-size: 15px; font-weight: 500; letter-spacing: 0.06em; margin-bottom: 6px; }
    .vc-model { font-size: 13px; margin-bottom: 2px; }
    .vc-year { font-size: 12px; color: var(--muted); }
    .vc-status { margin-top: 12px; }
    .add-vehicle-card {
      border: 1px dashed var(--border-strong); border-radius: 12px; padding: 18px 20px;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      color: var(--muted); font-size: 13px; font-weight: 500; text-decoration: none;
      transition: border-color 0.15s, color 0.15s; min-height: 110px;
    }
    .add-vehicle-card:hover { border-color: var(--text); color: var(--text); }

    .occ-item { display: grid; grid-template-columns: auto 1fr auto; align-items: start; gap: 14px; padding: 18px 22px; border-bottom: 1px solid var(--border); text-decoration: none; transition: background 0.1s; }
    .occ-item:last-child { border-bottom: none; }
    .occ-item:hover { background: var(--bg); }
    .occ-index { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 3px 7px; white-space: nowrap; margin-top: 1px; }
    .occ-component { font-size: 13.5px; font-weight: 500; margin-bottom: 4px; }
    .occ-desc { font-size: 13px; color: var(--muted); line-height: 1.5; }
    .occ-meta { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; margin-top: 6px; }
    .occ-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }

    .proto-item { padding: 16px 22px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 12px; text-decoration: none; transition: background 0.1s; }
    .proto-item:last-child { border-bottom: none; }
    .proto-item:hover { background: var(--bg); }
    .proto-num { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
    .proto-desc { font-size: 13.5px; font-weight: 500; margin-bottom: 3px; }
    .proto-date { font-size: 12px; color: var(--muted); }

    .profile-avatar-row { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--border); }
    .profile-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: var(--on-accent); font-size: 16px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .profile-name { font-size: 14px; font-weight: 600; }
    .profile-since { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }
    .ready-banner { border-color: var(--tag-ready-t); background: var(--tag-ready); color: var(--tag-ready-t); }

    @media (max-width: 760px) {
      .vehicles-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 20px; }
      .vehicle-card { padding: 14px; }
      .add-vehicle-card { min-height: 90px; }
      .occ-item { grid-template-columns: 1fr; gap: 8px; padding: 14px 16px; }
      .occ-right { flex-direction: row; align-items: center; }
      .proto-item { padding: 14px 16px; }
    }
  </style>
</head>
<body>
<?php sidebar('dashboard'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Olá, <?= e(primeiro_nome($u['nome'])) ?></div>
      <div class="page-sub">Acompanhe aqui os seus veículos e atendimentos.</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <?php foreach ($prontos as $pr): ?>
    <div class="reminder-banner ready-banner">
      <span style="font-size:20px">🎉</span>
      <div class="grow">Seu <strong><?= e($pr['marca'] . ' ' . $pr['modelo']) ?> (<?= e($pr['placa']) ?>)</strong> está pronto para retirada!</div>
      <a class="btn-outline btn-sm" href="visualizar_ocorrencias.php?v=<?= urlencode($pr['id']) ?>">Ver detalhes</a>
    </div>
  <?php endforeach; ?>

  <?php if ($semAvaliacao): ?>
    <div class="reminder-banner" style="border-color:var(--border);background:var(--surface);color:var(--text)">
      <span style="font-size:20px">⭐</span>
      <div class="grow">Como foi o atendimento <strong><?= proto_num($semAvaliacao['numero']) ?></strong> do veículo <?= e($semAvaliacao['placa']) ?>? Sua avaliação ajuda a oficina a melhorar.</div>
      <a class="btn-primary btn-sm" href="historico.php?numero=<?= (int)$semAvaliacao['numero'] ?>#p<?= (int)$semAvaliacao['numero'] ?>">Avaliar agora</a>
    </div>
  <?php endif; ?>

  <?php foreach ($revisoes as $r): ?>
    <div class="reminder-banner">
      <span style="font-size:20px">🔧</span>
      <div class="grow">Já se passaram <strong><?= plural(meses_desde($r['ultimo']), 'mês', 'meses') ?></strong> desde o último serviço do seu
        <?= e($r['marca'] . ' ' . $r['modelo']) ?> (<?= e($r['placa']) ?>). Recomendamos agendar uma revisão preventiva com a oficina.</div>
    </div>
  <?php endforeach; ?>

  <div class="vehicles-row">
    <?php foreach ($veiculos as $v): ?>
    <a class="vehicle-card<?= $sel && $sel['id'] === $v['id'] ? ' selected' : '' ?>" href="<?= e(qs_dash(['v' => $v['id']])) ?>">
      <div class="vc-plate"><?= e($v['placa']) ?></div>
      <div class="vc-model"><?= e($v['marca'] . ' ' . $v['modelo']) ?></div>
      <div class="vc-year"><?= e($v['ano'] . ' · ' . $v['cor']) ?></div>
      <div class="vc-status"><?= badge(status_veiculo($v['statusAtivo'])) ?></div>
    </a>
    <?php endforeach; ?>

    <a class="add-vehicle-card" href="meus_veiculos.php?novo=1">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Cadastrar veículo
    </a>
  </div>

  <div class="two-col">
    <div class="stack">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Ocorrências<?= $sel ? ' — ' . e($sel['placa']) : '' ?></div>
          <?php if ($sel): ?><?= badge(status_veiculo($sel['statusAtivo'])) ?><?php endif; ?>
        </div>

        <?php if (!$sel): ?>
          <div class="empty-state">
            <div class="empty-icon">🚗</div>
            Você ainda não tem veículos cadastrados.<br>
            <a href="meus_veiculos.php?novo=1" style="font-weight:500">Cadastre seu primeiro veículo</a>.
          </div>
        <?php else: ?>
        <div class="tab-bar">
          <a class="tab<?= $aba === 'abertas' ? ' active' : '' ?>" href="<?= e(qs_dash(['tab' => null])) ?>">Em andamento</a>
          <a class="tab<?= $aba === 'todas' ? ' active' : '' ?>" href="<?= e(qs_dash(['tab' => 'todas'])) ?>">Todas</a>
        </div>

        <div>
          <?php if (!$ocorrencias): ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <?= $aba === 'abertas' ? 'Nenhum atendimento em andamento para este veículo.' : 'Nenhuma ocorrência registrada para este veículo.' ?>
            </div>
          <?php endif; ?>
          <?php foreach ($ocorrencias as $o): ?>
          <a class="occ-item" href="visualizar_ocorrencias.php?numero=<?= (int)$o['numero'] ?>">
            <div class="occ-index"><?= proto_num($o['numero']) ?></div>
            <div>
              <div class="occ-component"><?= e($o['componentes'] ?: 'Ocorrência') ?></div>
              <div class="occ-desc"><?= e($o['descricao']) ?></div>
              <div class="occ-meta">Registrado por <?= e($o['colaborador']) ?> · <?= fmt_data_hora($o['dataRegistro']) ?>
                <?php if ($o['previsaoEntrega'] && in_array($o['status'], STATUS_ATIVOS, true)): ?> · previsão <?= fmt_data($o['previsaoEntrega']) ?><?php endif; ?></div>
            </div>
            <div class="occ-right"><?= badge(status_protocolo($o['status'])) ?></div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Histórico de atendimentos</div>
          <a class="card-action" href="historico.php">Ver todos →</a>
        </div>
        <div>
          <?php if (!$historico): ?>
            <div class="empty-state">Nenhum atendimento finalizado ainda.</div>
          <?php endif; ?>
          <?php foreach ($historico as $h): ?>
          <a class="proto-item" href="historico.php?numero=<?= (int)$h['numero'] ?>#p<?= (int)$h['numero'] ?>">
            <div style="min-width:0">
              <div class="proto-num"><?= proto_num($h['numero']) ?></div>
              <div class="proto-desc"><?= e(mb_strimwidth($h['descricao'], 0, 60, '…')) ?> — <?= e($h['placa']) ?></div>
              <div class="proto-date"><?= fmt_data_curta($h['dataFinalizacao']) ?> · <?= e($h['colaborador']) ?></div>
            </div>
            <?= $h['nota'] ? estrelas((int)$h['nota']) : '<span class="badge wait">Avaliar</span>' ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="stack">
      <?php if ($sel): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Detalhes do veículo</div>
        </div>
        <div class="kv-list">
          <div class="kv-row"><span class="kv-key">Placa</span><span class="kv-val mono"><?= e($sel['placa']) ?></span></div>
          <div class="kv-divider"></div>
          <div class="kv-row"><span class="kv-key">Marca</span><span class="kv-val"><?= e($sel['marca']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Modelo</span><span class="kv-val"><?= e($sel['modelo']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Ano</span><span class="kv-val"><?= e($sel['ano']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Cor</span><span class="kv-val"><?= e($sel['cor']) ?></span></div>
          <div class="kv-divider"></div>
          <div class="kv-row"><span class="kv-key">Em andamento</span><span class="kv-val"><?= (int)$sel['abertas'] ?></span></div>
          <div class="kv-row"><span class="kv-key">Total de protocolos</span><span class="kv-val"><?= (int)$sel['protocolos'] ?></span></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Meu perfil</div>
          <a class="card-action" href="perfil.php">Editar →</a>
        </div>
        <div class="kv-list">
          <div class="profile-avatar-row">
            <div class="profile-avatar"><?= e(initials($cliente['nome'])) ?></div>
            <div>
              <div class="profile-name"><?= e($cliente['nome']) ?></div>
              <div class="profile-since"><?= plural(count($veiculos), 'veículo', 'veículos') ?></div>
            </div>
          </div>
          <div class="info-item"><label>E-mail</label><span style="font-weight:400"><?= e($cliente['email']) ?></span></div>
          <div class="info-item"><label>Telefone</label><span style="font-weight:400"><?= e(fmt_tel($cliente['telefone'])) ?></span></div>
          <div class="info-item"><label>Endereço</label><span style="font-weight:400"><?= e($cliente['endereco']) ?></span></div>
          <div class="info-item"><label>CPF</label><span class="mono" style="font-weight:400;font-size:12.5px">•••.<?= e(substr($cliente['cpf'], 3, 3)) ?>.•••-<?= e(substr($cliente['cpf'], 9, 2)) ?></span></div>
        </div>
        <div class="card-footer" style="border-top:none;padding-top:0">
          <a class="btn-outline btn-block" href="perfil.php">Editar dados cadastrais</a>
        </div>
      </div>
    </div>
  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
