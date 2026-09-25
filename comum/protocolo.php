<?php
// Versão para impressão de um protocolo. O navegador abre a caixa de impressão,
// onde o usuário escolhe "Salvar como PDF".
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE', 'COLABORADOR', 'ADMIN']);

$numero = (int)($_GET['numero'] ?? 0);
$p = db_one("SELECT p.numero, p.status, p.dataFinalizacao, o.id AS idOcorrencia, o.descricao, o.componentes, o.dataRegistro,
                    v.placa, v.ano, v.cor, v.idCliente, mo.nome AS modelo, ma.nome AS marca,
                    c.nome AS cliente, c.cpf, c.telefone, c.email,
                    col.nome AS colaborador, col.cargo
             FROM protocolo p
             JOIN ocorrencia o    ON o.id = p.idOcorrencia
             JOIN veiculo v       ON v.id = p.idVeiculo
             JOIN modelo mo       ON mo.id = v.idModelo
             JOIN marca ma        ON ma.id = mo.idMarca
             JOIN cliente c       ON c.id = v.idCliente
             JOIN colaborador col ON col.id = p.responsavel
             WHERE p.numero = ?", [$numero]);

// Cliente só pode ver protocolos dos próprios veículos.
if (!$p || ($u['perfil'] === 'CLIENTE' && $p['idCliente'] !== $u['id'])) {
    http_response_code(404);
    die('Protocolo não encontrado.');
}
$itens = db_all('SELECT i.descricao, i.dataHora, c.nome AS colaborador
                 FROM itemocorrencia i JOIN colaborador c ON c.id = i.idColaborador
                 WHERE i.idOcorrencia = ? ORDER BY i.dataHora, i.id', [$p['idOcorrencia']]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <title>Protocolo <?= proto_num($p['numero']) ?> – IMM</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; color: #1A1917; background: #F5F4F2; padding: 40px 16px; }
    .sheet { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #E4E2DE; border-radius: 12px; padding: 40px 44px; }
    header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1A1917; padding-bottom: 18px; margin-bottom: 24px; }
    .mark { font-family: 'DM Mono', monospace; font-size: 12px; letter-spacing: .12em; color: #7A7872; }
    .brand { font-size: 17px; font-weight: 600; line-height: 1.3; }
    .proto { text-align: right; }
    .proto-num { font-family: 'DM Mono', monospace; font-size: 22px; font-weight: 500; }
    .proto-status { font-size: 12px; color: #7A7872; margin-top: 4px; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #7A7872; margin: 22px 0 10px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; font-size: 13.5px; }
    .grid span { color: #7A7872; display: inline-block; min-width: 90px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; font-size: 11px; color: #7A7872; text-transform: uppercase; letter-spacing: .06em; padding: 8px 6px; border-bottom: 1px solid #E4E2DE; }
    td { padding: 10px 6px; border-bottom: 1px solid #E4E2DE; vertical-align: top; }
    td.mono { font-family: 'DM Mono', monospace; font-size: 12px; color: #7A7872; white-space: nowrap; }
    .resumo { font-size: 13.5px; line-height: 1.6; }
    footer { margin-top: 36px; font-size: 11px; color: #7A7872; text-align: center; }
    .toolbar { max-width: 760px; margin: 0 auto 14px; text-align: right; }
    .toolbar button { padding: 8px 16px; border: none; border-radius: 8px; background: #1A1917; color: #fff; font-family: inherit; cursor: pointer; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; padding: 0; }
      .toolbar { display: none; }
    }
  </style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Imprimir / Salvar PDF</button></div>
  <div class="sheet">
    <header>
      <div>
        <div class="mark">IMM</div>
        <div class="brand">Integrated Mechanical Management</div>
      </div>
      <div class="proto">
        <div class="proto-num"><?= proto_num($p['numero']) ?></div>
        <div class="proto-status"><?= e(status_protocolo($p['status'])[1]) ?><?= $p['dataFinalizacao'] ? ' em ' . fmt_data($p['dataFinalizacao']) : '' ?></div>
      </div>
    </header>

    <h2>Cliente</h2>
    <div class="grid">
      <div><span>Nome</span><?= e($p['cliente']) ?></div>
      <div><span>CPF</span><?= e(fmt_cpf($p['cpf'])) ?></div>
      <div><span>Telefone</span><?= e(fmt_tel($p['telefone'])) ?></div>
      <div><span>E-mail</span><?= e($p['email']) ?></div>
    </div>

    <h2>Veículo</h2>
    <div class="grid">
      <div><span>Placa</span><?= e($p['placa']) ?></div>
      <div><span>Modelo</span><?= e($p['marca'] . ' ' . $p['modelo']) ?></div>
      <div><span>Ano</span><?= e($p['ano']) ?></div>
      <div><span>Cor</span><?= e($p['cor']) ?></div>
    </div>

    <h2>Atendimento</h2>
    <div class="grid">
      <div><span>Responsável</span><?= e($p['colaborador']) ?> (<?= e($p['cargo']) ?>)</div>
      <div><span>Aberto em</span><?= fmt_data_hora($p['dataRegistro']) ?></div>
      <div><span>Componente</span><?= e($p['componentes'] ?: '—') ?></div>
    </div>
    <p class="resumo" style="margin-top:10px"><?= e($p['descricao']) ?></p>

    <h2>Itens da ocorrência</h2>
    <table>
      <thead><tr><th>#</th><th>Descrição</th><th>Colaborador</th><th>Data</th></tr></thead>
      <tbody>
        <?php foreach ($itens as $i => $it): ?>
        <tr>
          <td class="mono"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
          <td><?= e($it['descricao']) ?></td>
          <td><?= e($it['colaborador']) ?></td>
          <td class="mono"><?= fmt_data_hora($it['dataHora']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <footer>Documento gerado em <?= date('d/m/Y H:i') ?> · IMM – Integrated Mechanical Management</footer>
  </div>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
