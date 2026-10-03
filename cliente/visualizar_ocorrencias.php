<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

$veiculos = db_all('SELECT v.id, v.placa, mo.nome AS modelo, ma.nome AS marca
                    FROM veiculo v JOIN modelo mo ON mo.id = v.idModelo JOIN marca ma ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa', [$u['id']]);

// Protocolo pedido na URL (ex.: vindo de uma notificação) define o veículo selecionado.
$numeroFoco = (int)($_GET['numero'] ?? 0);
$idVeiculoFoco = $numeroFoco ? db_val('SELECT p.idVeiculo FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                                       WHERE p.numero = ? AND v.idCliente = ?', [$numeroFoco, $u['id']]) : null;

$sel = $veiculos[0] ?? null;
foreach ($veiculos as $v) if ($v['id'] === ($idVeiculoFoco ?? $_GET['v'] ?? '')) $sel = $v;

// Ocorrências (com protocolo) do veículo selecionado, mais recentes primeiro.
$ocorrencias = $sel ? db_all("SELECT p.numero, p.status, p.kmEntrada, p.previsaoEntrega, p.dataFinalizacao,
                                     o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                                     col.nome AS colaborador, col.cargo
                              FROM protocolo p
                              JOIN ocorrencia o    ON o.id = p.idOcorrencia
                              JOIN colaborador col ON col.id = p.responsavel
                              WHERE p.idVeiculo = ? ORDER BY p.numero DESC", [$sel['id']]) : [];

// Itens, fotos e etapas de todas as ocorrências listadas, agrupados por protocolo.
$itens = $fotos = $etapas = [];
if ($ocorrencias) {
    $ids  = array_column($ocorrencias, 'idOcorrencia');
    $nums = array_map('intval', array_column($ocorrencias, 'numero'));
    $marc = fn($n) => implode(',', array_fill(0, $n, '?'));
    foreach (db_all('SELECT idOcorrencia, descricao FROM itemocorrencia WHERE idOcorrencia IN (' . $marc(count($ids)) . ')
                     ORDER BY dataHora, id', $ids) as $r) $itens[$r['idOcorrencia']][] = $r['descricao'];
    foreach (db_all('SELECT numeroProtocolo, arquivo, legenda FROM foto WHERE numeroProtocolo IN (' . $marc(count($nums)) . ')
                     ORDER BY dataHora, id', $nums) as $r) $fotos[$r['numeroProtocolo']][] = $r;
    foreach (db_all('SELECT s.numeroProtocolo, s.status, s.dataHora, NULL AS colaborador FROM protocolo_status s
                     WHERE s.numeroProtocolo IN (' . $marc(count($nums)) . ') ORDER BY s.dataHora, s.id', $nums) as $r) $etapas[$r['numeroProtocolo']][] = $r;
}
$expandido = $numeroFoco ?: (int)($ocorrencias[0]['numero'] ?? 0);

// Etapas resumidas exibidas no "rastreio" e a posição de cada status nelas.
const ETAPAS_RASTREIO = ['Recebido', 'Em serviço', 'Pronto', 'Entregue'];
function posicao_rastreio(string $status): int {
    return match ($status) {
        'ABERTO'     => 0,
        'PRONTO'     => 2,
        'FINALIZADO' => 3,
        default      => 1,   // em atendimento ou aguardando peças/aprovação
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Ocorrências'); ?>
  <style>
    .occurrence-list { display: flex; flex-direction: column; gap: 14px; }
    .occ-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
    .occ-card.expanded { border-color: var(--border-strong); }
    .occ-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 22px; cursor: pointer; user-select: none; width: 100%; background: none; border: none; text-align: left; }
    .occ-header:hover { background: var(--surface-2); }
    .occ-meta { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; }
    .occ-id { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }
    .occ-date { font-size: 13px; color: var(--muted); }
    .occ-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .occ-toggle { color: var(--muted); transition: transform 0.2s; }
    .occ-card.expanded .occ-toggle { transform: rotate(180deg); }
    .occ-body { display: none; border-top: 1px solid var(--border); }
    .occ-card.expanded .occ-body { display: block; }
    .occ-content { display: grid; grid-template-columns: minmax(0, 1fr) 280px; }
    .occ-main { padding: 20px 22px; display: flex; flex-direction: column; gap: 18px; min-width: 0; }
    .occ-side { border-left: 1px solid var(--border); }
    .occ-side .section-label { padding: 18px 22px 0; margin-bottom: 0; }
    .occ-summary { font-size: 13px; color: var(--muted); line-height: 1.5; }
    .waiting-note { margin: 4px 22px 0; }
    .facts { display: flex; gap: 24px; flex-wrap: wrap; font-size: 13px; }
    .facts .info-label { margin-bottom: 2px; }
    @media (max-width: 900px) {
      .occ-content { grid-template-columns: 1fr; }
      .occ-side { border-left: none; border-top: 1px solid var(--border); }
    }
    @media (max-width: 760px) {
      .occ-header { padding: 14px 16px; }
      .occ-main { padding: 16px; }
      .occ-side .section-label { padding: 16px 16px 0; }
      .progress-track { padding-left: 8px; padding-right: 8px; }
      .waiting-note { margin: 4px 16px 0; }
    }
  </style>
</head>
<body>
<?php sidebar('ocorrencias'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Ocorrências</div>
      <div class="page-sub">Acompanhe o andamento dos serviços nos seus veículos.</div>
    </div>
  </div>

  <?php if (!$veiculos): ?>
    <div class="empty-state lg">
      <div class="empty-icon">🚗</div>
      <div class="empty-title">Nenhum veículo cadastrado</div>
      <a href="meus_veiculos.php?novo=1">Cadastre um veículo</a> para acompanhar as ocorrências.
    </div>
  <?php else: ?>

  <div class="vehicle-tabs">
    <?php foreach ($veiculos as $v): ?>
      <a class="vtab<?= $v['id'] === $sel['id'] ? ' active' : '' ?>" href="?v=<?= urlencode($v['id']) ?>">
        <span class="vtab-plate"><?= e($v['placa']) ?></span>
        <span class="vtab-model"><?= e($v['marca'] . ' ' . $v['modelo']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$ocorrencias): ?>
    <div class="empty-state lg">
      <div class="empty-icon">📋</div>
      <div class="empty-title">Nenhuma ocorrência registrada</div>
      Seu <?= e($sel['marca'] . ' ' . $sel['modelo']) ?> (<?= e($sel['placa']) ?>) não possui ocorrências.
    </div>
  <?php else: ?>
  <div class="occurrence-list">
    <?php foreach ($ocorrencias as $o):
      $num = (int)$o['numero'];
      $cancelado = $o['status'] === 'CANCELADO';
      $pos = $cancelado ? -1 : posicao_rastreio($o['status']);
      $late = atrasado($o['previsaoEntrega'], $o['status']);
    ?>
    <div class="occ-card<?= $num === $expandido ? ' expanded' : '' ?>" id="p<?= $num ?>">
      <button type="button" class="occ-header" onclick="this.parentElement.classList.toggle('expanded')" aria-expanded="<?= $num === $expandido ? 'true' : 'false' ?>">
        <span class="occ-meta">
          <span class="occ-id"><?= proto_num($num) ?></span>
          <span class="occ-date"><?= fmt_data_curta($o['dataRegistro']) ?><?= $o['componentes'] ? ' · ' . e($o['componentes']) : '' ?></span>
        </span>
        <span class="occ-right">
          <?= badge(status_protocolo($o['status'])) ?>
          <svg class="occ-toggle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </span>
      </button>

      <div class="occ-body">
        <?php if (!$cancelado): ?>
          <div class="progress-track" aria-label="Andamento do serviço">
            <?php foreach (ETAPAS_RASTREIO as $i => $rotulo): $cls = $i < $pos || ($i === $pos && $pos === 3) ? 'done' : ($i === $pos ? 'current' : ''); ?>
              <div class="track-step <?= $cls ?>">
                <div class="track-dot"><?= $cls === 'done' ? '✓' : '' ?></div>
                <?= e($i === 1 && in_array($o['status'], ['AGUARDANDO_PECAS', 'AGUARDANDO_APROVACAO'], true) ? status_protocolo($o['status'])[1] : $rotulo) ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($o['status'] === 'AGUARDANDO_APROVACAO'): ?>
            <div class="notice info waiting-note">A oficina aguarda a sua autorização para continuar o serviço. Entre em contato para aprovar.</div>
          <?php elseif ($o['status'] === 'PRONTO'): ?>
            <div class="notice waiting-note" style="background:var(--tag-ready);color:var(--tag-ready-t);border-color:var(--tag-ready-t)">Seu veículo está pronto! Você já pode retirá-lo na oficina.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="notice err waiting-note" style="margin-top:18px">Este atendimento foi cancelado em <?= fmt_data($o['dataFinalizacao']) ?>.</div>
        <?php endif; ?>

        <div class="occ-content">
          <div class="occ-main">
            <div class="facts">
              <div><div class="info-label">Responsável</div><strong><?= e($o['colaborador']) ?></strong> · <?= e($o['cargo']) ?></div>
              <?php if ($o['kmEntrada']): ?><div><div class="info-label">KM de entrada</div><?= fmt_km($o['kmEntrada']) ?></div><?php endif; ?>
              <?php if ($o['previsaoEntrega'] && in_array($o['status'], STATUS_ATIVOS, true)): ?>
                <div><div class="info-label">Previsão de entrega</div><span<?= $late ? ' class="text-danger"' : '' ?>><?= fmt_data($o['previsaoEntrega']) ?><?= $late ? ' (atrasado)' : '' ?></span></div>
              <?php endif; ?>
            </div>

            <div>
              <div class="section-label">Resumo</div>
              <div class="occ-summary"><?= e($o['descricao']) ?></div>
            </div>

            <div>
              <div class="section-label">Itens da ocorrência</div>
              <div class="item-list">
                <?php foreach ($itens[$o['idOcorrencia']] ?? [] as $n => $desc): ?>
                <div class="item-view">
                  <span class="item-num"><?= str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) ?></span>
                  <span class="item-desc"><?= e($desc) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <?php if (!empty($fotos[$num])): ?>
            <div>
              <div class="section-label">Fotos do atendimento</div>
              <div class="photo-grid">
                <?php foreach ($fotos[$num] as $f): $src = '../' . PASTA_FOTOS . $f['arquivo']; ?>
                  <div class="photo">
                    <a href="<?= e($src) ?>" data-lightbox data-caption="<?= e($f['legenda'] ?? '') ?>"><img src="<?= e($src) ?>" alt="<?= e($f['legenda'] ?: 'Foto do atendimento') ?>" loading="lazy"/></a>
                    <?php if ($f['legenda']): ?><div class="photo-caption"><?= e($f['legenda']) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <div class="occ-side">
            <div class="section-label">Linha do tempo</div>
            <?php linha_do_tempo($etapas[$num] ?? [], $o['previsaoEntrega']); ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</main>

<?php flash_html(); ?>
<?php if ($numeroFoco): ?>
<script>document.addEventListener('DOMContentLoaded', () => document.getElementById('p<?= $numeroFoco ?>')?.scrollIntoView({ block: 'start' }));</script>
<?php endif; ?>
</body>
</html>
