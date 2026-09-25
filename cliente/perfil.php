<?php
require __DIR__ . '/../includes/app.php';
$u = exigir_login(['CLIENTE']);

// ─── DADOS PESSOAIS ──────────────────────────────────────────────────────────
if (acao() === 'dados') {
    $nome     = post('nome');
    $email    = post('email');
    $telefone = so_digitos(post('telefone'));
    $endereco = post('endereco');

    if (!$nome || !$email || !$endereco) {
        flash('err', 'Preencha nome, e-mail e endereço.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('err', 'E-mail inválido.');
    } elseif (db_val('SELECT 1 FROM colaborador WHERE email = ?', [$email])) {
        flash('err', 'Já existe um cadastro com este e-mail.');
    } else {
        try {
            db_exec('UPDATE cliente SET nome=?, email=?, telefone=?, endereco=? WHERE id=?',
                    [$nome, $email, $telefone, $endereco, $u['id']]);
            $_SESSION['user']['nome']  = $nome;
            $_SESSION['user']['email'] = $email;
            flash('ok', 'Dados atualizados com sucesso!');
        } catch (mysqli_sql_exception $ex) {
            flash('err', erro_db($ex));
        }
    }
    redirecionar('perfil.php');
}

// ─── ALTERAR SENHA ───────────────────────────────────────────────────────────
if (acao() === 'senha') {
    $atual = $_POST['atual'] ?? '';
    $nova  = $_POST['nova'] ?? '';
    $hash  = db_val('SELECT senha FROM cliente WHERE id = ?', [$u['id']]);

    if (!password_verify($atual, $hash)) {
        flash('err', 'Senha atual incorreta.');
    } elseif (strlen($nova) < 8) {
        flash('err', 'A nova senha deve ter ao menos 8 caracteres.');
    } elseif ($nova !== ($_POST['confirma'] ?? '')) {
        flash('err', 'A confirmação não confere com a nova senha.');
    } else {
        db_exec('UPDATE cliente SET senha=? WHERE id=?', [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        flash('ok', 'Senha atualizada com sucesso!');
    }
    redirecionar('perfil.php');
}

// ─── EXCLUIR CONTA (LGPD) ────────────────────────────────────────────────────
// Remove o cliente; veículos, ocorrências e protocolos saem junto (ON DELETE CASCADE).
if (acao() === 'excluir_conta') {
    if (db_val("SELECT 1 FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                WHERE v.idCliente = ? AND p.status IN ('ABERTO','EM_ATENDIMENTO')", [$u['id']])) {
        flash('err', 'Você possui atendimentos em andamento. Aguarde a finalização para excluir a conta.');
        redirecionar('perfil.php');
    }
    db_exec('DELETE FROM cliente WHERE id = ?', [$u['id']]);
    $_SESSION = [];
    session_destroy();
    redirecionar('../login.php');
}

$cliente      = db_one('SELECT nome, cpf, email, telefone, endereco FROM cliente WHERE id = ?', [$u['id']]);
$nVeiculos    = (int)db_val('SELECT COUNT(*) FROM veiculo WHERE idCliente = ?', [$u['id']]);
$nAtendimentos = (int)db_val("SELECT COUNT(*) FROM protocolo p JOIN veiculo v ON v.id = p.idVeiculo
                              WHERE v.idCliente = ? AND p.status = 'FINALIZADO'", [$u['id']]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Meu Perfil</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #F5F4F2;
      --surface:   #FFFFFF;
      --border:    #E4E2DE;
      --text:      #1A1917;
      --muted:     #7A7872;
      --accent:    #1A1917;
      --tag-open:  #E8F2EC;
      --tag-open-t:#2D7A4F;
      --tag-prog:  #FEF4E4;
      --tag-prog-t:#A05A00;
      --tag-done:  #EDECEA;
      --tag-done-t:#5A5750;
      --danger:    #C0392B;
      --danger-bg: #FEF0EF;
      --sidebar-w: 230px;
    }

    body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

    aside {
      width: var(--sidebar-w); min-height: 100vh; background: var(--surface);
      border-right: 1px solid var(--border); display: flex; flex-direction: column;
      padding: 28px 0; position: fixed; top: 0; left: 0;
    }
    .logo { padding: 0 24px 28px; border-bottom: 1px solid var(--border); }
    .logo-mark { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; letter-spacing: 0.08em; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
    .logo-name { font-size: 15px; font-weight: 600; color: var(--text); line-height: 1.3; }
    nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 2px; }
    .nav-label { font-size: 10px; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); padding: 14px 12px 6px; }
    .nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; font-size: 14px; font-weight: 400; color: var(--muted); cursor: pointer; text-decoration: none; transition: background 0.15s, color 0.15s; }
    .nav-item:hover { background: var(--bg); color: var(--text); }
    .nav-item.active { background: var(--text); color: #fff; }
    .nav-item .icon { width: 18px; height: 18px; opacity: 0.7; flex-shrink: 0; }
    .nav-item.active .icon { opacity: 1; }
    .sidebar-footer { padding: 20px 24px 0; border-top: 1px solid var(--border); }
    .user-chip { display: flex; align-items: center; gap: 10px; }
    .avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: var(--text); color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .user-info { min-width: 0; }
    .user-name { font-size: 13px; font-weight: 500; }
    .user-role { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }

    main { margin-left: var(--sidebar-w); flex: 1; padding: 40px 48px; max-width: calc(100% - var(--sidebar-w)); }

    .topbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-bottom: 32px; }
    .notif-btn { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }
    .notif-dot { width: 7px; height: 7px; background: #C0392B; border-radius: 50%; position: absolute; top: 7px; right: 7px; border: 1.5px solid white; }

    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 32px; }
    .page-title { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .page-date { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }

    /* layout */
    .profile-layout {
      display: grid;
      grid-template-columns: 260px 1fr;
      gap: 20px;
      align-items: start;
    }

    /* left: identity card */
    .identity-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      position: sticky;
      top: 24px;
    }
    .identity-banner {
      height: 80px;
      background: var(--text);
    }
    .identity-body { padding: 0 22px 22px; }
    .avatar-wrap {
      margin-top: -28px;
      margin-bottom: 14px;
    }
    .avatar-lg {
      width: 56px; height: 56px;
      border-radius: 50%;
      background: var(--surface);
      border: 3px solid var(--surface);
      color: var(--text);
      font-size: 20px;
      font-weight: 600;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 0 0 2px var(--border);
    }
    .identity-name { font-size: 16px; font-weight: 600; margin-bottom: 3px; }
    .identity-role {
      font-size: 11px;
      font-family: 'DM Mono', monospace;
      color: var(--muted);
      margin-bottom: 16px;
    }

    .identity-stat-row {
      display: flex;
      gap: 0;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 16px;
    }
    .identity-stat {
      flex: 1;
      padding: 10px 12px;
      text-align: center;
      border-right: 1px solid var(--border);
    }
    .identity-stat:last-child { border-right: none; }
    .identity-stat-val { font-size: 17px; font-weight: 600; margin-bottom: 2px; }
    .identity-stat-label { font-size: 10.5px; color: var(--muted); }

    .identity-links { display: flex; flex-direction: column; gap: 4px; }
    .identity-link {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 9px 12px;
      border-radius: 8px;
      font-size: 13px;
      color: var(--text);
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }
    .identity-link:hover { background: var(--bg); }
    .identity-link.danger { color: var(--danger); }
    .identity-link.danger:hover { background: var(--danger-bg); }
    .identity-link-left { display: flex; align-items: center; gap: 8px; }

    /* right: sections */
    .sections { display: flex; flex-direction: column; gap: 16px; }

    .section-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
    }
    .section-header {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .section-title { font-size: 14px; font-weight: 600; }
    .section-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

    .edit-btn {
      display: flex; align-items: center; gap: 5px;
      padding: 7px 12px;
      border: 1px solid var(--border);
      border-radius: 7px;
      font-size: 12.5px;
      font-weight: 500;
      font-family: inherit;
      background: transparent;
      color: var(--text);
      cursor: pointer;
      transition: background 0.15s;
    }
    .edit-btn:hover { background: var(--bg); }

    .section-body { padding: 20px 22px; }

    /* form fields */
    .fields-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .field-group { display: flex; flex-direction: column; gap: 6px; }
    .field-group.full { grid-column: 1 / -1; }
    .field-label {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--muted);
      letter-spacing: 0.02em;
    }
    .field-value {
      font-size: 14px;
      color: var(--text);
      padding: 9px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--bg);
    }
    .field-input {
      font-size: 14px;
      color: var(--text);
      padding: 9px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--surface);
      font-family: inherit;
      outline: none;
      width: 100%;
      transition: border-color 0.15s;
    }
    .field-input:focus { border-color: var(--text); }
    .field-input::placeholder { color: var(--muted); }
    .field-input.mono { font-family: 'DM Mono', monospace; font-size: 13px; }

    .section-footer {
      padding: 14px 22px;
      border-top: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
    }
    .btn-primary {
      padding: 9px 18px;
      background: var(--text);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 500;
      font-family: inherit;
      cursor: pointer;
      transition: opacity 0.15s;
    }
    .btn-primary:hover { opacity: 0.85; }
    .btn-ghost {
      padding: 9px 14px;
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 13px;
      font-weight: 500;
      font-family: inherit;
      cursor: pointer;
      transition: background 0.15s;
    }
    .btn-ghost:hover { background: var(--bg); color: var(--text); }

    /* password field */
    .password-field-wrap { position: relative; }
    .password-toggle {
      position: absolute;
      right: 12px; top: 50%;
      transform: translateY(-50%);
      color: var(--muted);
      cursor: pointer;
      background: none; border: none;
      display: flex; align-items: center;
    }

    /* danger zone */
    .danger-zone-body { padding: 20px 22px; }
    .danger-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 0;
      border-bottom: 1px solid var(--border);
    }
    .danger-item:last-child { border-bottom: none; }
    .danger-item-text { font-size: 13.5px; font-weight: 500; margin-bottom: 3px; }
    .danger-item-sub { font-size: 12px; color: var(--muted); }
    .btn-danger {
      padding: 8px 14px;
      background: transparent;
      color: var(--danger);
      border: 1px solid #F5C6C2;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 500;
      font-family: inherit;
      cursor: pointer;
      transition: background 0.15s;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .btn-danger:hover { background: var(--danger-bg); }

    .badge { display: inline-flex; align-items: center; font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .badge.verified { background: var(--tag-open); color: var(--tag-open-t); }

    @media (max-width: 1100px) {
      .profile-layout { grid-template-columns: 1fr; }
      .identity-card { position: static; }
      .fields-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<style>
  .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--text); color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  a.btn-danger { text-decoration: none; }
  button.identity-link { width: 100%; border: none; background: none; font-family: inherit; cursor: pointer; text-align: left; }
</style>

<?php sidebar('perfil'); ?>

<!-- MAIN -->
<main>
  <div class="page-header">
    <div>
      <div class="page-title">Meu Perfil</div>
      <div class="page-sub">Gerencie seus dados pessoais e preferências de conta</div>
    </div>
    <div class="page-date"><?= data_hoje() ?></div>
  </div>

  <div class="profile-layout">

    <!-- identity card -->
    <div class="identity-card">
      <div class="identity-banner"></div>
      <div class="identity-body">
        <div class="avatar-wrap">
          <div class="avatar-lg"><?= e(initials($cliente['nome'])) ?></div>
        </div>
        <div class="identity-name"><?= e($cliente['nome']) ?></div>
        <div class="identity-role">Cliente</div>

        <div class="identity-stat-row">
          <div class="identity-stat">
            <div class="identity-stat-val"><?= $nVeiculos ?></div>
            <div class="identity-stat-label">Veículos</div>
          </div>
          <div class="identity-stat">
            <div class="identity-stat-val"><?= $nAtendimentos ?></div>
            <div class="identity-stat-label">Atendimentos</div>
          </div>
        </div>

        <div class="identity-links">
          <a class="identity-link" href="meus_veiculos.php">
            <div class="identity-link-left">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-4 0v2M8 7V5a2 2 0 00-4 0v2"/><circle cx="12" cy="14" r="2"/></svg>
              Meus Veículos
            </div>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </a>
          <a class="identity-link" href="historico.php">
            <div class="identity-link-left">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Histórico
            </div>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </a>
          <a class="identity-link danger" href="../logout.php">
            <div class="identity-link-left">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
              Sair da conta
            </div>
          </a>
        </div>
      </div>
    </div>

    <!-- right sections -->
    <div class="sections">

      <!-- dados pessoais -->
      <form class="section-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="dados"/>
        <div class="section-header">
          <div>
            <div class="section-title">Dados Pessoais</div>
            <div class="section-sub">Informações do seu cadastro</div>
          </div>
          <button type="button" class="edit-btn" onclick="toggleEdit('dados')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Editar
          </button>
        </div>
        <div class="section-body">
          <!-- view mode -->
          <div id="dados-view" class="fields-grid">
            <div class="field-group">
              <div class="field-label">Nome completo</div>
              <div class="field-value"><?= e($cliente['nome']) ?></div>
            </div>
            <div class="field-group">
              <div class="field-label">CPF</div>
              <div class="field-value" style="font-family:'DM Mono',monospace;font-size:13px"><?= e(fmt_cpf($cliente['cpf'])) ?></div>
            </div>
            <div class="field-group">
              <div class="field-label">E-mail</div>
              <div class="field-value"><?= e($cliente['email']) ?></div>
            </div>
            <div class="field-group">
              <div class="field-label">Telefone</div>
              <div class="field-value"><?= e(fmt_tel($cliente['telefone'])) ?></div>
            </div>
            <div class="field-group full">
              <div class="field-label">Endereço</div>
              <div class="field-value"><?= e($cliente['endereco']) ?></div>
            </div>
          </div>
          <!-- edit mode (hidden) -->
          <div id="dados-edit" class="fields-grid" style="display:none">
            <div class="field-group">
              <div class="field-label">Nome completo</div>
              <input class="field-input" type="text" name="nome" value="<?= e($cliente['nome']) ?>" maxlength="100" required/>
            </div>
            <div class="field-group">
              <div class="field-label">CPF <span style="color:var(--muted);font-size:10.5px;font-weight:400">(não editável)</span></div>
              <input class="field-input mono" type="text" value="<?= e(fmt_cpf($cliente['cpf'])) ?>" disabled style="opacity:0.5;cursor:not-allowed"/>
            </div>
            <div class="field-group">
              <div class="field-label">E-mail</div>
              <input class="field-input" type="email" name="email" value="<?= e($cliente['email']) ?>" maxlength="100" required/>
            </div>
            <div class="field-group">
              <div class="field-label">Telefone</div>
              <input class="field-input" type="tel" name="telefone" id="f-tel" value="<?= e(fmt_tel($cliente['telefone']) === '—' ? '' : fmt_tel($cliente['telefone'])) ?>" maxlength="15"/>
            </div>
            <div class="field-group full">
              <div class="field-label">Endereço</div>
              <input class="field-input" type="text" name="endereco" value="<?= e($cliente['endereco']) ?>" maxlength="100" required/>
            </div>
          </div>
        </div>
        <div id="dados-footer" class="section-footer" style="display:none">
          <button type="button" class="btn-ghost" onclick="cancelEdit('dados')">Cancelar</button>
          <button type="submit" class="btn-primary">Salvar alterações</button>
        </div>
      </form>

      <!-- segurança -->
      <form class="section-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="senha"/>
        <div class="section-header">
          <div>
            <div class="section-title">Segurança</div>
            <div class="section-sub">Senha e acesso à conta</div>
          </div>
          <button type="button" class="edit-btn" onclick="toggleEdit('senha')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Alterar senha
          </button>
        </div>
        <div class="section-body">
          <div id="senha-view" class="fields-grid">
            <div class="field-group">
              <div class="field-label">Senha atual</div>
              <div class="field-value" style="letter-spacing:0.15em;color:var(--muted)">••••••••••</div>
            </div>
            <div class="field-group">
              <div class="field-label">Acesso</div>
              <div class="field-value"><?= e($cliente['email']) ?></div>
            </div>
          </div>
          <div id="senha-edit" class="fields-grid" style="display:none">
            <div class="field-group full">
              <div class="field-label">Senha atual</div>
              <div class="password-field-wrap">
                <input class="field-input" type="password" name="atual" placeholder="Digite sua senha atual"/>
                <button class="password-toggle" type="button" onclick="togglePass(this)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="field-group">
              <div class="field-label">Nova senha</div>
              <div class="password-field-wrap">
                <input class="field-input" type="password" name="nova" placeholder="Mínimo 8 caracteres" minlength="8"/>
                <button class="password-toggle" type="button" onclick="togglePass(this)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="field-group">
              <div class="field-label">Confirmar nova senha</div>
              <div class="password-field-wrap">
                <input class="field-input" type="password" name="confirma" placeholder="Repita a nova senha" minlength="8"/>
                <button class="password-toggle" type="button" onclick="togglePass(this)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div id="senha-footer" class="section-footer" style="display:none">
          <button type="button" class="btn-ghost" onclick="cancelEdit('senha')">Cancelar</button>
          <button type="submit" class="btn-primary">Atualizar senha</button>
        </div>
      </form>

      <!-- danger zone -->
      <div class="section-card">
        <div class="section-header">
          <div>
            <div class="section-title" style="color:var(--danger)">Zona de Perigo</div>
            <div class="section-sub">Ações irreversíveis para sua conta</div>
          </div>
        </div>
        <div class="danger-zone-body">
          <div class="danger-item">
            <div>
              <div class="danger-item-text">Excluir um veículo</div>
              <div class="danger-item-sub">Remove permanentemente o veículo e seu histórico de ocorrências</div>
            </div>
            <a class="btn-danger" href="meus_veiculos.php">Gerenciar veículos</a>
          </div>
          <div class="danger-item">
            <div>
              <div class="danger-item-text">Excluir minha conta</div>
              <div class="danger-item-sub">Remove permanentemente seus dados pessoais do sistema (LGPD)</div>
            </div>
            <form method="POST" onsubmit="return confirm('Excluir sua conta permanentemente? Seus veículos e histórico também serão apagados. Esta ação não pode ser desfeita.')">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="excluir_conta"/>
              <button type="submit" class="btn-danger">Excluir conta</button>
            </form>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<?php flash_html(); ?>

<script>
  function toggleEdit(section) {
    document.getElementById(section + '-view').style.display = 'none';
    document.getElementById(section + '-edit').style.display = 'grid';
    document.getElementById(section + '-footer').style.display = 'flex';
    if (section === 'senha') document.querySelectorAll('#senha-edit input').forEach(i => i.required = true);
  }
  function cancelEdit(section) {
    document.getElementById(section + '-view').style.display = 'grid';
    document.getElementById(section + '-edit').style.display = 'none';
    document.getElementById(section + '-footer').style.display = 'none';
    if (section === 'senha') document.querySelectorAll('#senha-edit input').forEach(i => { i.required = false; i.value = ''; });
  }
  function togglePass(btn) {
    const input = btn.parentElement.querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
  }
  document.getElementById('f-tel').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0, 11);
    if (v.length > 10)     v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    else if (v.length > 6) v = v.replace(/(\d{2})(\d{4})(\d+)/, '($1) $2-$3');
    else if (v.length > 2) v = v.replace(/(\d{2})(\d+)/, '($1) $2');
    this.value = v;
  });
</script>
</body>
</html>
