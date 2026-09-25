<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

$cliente = db_one('SELECT nome, cpf, email, telefone, endereco FROM cliente WHERE id = ?', [$u['id']]);

// ─── MEUS VEÍCULOS ───────────────────────────────────────────────────────────
$veiculos = db_all("SELECT v.id, v.placa, v.ano, v.cor, mo.nome AS modelo, ma.nome AS marca,
                           " . SQL_STATUS_ATIVO . " AS statusAtivo,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id AND p.status IN ('ABERTO','EM_ATENDIMENTO')) AS abertas,
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
$ocorrencias = $sel ? db_all("SELECT p.numero, p.status, o.descricao, o.componentes, o.dataRegistro, col.nome AS colaborador
                              FROM protocolo p
                              JOIN ocorrencia o    ON o.id = p.idOcorrencia
                              JOIN colaborador col ON col.id = p.responsavel
                              WHERE p.idVeiculo = ?" . ($aba === 'abertas' ? " AND p.status IN ('ABERTO','EM_ATENDIMENTO')" : '') . "
                              ORDER BY p.numero DESC", [$sel['id']]) : [];

// ─── HISTÓRICO (todos os veículos) ───────────────────────────────────────────
$historico = db_all("SELECT p.numero, p.dataFinalizacao, o.descricao, v.placa, col.nome AS colaborador
                     FROM protocolo p
                     JOIN ocorrencia o    ON o.id = p.idOcorrencia
                     JOIN veiculo v       ON v.id = p.idVeiculo
                     JOIN colaborador col ON col.id = p.responsavel
                     WHERE v.idCliente = ? AND p.status = 'FINALIZADO'
                     ORDER BY p.numero DESC LIMIT 4", [$u['id']]);

function qs_dash(array $troca): string {
    return '?' . http_build_query(array_filter(array_merge($_GET, $troca), fn($v) => $v !== null && $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Cliente</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #F5F4F2;
      --surface:   #FFFFFF;
      --border:    #E4E2DE;
      --text:      #1A1917;
      --muted:     #7A7872;
      --tag-open:  #E8F2EC;
      --tag-open-t:#2D7A4F;
      --tag-prog:  #FEF4E4;
      --tag-prog-t:#A05A00;
      --tag-done:  #EDECEA;
      --tag-done-t:#5A5750;
      --sidebar-w: 230px;
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      min-height: 100vh;
    }

    /* ── SIDEBAR ── */
    aside {
      width: var(--sidebar-w);
      min-height: 100vh;
      background: var(--surface);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      padding: 28px 0;
      position: fixed;
      top: 0; left: 0;
    }
    .logo { padding: 0 24px 28px; border-bottom: 1px solid var(--border); }
    .logo-mark {
      font-family: 'DM Mono', monospace;
      font-size: 13px; font-weight: 500;
      letter-spacing: 0.08em; color: var(--muted);
      text-transform: uppercase; margin-bottom: 4px;
    }
    .logo-name { font-size: 15px; font-weight: 600; line-height: 1.3; }

    nav {
      flex: 1; padding: 20px 12px;
      display: flex; flex-direction: column; gap: 2px;
    }
    .nav-label {
      font-size: 10px; font-weight: 500;
      letter-spacing: 0.1em; text-transform: uppercase;
      color: var(--muted); padding: 14px 12px 6px;
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; border-radius: 8px;
      font-size: 14px; color: var(--muted);
      cursor: pointer; text-decoration: none;
      transition: background 0.15s, color 0.15s;
    }
    .nav-item:hover { background: var(--bg); color: var(--text); }
    .nav-item.active { background: var(--text); color: #fff; }
    .nav-item .icon { width: 18px; height: 18px; opacity: 0.7; flex-shrink: 0; }
    .nav-item.active .icon { opacity: 1; }

    .sidebar-footer { padding: 20px 24px 0; border-top: 1px solid var(--border); }
    .user-chip { display: flex; align-items: center; gap: 10px; }
    .avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--text); color: #fff;
      font-size: 12px; font-weight: 600;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .user-name { font-size: 13px; font-weight: 500; }
    .user-role { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }

    /* ── MAIN ── */
    main { margin-left: var(--sidebar-w); flex: 1; padding: 40px 48px; }

    .topbar {
      display: flex; align-items: center;
      justify-content: flex-end; gap: 12px; margin-bottom: 32px;
    }
    .notif-btn {
      width: 36px; height: 36px; border: 1px solid var(--border);
      border-radius: 8px; background: var(--surface);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; position: relative;
    }
    .notif-dot {
      width: 7px; height: 7px; background: #C0392B; border-radius: 50%;
      position: absolute; top: 7px; right: 7px; border: 1.5px solid white;
    }

    .page-header {
      display: flex; align-items: flex-end;
      justify-content: space-between; margin-bottom: 32px;
    }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .page-date { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }

    /* ── VEÍCULOS ── */
    .vehicles-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 28px;
    }
    .vehicle-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 18px 20px;
      cursor: pointer;
      transition: border-color 0.15s, box-shadow 0.15s;
      position: relative;
    }
    .vehicle-card:hover { border-color: #ccc; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .vehicle-card.selected { border-color: var(--text); }
    .vc-plate {
      font-family: 'DM Mono', monospace;
      font-size: 15px; font-weight: 500;
      letter-spacing: 0.06em;
      margin-bottom: 6px;
    }
    .vc-model { font-size: 13px; color: var(--text); margin-bottom: 2px; }
    .vc-year  { font-size: 12px; color: var(--muted); }
    .vc-status {
      position: absolute; top: 16px; right: 16px;
    }
    .badge {
      display: inline-flex; align-items: center;
      font-size: 11.5px; font-weight: 500;
      padding: 3px 9px; border-radius: 20px;
    }
    .badge.open  { background: var(--tag-open);  color: var(--tag-open-t); }
    .badge.prog  { background: var(--tag-prog);  color: var(--tag-prog-t); }
    .badge.done  { background: var(--tag-done);  color: var(--tag-done-t); }

    .add-vehicle-card {
      background: none;
      border: 1px dashed var(--border);
      border-radius: 12px;
      padding: 18px 20px;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      gap: 8px;
      color: var(--muted); font-size: 13px; font-weight: 500;
      transition: border-color 0.15s, color 0.15s;
    }
    .add-vehicle-card:hover { border-color: var(--text); color: var(--text); }

    /* ── TWO-COL ── */
    .two-col { display: grid; grid-template-columns: 1fr 320px; gap: 20px; }

    /* ── CARD ── */
    .card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; overflow: hidden;
    }
    .card-header {
      padding: 18px 22px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .card-title { font-size: 14px; font-weight: 600; }
    .card-action {
      font-size: 12px; color: var(--muted); cursor: pointer;
      font-weight: 500; border: none; background: none; font-family: inherit;
    }
    .card-action:hover { color: var(--text); }

    /* ── TABS ── */
    .tab-bar {
      display: flex; border-bottom: 1px solid var(--border); padding: 0 22px;
    }
    .tab {
      font-size: 13px; font-weight: 500; color: var(--muted);
      padding: 12px 16px 11px;
      border-bottom: 2px solid transparent;
      cursor: pointer; background: none;
      border-left: none; border-right: none; border-top: none;
      font-family: inherit; transition: color 0.15s, border-color 0.15s;
    }
    .tab:hover { color: var(--text); }
    .tab.active { color: var(--text); border-bottom-color: var(--text); }

    /* ── OCORRÊNCIAS ── */
    .occ-list { padding: 0; }
    .occ-item {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: start;
      gap: 14px;
      padding: 18px 22px;
      border-bottom: 1px solid var(--border);
      transition: background 0.1s;
    }
    .occ-item:last-child { border-bottom: none; }
    .occ-item:hover { background: var(--bg); }
    .occ-index {
      font-family: 'DM Mono', monospace;
      font-size: 11px; color: var(--muted);
      background: var(--bg); border: 1px solid var(--border);
      border-radius: 5px; padding: 3px 7px;
      white-space: nowrap; margin-top: 1px;
    }
    .occ-component {
      font-size: 13.5px; font-weight: 500; margin-bottom: 4px;
    }
    .occ-desc {
      font-size: 13px; color: var(--muted); line-height: 1.5;
    }
    .occ-meta {
      font-size: 11px; color: var(--muted);
      font-family: 'DM Mono', monospace; margin-top: 6px;
    }
    .occ-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }

    /* ── PROTOCOLO CARD ── */
    .proto-list { padding: 0; }
    .proto-item {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      display: flex; justify-content: space-between; align-items: flex-start;
      cursor: pointer; transition: background 0.1s;
    }
    .proto-item:last-child { border-bottom: none; }
    .proto-item:hover { background: var(--bg); }
    .proto-num {
      font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); margin-bottom: 4px;
    }
    .proto-desc { font-size: 13.5px; font-weight: 500; margin-bottom: 3px; }
    .proto-date { font-size: 12px; color: var(--muted); }
    .proto-arrow { color: var(--muted); margin-top: 2px; }

    /* ── RIGHT COLUMN ── */
    .right-col { display: flex; flex-direction: column; gap: 20px; }

    /* detalhes do veículo */
    .vi-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 11px; }
    .vi-row { display: flex; justify-content: space-between; align-items: center; }
    .vi-key { font-size: 12px; color: var(--muted); font-weight: 500; }
    .vi-val { font-size: 13px; font-weight: 500; }
    .vi-val.mono { font-family: 'DM Mono', monospace; }
    .vi-divider { height: 1px; background: var(--border); }

    /* perfil do cliente */
    .profile-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 11px; }
    .profile-avatar-row {
      display: flex; align-items: center; gap: 14px;
      padding-bottom: 14px; border-bottom: 1px solid var(--border);
      margin-bottom: 2px;
    }
    .profile-avatar {
      width: 44px; height: 44px; border-radius: 50%;
      background: var(--text); color: #fff;
      font-size: 16px; font-weight: 600;
      display: flex; align-items: center; justify-content: center;
    }
    .profile-name { font-size: 14px; font-weight: 600; }
    .profile-since { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }
    .field-row { display: flex; flex-direction: column; gap: 3px; }
    .field-label { font-size: 11px; color: var(--muted); font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; }
    .field-value { font-size: 13px; }

    .btn-ghost {
      padding: 9px 14px; border-radius: 8px;
      background: none; color: var(--text);
      font-size: 13px; font-weight: 500; font-family: inherit;
      border: 1px solid var(--border); cursor: pointer;
      transition: background 0.15s; width: 100%; text-align: center;
    }
    .btn-ghost:hover { background: var(--bg); }

    /* empty state */
    .empty-state {
      padding: 36px 22px; text-align: center;
      color: var(--muted); font-size: 13px; line-height: 1.7;
    }
    .empty-icon { font-size: 26px; margin-bottom: 8px; }
  </style>
</head>
<body>
<style>
  a.vehicle-card, a.add-vehicle-card, a.tab, a.card-action, a.proto-item { text-decoration: none; color: inherit; display: block; }
  a.add-vehicle-card { display: flex; color: var(--muted); }
  a.card-action { color: var(--muted); }
  a.proto-item { display: flex; }
</style>

<?php sidebar('dashboard'); ?>

<!-- MAIN -->
<main>

  <!-- header -->
  <div class="page-header">
    <div>
      <div class="page-title">Olá, <?= e(primeiro_nome($u['nome'])) ?></div>
      <div class="page-sub">Acompanhe aqui os seus veículos e atendimentos.</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <!-- veículos -->
  <div class="vehicles-row">
    <?php foreach ($veiculos as $v): ?>
    <a class="vehicle-card<?= $sel && $sel['id'] === $v['id'] ? ' selected' : '' ?>" href="<?= e(qs_dash(['v' => $v['id']])) ?>">
      <div class="vc-status"><?= badge(status_veiculo($v['statusAtivo'])) ?></div>
      <div class="vc-plate"><?= e($v['placa']) ?></div>
      <div class="vc-model"><?= e($v['marca'] . ' ' . $v['modelo']) ?></div>
      <div class="vc-year"><?= e($v['ano'] . ' · ' . $v['cor']) ?></div>
    </a>
    <?php endforeach; ?>

    <a class="add-vehicle-card" href="meus_veiculos.php?novo=1">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      Cadastrar veículo
    </a>
  </div>

  <!-- two col -->
  <div class="two-col">

    <!-- LEFT -->
    <div style="display:flex; flex-direction:column; gap:20px;">

      <!-- Ocorrências do veículo -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Ocorrências<?= $sel ? ' — ' . e($sel['placa']) : '' ?></div>
          <?php if ($sel): ?><?= badge(status_veiculo($sel['statusAtivo'])) ?><?php endif; ?>
        </div>

        <?php if (!$sel): ?>
          <div class="empty-state">
            <div class="empty-icon">🚗</div>
            Você ainda não tem veículos cadastrados.<br>
            <a href="meus_veiculos.php?novo=1" style="color:var(--text);font-weight:500">Cadastre seu primeiro veículo</a>.
          </div>
        <?php else: ?>
        <div class="tab-bar">
          <a class="tab<?= $aba === 'abertas' ? ' active' : '' ?>" href="<?= e(qs_dash(['tab' => null])) ?>">Abertas</a>
          <a class="tab<?= $aba === 'todas' ? ' active' : '' ?>" href="<?= e(qs_dash(['tab' => 'todas'])) ?>">Todas</a>
        </div>

        <div class="occ-list">
          <?php if (!$ocorrencias): ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <?= $aba === 'abertas' ? 'Nenhuma ocorrência em aberto para este veículo.' : 'Nenhuma ocorrência registrada para este veículo.' ?>
            </div>
          <?php endif; ?>
          <?php foreach ($ocorrencias as $o): ?>
          <div class="occ-item">
            <div class="occ-index"><?= proto_num($o['numero']) ?></div>
            <div class="occ-body">
              <div class="occ-component"><?= e($o['componentes'] ?: 'Ocorrência') ?></div>
              <div class="occ-desc"><?= e($o['descricao']) ?></div>
              <div class="occ-meta">Registrado por <?= e($o['colaborador']) ?> · <?= fmt_data_hora($o['dataRegistro']) ?></div>
            </div>
            <div class="occ-right">
              <?= badge(status_protocolo($o['status'])) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Histórico de protocolos -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Histórico de atendimentos</div>
          <a class="card-action" href="historico.php">Ver todos →</a>
        </div>

        <div class="proto-list">
          <?php if (!$historico): ?>
            <div class="empty-state">Nenhum atendimento finalizado ainda.</div>
          <?php endif; ?>
          <?php foreach ($historico as $h): ?>
          <a class="proto-item" href="historico.php?numero=<?= (int)$h['numero'] ?>#p<?= (int)$h['numero'] ?>">
            <div>
              <div class="proto-num"><?= proto_num($h['numero']) ?></div>
              <div class="proto-desc"><?= e(mb_strimwidth($h['descricao'], 0, 60, '…')) ?> — <?= e($h['placa']) ?></div>
              <div class="proto-date"><?= fmt_data_curta($h['dataFinalizacao']) ?> · <?= e($h['colaborador']) ?></div>
            </div>
            <svg class="proto-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18l6-6-6-6"/></svg>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- RIGHT -->
    <div class="right-col">

      <!-- Detalhes do veículo selecionado -->
      <?php if ($sel): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Detalhes do veículo</div>
        </div>
        <div class="vi-body">
          <div class="vi-row"><span class="vi-key">Placa</span><span class="vi-val mono"><?= e($sel['placa']) ?></span></div>
          <div class="vi-divider"></div>
          <div class="vi-row"><span class="vi-key">Marca</span><span class="vi-val"><?= e($sel['marca']) ?></span></div>
          <div class="vi-row"><span class="vi-key">Modelo</span><span class="vi-val"><?= e($sel['modelo']) ?></span></div>
          <div class="vi-row"><span class="vi-key">Ano</span><span class="vi-val"><?= e($sel['ano']) ?></span></div>
          <div class="vi-row"><span class="vi-key">Cor</span><span class="vi-val"><?= e($sel['cor']) ?></span></div>
          <div class="vi-divider"></div>
          <div class="vi-row"><span class="vi-key">Ocorrências abertas</span><span class="vi-val"><?= (int)$sel['abertas'] ?></span></div>
          <div class="vi-row"><span class="vi-key">Total de protocolos</span><span class="vi-val"><?= (int)$sel['protocolos'] ?></span></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Perfil -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Meu perfil</div>
          <a class="card-action" href="perfil.php">Editar →</a>
        </div>
        <div class="profile-body">
          <div class="profile-avatar-row">
            <div class="profile-avatar"><?= e(initials($cliente['nome'])) ?></div>
            <div>
              <div class="profile-name"><?= e($cliente['nome']) ?></div>
              <div class="profile-since"><?= plural(count($veiculos), 'veículo', 'veículos') ?></div>
            </div>
          </div>

          <div class="field-row">
            <span class="field-label">E-mail</span>
            <span class="field-value"><?= e($cliente['email']) ?></span>
          </div>
          <div class="vi-divider"></div>
          <div class="field-row">
            <span class="field-label">Telefone</span>
            <span class="field-value"><?= e(fmt_tel($cliente['telefone'])) ?></span>
          </div>
          <div class="vi-divider"></div>
          <div class="field-row">
            <span class="field-label">Endereço</span>
            <span class="field-value"><?= e($cliente['endereco']) ?></span>
          </div>
          <div class="vi-divider"></div>
          <div class="field-row">
            <span class="field-label">CPF</span>
            <span class="field-value" style="font-family:'DM Mono',monospace;font-size:12.5px;">•••.<?= e(substr($cliente['cpf'], 3, 3)) ?>.•••-<?= e(substr($cliente['cpf'], 9, 2)) ?></span>
          </div>
        </div>
        <div style="padding: 0 22px 18px;">
          <a class="btn-ghost" href="perfil.php" style="display:block;text-decoration:none">Editar dados cadastrais</a>
        </div>
      </div>

    </div>
  </div>

</main>

<?php flash_html(); ?>
</body>
</html>
