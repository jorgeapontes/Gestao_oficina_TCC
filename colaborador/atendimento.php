<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['COLABORADOR', 'ADMIN']);

$numero = (int)($_GET['numero'] ?? $_POST['numero'] ?? 0);

function carregar_protocolo(int $numero): ?array {
    return db_one("SELECT p.numero, p.status, p.dataFinalizacao, p.responsavel, p.kmEntrada, p.previsaoEntrega,
                          o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                          v.placa, v.ano, v.cor, v.idCliente, mo.nome AS modelo, ma.nome AS marca,
                          c.nome AS cliente, c.telefone, c.email AS clienteEmail,
                          col.nome AS responsavelNome, col.cargo AS responsavelCargo,
                          a.nota, a.comentario, a.dataHora AS dataAvaliacao
                   FROM protocolo p
                   JOIN ocorrencia o    ON o.id = p.idOcorrencia
                   JOIN veiculo v       ON v.id = p.idVeiculo
                   JOIN modelo mo       ON mo.id = v.idModelo
                   JOIN marca ma        ON ma.id = mo.idMarca
                   JOIN cliente c       ON c.id = v.idCliente
                   JOIN colaborador col ON col.id = p.responsavel
                   LEFT JOIN avaliacao a ON a.numeroProtocolo = p.numero
                   WHERE p.numero = ?", [$numero]);
}

$p = carregar_protocolo($numero);
if (!$p) {
    flash('err', 'Atendimento não encontrado.');
    redirecionar('dashboard.php');
}
$ativo = in_array($p['status'], STATUS_ATIVOS, true);
$self  = 'atendimento.php?numero=' . $numero;

// Etapas intermediárias que o colaborador pode escolher (além de finalizar/cancelar).
const ETAPAS_INTERMEDIARIAS = ['AGUARDANDO_APROVACAO', 'EM_ATENDIMENTO', 'AGUARDANDO_PECAS', 'PRONTO'];

// ─── AÇÕES ───────────────────────────────────────────────────────────────────
// Protocolos finalizados/cancelados são imutáveis: nenhuma ação é aceita.
if (is_post() && !$ativo) {
    flash('err', 'Este atendimento já foi encerrado e não pode ser alterado.');
    redirecionar($self);
}

if (acao() === 'item') {
    $desc = post('descricao');
    if ($desc === '') {
        flash('err', 'Descreva o item antes de adicionar.');
    } else {
        db_exec('INSERT INTO itemocorrencia (id, descricao, idOcorrencia, idColaborador) VALUES (?,?,?,?)',
                [uuid(), mb_substr($desc, 0, 200), $p['idOcorrencia'], $u['id']]);
        flash('ok', 'Item adicionado à ocorrência.');
    }
    redirecionar($self);
}

if (acao() === 'status') {
    $novo = post('status');
    $permitidos = array_merge(array_diff(ETAPAS_INTERMEDIARIAS, [$p['status']]), ['FINALIZADO', 'CANCELADO']);
    if (!in_array($novo, $permitidos, true)) {
        flash('err', 'Mudança de status inválida.');
    } else {
        // Quem inicia o serviço passa a ser o responsável por ele.
        if ($novo === 'EM_ATENDIMENTO' && $p['responsavel'] !== $u['id']) {
            db_exec('UPDATE protocolo SET responsavel = ? WHERE numero = ?', [$u['id'], $numero]);
        }
        mudar_status_protocolo($numero, $novo, $u['id']);
        flash('ok', match ($novo) {
            'FINALIZADO' => 'Atendimento finalizado. O protocolo foi para o histórico.',
            'CANCELADO'  => 'Atendimento cancelado.',
            default      => 'Status alterado para "' . status_protocolo($novo)[1] . '". O cliente foi notificado.',
        });
    }
    redirecionar($self);
}

if (acao() === 'dados') {
    $km       = so_digitos(post('kmEntrada'));
    $previsao = post('previsaoEntrega');
    if ($previsao !== '' && !DateTime::createFromFormat('Y-m-d', $previsao)) {
        flash('err', 'Data de previsão inválida.');
        redirecionar($self);
    }
    $previsao = $previsao !== '' ? $previsao : null;
    db_exec('UPDATE protocolo SET kmEntrada = ?, previsaoEntrega = ? WHERE numero = ?',
            [$km !== '' ? (int)$km : null, $previsao, $numero]);
    if ($previsao && $previsao !== $p['previsaoEntrega']) {
        notificar_cliente($p['idCliente'], $p['placa'] . ' · Previsão de entrega',
                          'A previsão de entrega do seu veículo é ' . fmt_data($previsao) . '.',
                          'cliente/visualizar_ocorrencias.php?numero=' . $numero);
    }
    flash('ok', 'Dados do atendimento atualizados.');
    redirecionar($self);
}

if (acao() === 'foto') {
    $legenda = mb_substr(post('legenda'), 0, 150);
    $arquivos = $_FILES['fotos'] ?? null;
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $salvas = 0;
    $erros  = [];
    $pasta  = __DIR__ . '/../' . PASTA_FOTOS;
    if (!is_dir($pasta)) mkdir($pasta, 0775, true);

    foreach ((array)($arquivos['name'] ?? []) as $i => $nome) {
        $erro = $arquivos['error'][$i];
        if ($erro === UPLOAD_ERR_NO_FILE) continue;
        if ($erro !== UPLOAD_ERR_OK || $arquivos['size'][$i] > TAMANHO_MAX_FOTO) {
            $erros[] = "$nome: arquivo muito grande ou envio interrompido";
            continue;
        }
        $info = @getimagesize($arquivos['tmp_name'][$i]);
        if (!$info || !isset($tipos[$info[2]])) {
            $erros[] = "$nome: formato não suportado (use JPG, PNG, WEBP ou GIF)";
            continue;
        }
        $arquivo = uuid() . '.' . $tipos[$info[2]];
        if (move_uploaded_file($arquivos['tmp_name'][$i], $pasta . $arquivo)) {
            db_exec('INSERT INTO foto (id, numeroProtocolo, arquivo, legenda, idColaborador) VALUES (?,?,?,?,?)',
                    [uuid(), $numero, $arquivo, $legenda !== '' ? $legenda : null, $u['id']]);
            $salvas++;
        }
    }
    if ($salvas) {
        notificar_cliente($p['idCliente'], $p['placa'] . ' · Novas fotos',
                          plural($salvas, 'foto foi adicionada', 'fotos foram adicionadas') . ' ao atendimento.',
                          'cliente/visualizar_ocorrencias.php?numero=' . $numero);
    }
    if ($erros) flash('err', ($salvas ? plural($salvas, 'foto enviada', 'fotos enviadas') . '. ' : '') . implode('; ', $erros) . '.');
    elseif ($salvas) flash('ok', plural($salvas, 'foto adicionada', 'fotos adicionadas') . ' ao atendimento.');
    else flash('err', 'Selecione ao menos uma foto.');
    redirecionar($self . '#fotos');
}

if (acao() === 'remover_foto') {
    $foto = db_one('SELECT id, arquivo FROM foto WHERE id = ? AND numeroProtocolo = ?', [post('id'), $numero]);
    if ($foto) {
        db_exec('DELETE FROM foto WHERE id = ?', [$foto['id']]);
        @unlink(__DIR__ . '/../' . PASTA_FOTOS . $foto['arquivo']);
        flash('ok', 'Foto removida.');
    }
    redirecionar($self . '#fotos');
}

$itens = db_all('SELECT i.descricao, i.dataHora, c.nome AS colaborador
                 FROM itemocorrencia i JOIN colaborador c ON c.id = i.idColaborador
                 WHERE i.idOcorrencia = ? ORDER BY i.dataHora, i.id', [$p['idOcorrencia']]);
$etapas = etapas_protocolo($numero);
$fotos  = fotos_protocolo($numero);
$late   = atrasado($p['previsaoEntrega'], $p['status']);
$tel    = so_digitos($p['telefone']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php head_comum('Atendimento ' . proto_num($p['numero'])); ?>
  <style>
    .status-picker { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .status-option {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 12px;
      border: 1px solid var(--border); border-radius: 9px;
      background: var(--surface);
      font-size: 13px; font-weight: 500; text-align: left;
      cursor: pointer;
      transition: border-color .15s, background .15s;
    }
    .status-option:hover { border-color: var(--text); }
    .status-option.current { border-color: var(--accent); background: var(--bg); cursor: default; }
    .status-option .dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .dot.wait { background: var(--tag-wait-t); } .dot.prog { background: var(--tag-prog-t); }
    .dot.ready { background: var(--tag-ready-t); }
    .closing-actions { display: flex; gap: 10px; justify-content: space-between; flex-wrap: wrap; }
    .closed-note { padding: 14px 22px; font-size: 13px; color: var(--muted); border-top: 1px solid var(--border); line-height: 1.6; }
    .rating-box { padding: 18px 22px; }
    .rating-box .stars { font-size: 20px; }
    .rating-comment { font-size: 13px; line-height: 1.55; margin-top: 10px; color: var(--text); }
    .contact-links { display: flex; gap: 8px; padding: 0 22px 18px; flex-wrap: wrap; }
    @media (max-width: 760px) {
      .status-picker { grid-template-columns: 1fr; }
      .closing-actions > * { flex: 1; }
      .closing-actions form, .closing-actions button { width: 100%; }
      .contact-links { padding: 0 16px 16px; }
    }
  </style>
</head>
<body>
<?php sidebar('ocorrencias'); ?>

<main>
  <div class="topbar">
    <form class="search-wrap" method="GET" action="veiculos.php">
      <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input class="search-input" type="text" name="q" placeholder="Buscar cliente, veículo, placa..."/>
    </form>
  </div>

  <div class="page-header">
    <div>
      <div class="proto-code">Protocolo <?= proto_num($p['numero']) ?> · aberto em <?= fmt_data_hora($p['dataRegistro']) ?></div>
      <div class="page-title">Atendimento — <?= e($p['placa']) ?></div>
      <div class="page-sub"><?= e($p['marca'] . ' ' . $p['modelo']) ?> · <?= e($p['cliente']) ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?= badge(status_protocolo($p['status'])) ?>
      <?php if ($late): ?><span class="badge late">Atrasado</span><?php endif; ?>
    </div>
  </div>

  <div class="two-col side-360">

    <div class="stack">
      <!-- OCORRÊNCIA + ITENS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Ocorrência</div>
          <span class="card-meta"><?= plural(count($itens), 'item', 'itens') ?></span>
        </div>

        <div class="info-grid" style="border-bottom:1px solid var(--border)">
          <div class="info-item"><label>Componente</label><span><?= e($p['componentes'] ?: '—') ?></span></div>
          <div class="info-item"><label>Responsável</label><span><?= e($p['responsavelNome']) ?></span></div>
          <div class="info-item"><label>KM de entrada</label><span><?= fmt_km($p['kmEntrada']) ?></span></div>
          <div class="info-item"><label>Previsão de entrega</label>
            <span<?= $late ? ' class="text-danger"' : '' ?>><?= fmt_data($p['previsaoEntrega']) ?><?= $late ? ' (atrasado)' : '' ?></span></div>
          <div class="info-item full"><label>Resumo</label><span style="font-weight:400"><?= e($p['descricao']) ?></span></div>
        </div>

        <div class="form-body">
          <div class="item-list">
            <?php foreach ($itens as $i => $it): ?>
              <div class="item-view">
                <span class="item-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <div>
                  <div class="item-desc"><?= e($it['descricao']) ?></div>
                  <div class="item-meta"><?= e($it['colaborador']) ?> · <?= fmt_data_hora($it['dataHora']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($ativo): ?>
            <form method="POST" class="field">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="item"/>
              <input type="hidden" name="numero" value="<?= $numero ?>"/>
              <label for="novo-item">Adicionar item</label>
              <div class="inline-form">
                <textarea id="novo-item" name="descricao" rows="2" maxlength="200" style="min-height:44px" placeholder="Novo problema identificado ou serviço realizado..." required></textarea>
                <button type="submit" class="btn-primary">Adicionar</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ANDAMENTO -->
      <?php if ($ativo): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Andamento do serviço</div></div>
        <div class="form-body">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="status"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <div class="section-label">Etapa atual</div>
            <div class="status-picker">
              <?php foreach (ETAPAS_INTERMEDIARIAS as $etapa): $st = status_protocolo($etapa); $atual = $etapa === $p['status']; ?>
                <button type="<?= $atual ? 'button' : 'submit' ?>" name="status" value="<?= $etapa ?>" class="status-option<?= $atual ? ' current' : '' ?>">
                  <span class="dot <?= $st[0] ?>"></span><?= e($st[1]) ?><?= $atual ? ' <span class="text-muted" style="margin-left:auto;font-weight:400">atual</span>' : '' ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="field-hint" style="margin-top:8px">O cliente recebe uma notificação a cada mudança de etapa.</div>
          </form>

          <form method="POST" class="field-row">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="dados"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <div class="field">
              <label for="km">KM de entrada</label>
              <input id="km" type="text" inputmode="numeric" name="kmEntrada" value="<?= e($p['kmEntrada'] ?? '') ?>" placeholder="Ex.: 54000"/>
            </div>
            <div class="field">
              <label for="previsao">Previsão de entrega</label>
              <input id="previsao" type="date" name="previsaoEntrega" value="<?= e($p['previsaoEntrega'] ?? '') ?>"/>
            </div>
            <div class="full" style="display:flex;justify-content:flex-end">
              <button type="submit" class="btn-outline btn-sm">Salvar km e previsão</button>
            </div>
          </form>
        </div>
        <div class="form-actions closing-actions">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="status"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <button type="submit" name="status" value="CANCELADO" class="btn-danger-outline"
                    data-confirm="Cancelar este atendimento? Esta ação não pode ser desfeita." data-confirm-title="Cancelar atendimento"
                    data-confirm-ok="Cancelar atendimento" data-confirm-danger>Cancelar atendimento</button>
          </form>
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="status"/>
            <input type="hidden" name="numero" value="<?= $numero ?>"/>
            <button type="submit" name="status" value="FINALIZADO" class="btn-primary"
                    data-confirm="Finalizar o atendimento? O protocolo ficará imutável no histórico e o cliente poderá avaliá-lo."
                    data-confirm-title="Finalizar atendimento" data-confirm-ok="Finalizar">Finalizar atendimento</button>
          </form>
        </div>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="closed-note" style="border-top:none">
          Atendimento <?= $p['status'] === 'FINALIZADO' ? 'finalizado' : 'cancelado' ?> em <?= fmt_data($p['dataFinalizacao']) ?>.
          Registros encerrados são imutáveis.
          <?php if ($p['status'] === 'FINALIZADO'): ?>
            <a href="../admin/historico.php?numero=<?= $numero ?>" style="color:var(--text);font-weight:500">Ver no histórico →</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- FOTOS -->
      <div class="card" id="fotos">
        <div class="card-header">
          <div class="card-title">Fotos</div>
          <span class="card-meta"><?= plural(count($fotos), 'foto', 'fotos') ?></span>
        </div>
        <div class="form-body">
          <?php if ($fotos): ?>
            <div class="photo-grid">
              <?php foreach ($fotos as $f): $src = '../' . PASTA_FOTOS . $f['arquivo']; ?>
                <div class="photo">
                  <a href="<?= e($src) ?>" data-lightbox data-caption="<?= e(($f['legenda'] ? $f['legenda'] . ' · ' : '') . fmt_data_hora($f['dataHora'])) ?>">
                    <img src="<?= e($src) ?>" alt="<?= e($f['legenda'] ?: 'Foto do atendimento') ?>" loading="lazy"/>
                  </a>
                  <?php if ($f['legenda']): ?><div class="photo-caption"><?= e($f['legenda']) ?></div><?php endif; ?>
                  <?php if ($ativo): ?>
                    <form method="POST" data-confirm="Remover esta foto do atendimento?" data-confirm-ok="Remover" data-confirm-danger>
                      <?= csrf_field() ?>
                      <input type="hidden" name="_action" value="remover_foto"/>
                      <input type="hidden" name="numero" value="<?= $numero ?>"/>
                      <input type="hidden" name="id" value="<?= e($f['id']) ?>"/>
                      <button type="submit" class="photo-remove" title="Remover foto" aria-label="Remover foto">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php elseif (!$ativo): ?>
            <div class="text-muted" style="font-size:13px">Nenhuma foto registrada neste atendimento.</div>
          <?php endif; ?>

          <?php if ($ativo): ?>
            <form method="POST" enctype="multipart/form-data" class="field-row">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="foto"/>
              <input type="hidden" name="numero" value="<?= $numero ?>"/>
              <div class="field">
                <label for="fotos-input">Adicionar fotos</label>
                <input id="fotos-input" type="file" name="fotos[]" accept="image/*" multiple required/>
                <span class="field-hint">JPG, PNG ou WEBP · até 8 MB cada. No celular, dá para tirar a foto na hora.</span>
              </div>
              <div class="field">
                <label for="legenda">Legenda (opcional)</label>
                <input id="legenda" type="text" name="legenda" maxlength="150" placeholder="Ex.: pastilha de freio desgastada"/>
              </div>
              <div class="full" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn-outline btn-sm">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                  Enviar fotos
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-header"><div class="card-title">Linha do tempo</div></div>
        <?php linha_do_tempo($etapas, $p['previsaoEntrega']); ?>
      </div>

      <?php if ($p['nota']): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Avaliação do cliente</div><span class="card-meta"><?= fmt_data($p['dataAvaliacao']) ?></span></div>
        <div class="rating-box">
          <?= estrelas((int)$p['nota']) ?>
          <?php if ($p['comentario']): ?><div class="rating-comment">“<?= e($p['comentario']) ?>”</div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><div class="card-title">Dados do veículo</div></div>
        <div class="kv-list">
          <div class="kv-row"><span class="kv-key">Placa</span><span class="kv-val mono"><?= e($p['placa']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Marca / Modelo</span><span class="kv-val"><?= e($p['marca'] . ' ' . $p['modelo']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Ano / Cor</span><span class="kv-val"><?= e($p['ano'] . ' · ' . $p['cor']) ?></span></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">Proprietário</div></div>
        <div class="kv-list">
          <div class="kv-row"><span class="kv-key">Nome</span><span class="kv-val"><?= e($p['cliente']) ?></span></div>
          <div class="kv-row"><span class="kv-key">Telefone</span><span class="kv-val"><?= e(fmt_tel($p['telefone'])) ?></span></div>
          <div class="kv-row"><span class="kv-key">E-mail</span><span class="kv-val"><?= e($p['clienteEmail']) ?></span></div>
        </div>
        <div class="contact-links">
          <?php if ($tel !== ''): ?><a class="act-btn" href="tel:<?= e($tel) ?>">Ligar</a><?php endif; ?>
          <a class="act-btn" href="mailto:<?= e($p['clienteEmail']) ?>">Enviar e-mail</a>
          <a class="act-btn" href="../comum/protocolo.php?numero=<?= $numero ?>" target="_blank">Imprimir protocolo</a>
        </div>
      </div>
    </div>

  </div>
</main>

<?php flash_html(); ?>
</body>
</html>
