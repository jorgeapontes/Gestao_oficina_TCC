<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

// ─── AVALIAR ATENDIMENTO ─────────────────────────────────────────────────────
// Só protocolos finalizados dos veículos do próprio cliente, uma única vez.
if (acao() === 'avaliar') {
    $numero = (int)post('numero');
    $nota   = (int)post('nota');
    $coment = mb_substr(post('comentario'), 0, 500);
    $p = db_one("SELECT p.numero, p.responsavel, v.placa FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                 WHERE p.numero = ? AND v.idCliente = ? AND p.status = 'FINALIZADO'", [$numero, $u['id']]);
    $volta = 'historico.php?numero=' . $numero . '#p' . $numero;

    if (!$p) {
        flash('err', 'Atendimento não encontrado.');
    } elseif ($nota < 1 || $nota > 5) {
        flash('err', 'Escolha uma nota de 1 a 5 estrelas.');
    } elseif (db_val('SELECT 1 FROM avaliacao WHERE numeroProtocolo = ?', [$numero])) {
        flash('err', 'Este atendimento já foi avaliado.');
    } else {
        db_exec('INSERT INTO avaliacao (numeroProtocolo, nota, comentario) VALUES (?,?,?)',
                [$numero, $nota, $coment !== '' ? $coment : null]);
        $titulo = 'Nova avaliação: ' . str_repeat('★', $nota) . str_repeat('☆', 5 - $nota);
        $msg    = primeiro_nome($u['nome']) . ' avaliou o atendimento ' . proto_num($numero) . ' (' . $p['placa'] . ')'
                . ($coment !== '' ? ': "' . mb_strimwidth($coment, 0, 120, '…') . '"' : '.');
        $link   = 'colaborador/atendimento.php?numero=' . $numero;
        notificar_colaborador($p['responsavel'], $titulo, $msg, $link);
        notificar_admins($titulo, $msg, $link, [$p['responsavel']]);
        flash('ok', 'Obrigado pela avaliação!');
    }
    redirecionar($volta);
}

// Veículos com a contagem de atendimentos finalizados.
$veiculos = db_all("SELECT v.id, v.placa, v.ano, mo.nome AS modelo, ma.nome AS marca,
                           (SELECT COUNT(*) FROM protocolo p WHERE p.idVeiculo = v.id AND p.status = 'FINALIZADO') AS atendimentos
                    FROM veiculo v JOIN modelo mo ON mo.id = v.idModelo JOIN marca ma ON ma.id = mo.idMarca
                    WHERE v.idCliente = ? ORDER BY v.placa", [$u['id']]);
$totalAtend = array_sum(array_column($veiculos, 'atendimentos'));

$filtroV = $_GET['v'] ?? '';
if (!in_array($filtroV, array_column($veiculos, 'id'), true)) $filtroV = '';

$params = [$u['id']];
$sql = "SELECT p.numero, p.dataFinalizacao, p.kmEntrada, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
               v.placa, mo.nome AS modelo, col.nome AS colaborador, a.nota, a.comentario
        FROM protocolo p
        JOIN ocorrencia o    ON o.id = p.idOcorrencia
        JOIN veiculo v       ON v.id = p.idVeiculo
        JOIN modelo mo       ON mo.id = v.idModelo
        JOIN colaborador col ON col.id = p.responsavel
        LEFT JOIN avaliacao a ON a.numeroProtocolo = p.numero
        WHERE v.idCliente = ? AND p.status = 'FINALIZADO'";
if ($filtroV !== '') { $sql .= ' AND v.id = ?'; $params[] = $filtroV; }
$protocolos = db_all($sql . ' ORDER BY p.dataFinalizacao DESC, p.numero DESC', $params);

$itens = $fotos = [];
if ($protocolos) {
    $ids  = array_column($protocolos, 'idOcorrencia');
    $nums = array_map('intval', array_column($protocolos, 'numero'));
    foreach (db_all('SELECT idOcorrencia, descricao FROM itemocorrencia
                     WHERE idOcorrencia IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                     ORDER BY dataHora, id', $ids) as $r) $itens[$r['idOcorrencia']][] = $r['descricao'];
    foreach (db_all('SELECT numeroProtocolo, arquivo, legenda FROM foto
                     WHERE numeroProtocolo IN (' . implode(',', array_fill(0, count($nums), '?')) . ')
                     ORDER BY dataHora, id', $nums) as $r) $fotos[$r['numeroProtocolo']][] = $r;
}

// Protocolo aberto (expandido) por padrão: o pedido na URL ou o mais recente.
$expandido = (int)($_GET['numero'] ?? ($protocolos[0]['numero'] ?? 0));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Meu Histórico'); ?>
  <style>
    .timeline-month { display: flex; align-items: center; gap: 14px; padding: 16px 0 10px; }
    .timeline-month-label { font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); white-space: nowrap; }
    .timeline-month-line { flex: 1; height: 1px; background: var(--border); }

    .protocol-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 12px; transition: border-color 0.15s; scroll-margin-top: 70px; }
    .protocol-card:hover { border-color: var(--border-strong); }
    .protocol-card.expanded { border-color: var(--accent); }
    .protocol-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; cursor: pointer; width: 100%; border: none; background: none; text-align: left; }
    .protocol-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .protocol-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--bg); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .protocol-proto { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); margin-bottom: 3px; }
    .protocol-date { font-size: 13.5px; font-weight: 500; }
    .protocol-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .protocol-items { font-size: 12px; color: var(--muted); }
    .chevron { width: 16px; height: 16px; color: var(--muted); transition: transform 0.2s; }
    .protocol-card.expanded .chevron { transform: rotate(180deg); }
    .protocol-body { border-top: 1px solid var(--border); padding: 18px 20px; display: none; }
    .protocol-card.expanded .protocol-body { display: block; }
    .protocol-body > * + * { margin-top: 16px; }
    .protocol-meta-row { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-end; padding-top: 14px; border-top: 1px solid var(--border); font-size: 12px; }
    .pmeta-label { color: var(--muted); }
    .pmeta-value { font-weight: 500; margin-top: 2px; }
    .export-link { margin-left: auto; }

    .rating-card { border: 1px solid var(--border); border-radius: 10px; padding: 16px; background: var(--surface-2); }
    .rating-card .section-label { margin-bottom: 6px; }
    .rating-form { display: flex; flex-direction: column; gap: 10px; }
    .rating-form textarea { width: 100%; min-height: 70px; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 14px; background: var(--surface); color: var(--text); resize: vertical; outline: none; }
    .rating-form textarea:focus { border-color: var(--text); }
    .rating-form .btn-primary { align-self: flex-start; }
    .rating-done .stars { font-size: 18px; }
    .rating-done p { font-size: 13px; line-height: 1.5; margin-top: 6px; }

    @media (max-width: 760px) {
      .protocol-header { padding: 14px 16px; }
      .protocol-body { padding: 16px; }
      .protocol-items, .protocol-right .badge { display: none; }
      .export-link { margin-left: 0; }
      .rating-form textarea { font-size: 16px; }
      .rating-form .btn-primary { align-self: stretch; }
    }
  </style>
</head>
<body>
<?php sidebar('historico'); ?>

<main>
  <div class="page-header">
    <div>
      <div class="page-title">Histórico de Atendimentos</div>
      <div class="page-sub">Todos os protocolos finalizados dos seus veículos</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <?php if ($veiculos): ?>
  <div class="vehicle-tabs">
    <a class="vtab<?= $filtroV === '' ? ' active' : '' ?>" href="historico.php">
      <span class="vtab-plate">Todos</span>
      <span class="vtab-model">Todos os veículos</span>
      <span class="vtab-count"><?= plural($totalAtend, 'atendimento', 'atendimentos') ?></span>
    </a>
    <?php foreach ($veiculos as $v): ?>
    <a class="vtab<?= $filtroV === $v['id'] ? ' active' : '' ?>" href="?v=<?= urlencode($v['id']) ?>">
      <span class="vtab-plate"><?= e($v['placa']) ?></span>
      <span class="vtab-model"><?= e($v['marca'] . ' ' . $v['modelo']) ?> · <?= e($v['ano']) ?></span>
      <span class="vtab-count"><?= plural((int)$v['atendimentos'], 'atendimento', 'atendimentos') ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div>
    <?php if (!$protocolos): ?>
      <div class="empty-state lg">
        <div class="empty-icon">📋</div>
        <div class="empty-title">Nenhum atendimento finalizado</div>
        Quando um atendimento for concluído pela oficina, ele aparecerá aqui.
      </div>
    <?php endif; ?>

    <?php $mesAtual = null; foreach ($protocolos as $p):
      $num   = (int)$p['numero'];
      $mes   = mes_ano($p['dataFinalizacao']);
      $lista = $itens[$p['idOcorrencia']] ?? [];
    ?>
      <?php if ($mes !== $mesAtual): $mesAtual = $mes; ?>
      <div class="timeline-month">
        <div class="timeline-month-label"><?= e($mes) ?></div>
        <div class="timeline-month-line"></div>
      </div>
      <?php endif; ?>

      <div class="protocol-card<?= $num === $expandido ? ' expanded' : '' ?>" id="p<?= $num ?>">
        <button type="button" class="protocol-header" onclick="this.closest('.protocol-card').classList.toggle('expanded')">
          <span class="protocol-left">
            <span class="protocol-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span>
              <span class="protocol-proto" style="display:block"><?= proto_num($num) ?> · <?= e($p['placa']) ?></span>
              <span class="protocol-date"><?= fmt_data_extenso($p['dataFinalizacao']) ?></span>
            </span>
          </span>
          <span class="protocol-right">
            <span class="protocol-items"><?= plural(count($lista), 'item', 'itens') ?></span>
            <?= $p['nota'] ? estrelas((int)$p['nota']) : '<span class="badge wait">Avaliar</span>' ?>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </span>
        </button>
        <div class="protocol-body">
          <div class="item-list">
            <?php foreach ($lista as $n => $desc): ?>
            <div class="item-view">
              <span class="item-num"><?= str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <span class="item-desc" style="font-size:13px"><?= e($desc) ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if (!empty($fotos[$num])): ?>
          <div class="photo-grid">
            <?php foreach ($fotos[$num] as $f): $src = '../' . PASTA_FOTOS . $f['arquivo']; ?>
              <div class="photo"><a href="<?= e($src) ?>" data-lightbox data-caption="<?= e($f['legenda'] ?? '') ?>"><img src="<?= e($src) ?>" alt="<?= e($f['legenda'] ?: 'Foto do atendimento') ?>" loading="lazy"/></a></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="rating-card">
            <?php if ($p['nota']): ?>
              <div class="rating-done">
                <div class="section-label">Sua avaliação</div>
                <?= estrelas((int)$p['nota']) ?>
                <?php if ($p['comentario']): ?><p>“<?= e($p['comentario']) ?>”</p><?php endif; ?>
              </div>
            <?php else: ?>
              <form method="POST" class="rating-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="avaliar"/>
                <input type="hidden" name="numero" value="<?= $num ?>"/>
                <div class="section-label">Como foi este atendimento?</div>
                <div class="star-input" role="radiogroup" aria-label="Nota de 1 a 5">
                  <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" name="nota" id="n<?= $num ?>-<?= $s ?>" value="<?= $s ?>" required/>
                    <label for="n<?= $num ?>-<?= $s ?>" title="<?= plural($s, 'estrela', 'estrelas') ?>">★</label>
                  <?php endfor; ?>
                </div>
                <textarea name="comentario" maxlength="500" placeholder="Conte como foi o serviço (opcional)"></textarea>
                <button type="submit" class="btn-primary btn-sm">Enviar avaliação</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="protocol-meta-row">
            <div><div class="pmeta-label">Colaborador</div><div class="pmeta-value"><?= e($p['colaborador']) ?></div></div>
            <div><div class="pmeta-label">Veículo</div><div class="pmeta-value"><?= e($p['placa'] . ' · ' . $p['modelo']) ?></div></div>
            <div><div class="pmeta-label">Aberto em</div><div class="pmeta-value"><?= fmt_data($p['dataRegistro']) ?></div></div>
            <?php if ($p['kmEntrada']): ?><div><div class="pmeta-label">KM</div><div class="pmeta-value"><?= fmt_km($p['kmEntrada']) ?></div></div><?php endif; ?>
            <a class="act-btn export-link" href="../comum/protocolo.php?numero=<?= $num ?>" target="_blank">
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
