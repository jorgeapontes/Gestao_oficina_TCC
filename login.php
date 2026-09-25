<?php
require __DIR__ . '/includes/app.php';

// Já logado: vai direto para o painel do perfil.
if (usuario()) redirecionar(home_do_perfil(usuario()['perfil']));
verificar_csrf();

$aba      = 'login';
$erroLogin = '';
$erroCad   = '';
$old       = [];

// ─── LOGIN ───────────────────────────────────────────────────────────────────
// Procura primeiro em colaborador (perfil COLABORADOR/ADMIN), depois em cliente.
if (acao() === 'login') {
    $email = post('email');
    $senha = $_POST['senha'] ?? '';
    $old['login_email'] = $email;

    if ($email === '' || $senha === '') {
        $erroLogin = 'Preencha todos os campos.';
    } else {
        $u = db_one('SELECT id, nome, email, senha, cargo, perfil FROM colaborador WHERE email = ?', [$email]);
        if (!$u) {
            $u = db_one("SELECT id, nome, email, senha, '' AS cargo, 'CLIENTE' AS perfil FROM cliente WHERE email = ?", [$email]);
        }
        if ($u && password_verify($senha, $u['senha'])) {
            session_regenerate_id(true);
            unset($u['senha']);
            $_SESSION['user'] = $u;
            redirecionar(home_do_perfil($u['perfil']));
        }
        $erroLogin = 'E-mail ou senha incorretos. Tente novamente.';
    }
}

// ─── CADASTRO DE CLIENTE ─────────────────────────────────────────────────────
if (acao() === 'cadastro') {
    $aba = 'register';
    $old = [
        'nome' => post('nome'), 'cpf' => post('cpf'), 'email' => post('email'),
        'telefone' => post('telefone'), 'endereco' => post('endereco'),
    ];
    $cpf   = so_digitos($old['cpf']);
    $senha = $_POST['senha'] ?? '';

    if (!$old['nome'] || !$cpf || !$old['email'] || !$old['telefone'] || !$old['endereco'] || $senha === '') {
        $erroCad = 'Preencha todos os campos obrigatórios.';
    } elseif (strlen($cpf) !== 11) {
        $erroCad = 'CPF inválido: informe os 11 dígitos.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $erroCad = 'E-mail inválido.';
    } elseif ($senha !== ($_POST['senha2'] ?? '')) {
        $erroCad = 'As senhas não conferem.';
    } elseif (strlen($senha) < 8) {
        $erroCad = 'A senha deve ter ao menos 8 caracteres.';
    } elseif (db_val('SELECT 1 FROM colaborador WHERE email = ?', [$old['email']])) {
        $erroCad = 'Já existe um cadastro com este e-mail.';
    } else {
        try {
            $id = uuid();
            db_exec('INSERT INTO cliente (id, nome, cpf, email, senha, telefone, endereco) VALUES (?,?,?,?,?,?,?)', [
                $id, $old['nome'], $cpf, $old['email'], password_hash($senha, PASSWORD_DEFAULT),
                so_digitos($old['telefone']), $old['endereco'],
            ]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $id, 'nome' => $old['nome'], 'email' => $old['email'], 'cargo' => '', 'perfil' => 'CLIENTE'];
            flash('ok', 'Cadastro realizado com sucesso! Bem-vindo(a).');
            redirecionar('cliente/dashboard.php');
        } catch (mysqli_sql_exception $ex) {
            $erroCad = erro_db($ex);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IMM – Login</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:      #F5F4F2;
      --surface: #FFFFFF;
      --border:  #E4E2DE;
      --text:    #1A1917;
      --muted:   #7A7872;
      --accent:  #1A1917;
      --error:   #C0392B;
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: stretch;
    }

    /* LEFT PANEL */
    .left-panel {
      width: 420px;
      flex-shrink: 0;
      background: var(--text);
      color: #fff;
      display: flex;
      flex-direction: column;
      padding: 52px 48px;
      position: relative;
      overflow: hidden;
    }

    .left-panel::before {
      content: '';
      position: absolute;
      width: 320px; height: 320px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.07);
      bottom: -80px; right: -80px;
    }
    .left-panel::after {
      content: '';
      position: absolute;
      width: 200px; height: 200px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.07);
      bottom: 40px; right: 20px;
    }

    .left-logo {
      margin-bottom: auto;
    }
    .logo-mark {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      font-weight: 500;
      letter-spacing: 0.12em;
      color: rgba(255,255,255,0.45);
      text-transform: uppercase;
      margin-bottom: 8px;
    }
    .logo-name {
      font-size: 18px;
      font-weight: 600;
      line-height: 1.35;
    }

    .left-tagline {
      margin-top: auto;
    }
    .tagline-text {
      font-size: 26px;
      font-weight: 300;
      line-height: 1.4;
      color: rgba(255,255,255,0.9);
      margin-bottom: 24px;
    }
    .tagline-text strong {
      font-weight: 600;
      color: #fff;
    }

    .features-list {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .features-list li {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 13px;
      color: rgba(255,255,255,0.6);
    }
    .feat-dot {
      width: 5px; height: 5px;
      border-radius: 50%;
      background: rgba(255,255,255,0.35);
      flex-shrink: 0;
    }

    /* RIGHT PANEL */
    .right-panel {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 48px;
    }

    .auth-box {
      width: 100%;
      max-width: 400px;
    }

    /* TABS */
    .tabs {
      display: flex;
      gap: 0;
      background: var(--bg);
      border-radius: 10px;
      padding: 4px;
      margin-bottom: 36px;
    }
    .tab-btn {
      flex: 1;
      padding: 9px 16px;
      border: none;
      border-radius: 7px;
      background: transparent;
      font-family: inherit;
      font-size: 13.5px;
      font-weight: 500;
      color: var(--muted);
      cursor: pointer;
      transition: background 0.15s, color 0.15s;
    }
    .tab-btn.active {
      background: var(--surface);
      color: var(--text);
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    /* FORM PANELS */
    .form-panel { display: none; }
    .form-panel.active { display: block; }

    .form-head {
      margin-bottom: 28px;
    }
    .form-title {
      font-size: 20px;
      font-weight: 600;
      letter-spacing: -0.02em;
      margin-bottom: 6px;
    }
    .form-sub {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.5;
    }

    .field {
      margin-bottom: 16px;
    }
    .field label {
      display: block;
      font-size: 12.5px;
      font-weight: 500;
      color: var(--muted);
      margin-bottom: 6px;
      letter-spacing: 0.02em;
    }
    .field input, .field select {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 14px;
      font-family: inherit;
      background: var(--surface);
      color: var(--text);
      outline: none;
      transition: border-color 0.15s;
      -webkit-appearance: none;
    }
    .field input::placeholder { color: var(--muted); }
    .field input:focus, .field select:focus { border-color: var(--text); }
    .field input.error { border-color: var(--error); }

    .field-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .error-msg {
      font-size: 12px;
      color: var(--error);
      margin-top: 4px;
      display: none;
    }
    .error-msg.visible { display: block; }

    .forgot-link {
      display: block;
      text-align: right;
      font-size: 12px;
      color: var(--muted);
      text-decoration: none;
      margin-top: -8px;
      margin-bottom: 20px;
    }
    .forgot-link:hover { color: var(--text); }

    .btn-primary {
      width: 100%;
      padding: 11px 20px;
      background: var(--text);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-family: inherit;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: opacity 0.15s;
      margin-top: 4px;
    }
    .btn-primary:hover { opacity: 0.85; }

    .divider {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 20px 0;
    }
    .divider-line {
      flex: 1;
      height: 1px;
      background: var(--border);
    }
    .divider-text {
      font-size: 11.5px;
      color: var(--muted);
      font-family: 'DM Mono', monospace;
    }

    .role-select {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 20px;
    }
    .role-card {
      border: 1.5px solid var(--border);
      border-radius: 9px;
      padding: 14px;
      cursor: pointer;
      transition: border-color 0.15s;
    }
    .role-card.selected {
      border-color: var(--text);
      background: #f9f9f8;
    }
    .role-card input[type="radio"] { display: none; }
    .role-icon { font-size: 20px; margin-bottom: 6px; }
    .role-name { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
    .role-desc { font-size: 11px; color: var(--muted); }

    .form-footer {
      margin-top: 24px;
      text-align: center;
      font-size: 12px;
      color: var(--muted);
    }
    .form-footer a {
      color: var(--text);
      font-weight: 500;
      text-decoration: none;
    }

    /* alert */
    .alert {
      padding: 12px 14px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 20px;
      display: none;
    }
    .alert.error { background: #FEF0EE; color: #9B2C2C; border: 1px solid #FBC8C3; display: block; }
    .alert.success { background: #E8F2EC; color: #2D7A4F; border: 1px solid #B7DEC8; display: block; }

    @media (max-width: 768px) {
      .left-panel { display: none; }
      .right-panel { padding: 32px 24px; }
    }
  </style>
</head>
<body>

<!-- LEFT PANEL -->
<div class="left-panel">
  <div class="left-logo">
    <div class="logo-mark">IMM</div>
    <div class="logo-name">Integrated Mechanical<br>Management</div>
  </div>
  <div class="left-tagline">
    <p class="tagline-text">Gerencie sua oficina com <strong>precisão e agilidade</strong>.</p>
    <ul class="features-list">
      <li><span class="feat-dot"></span> Cadastro de clientes e veículos</li>
      <li><span class="feat-dot"></span> Registro e acompanhamento de ocorrências</li>
      <li><span class="feat-dot"></span> Histórico imutável de atendimentos</li>
      <li><span class="feat-dot"></span> Controle de acesso por perfil</li>
    </ul>
  </div>
</div>

<!-- RIGHT PANEL -->
<div class="right-panel">
  <div class="auth-box">

    <div class="tabs">
      <button type="button" class="tab-btn<?= $aba === 'login' ? ' active' : '' ?>" onclick="showTab('login')">Entrar</button>
      <button type="button" class="tab-btn<?= $aba === 'register' ? ' active' : '' ?>" onclick="showTab('register')">Criar conta</button>
    </div>

    <!-- LOGIN PANEL -->
    <form method="POST" class="form-panel<?= $aba === 'login' ? ' active' : '' ?>" id="panel-login">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="login"/>
      <div class="form-head">
        <div class="form-title">Bom retorno</div>
        <div class="form-sub">Acesse com seu e-mail e senha cadastrados.</div>
      </div>

      <?php if ($erroLogin): ?><div class="alert error"><?= e($erroLogin) ?></div><?php endif; ?>

      <div class="field">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($old['login_email'] ?? '') ?>" placeholder="seu@email.com" required autofocus/>
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="senha" placeholder="••••••••" required/>
      </div>
      <a href="#" class="forgot-link" onclick="alert('Para redefinir sua senha, entre em contato com a oficina.'); return false;">Esqueceu a senha?</a>
      <button type="submit" class="btn-primary">Entrar no sistema</button>

      <div class="form-footer">
        Não tem conta? <a href="#" onclick="showTab('register'); return false;">Cadastre-se</a>
      </div>
    </form>

    <!-- REGISTER PANEL -->
    <form method="POST" class="form-panel<?= $aba === 'register' ? ' active' : '' ?>" id="panel-register">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="cadastro"/>
      <div class="form-head">
        <div class="form-title">Criar conta</div>
        <div class="form-sub">Preencha seus dados para se cadastrar como cliente.</div>
      </div>

      <?php if ($erroCad): ?><div class="alert error"><?= e($erroCad) ?></div><?php endif; ?>

      <div class="field-row">
        <div class="field">
          <label>Nome completo</label>
          <input type="text" name="nome" value="<?= e($old['nome'] ?? '') ?>" placeholder="João Silva" required/>
        </div>
        <div class="field">
          <label>CPF</label>
          <input type="text" name="cpf" id="reg-cpf" value="<?= e($old['cpf'] ?? '') ?>" placeholder="000.000.000-00" maxlength="14" required/>
        </div>
      </div>

      <div class="field">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" placeholder="seu@email.com" required/>
      </div>

      <div class="field">
        <label>Telefone</label>
        <input type="tel" name="telefone" id="reg-tel" value="<?= e($old['telefone'] ?? '') ?>" placeholder="(00) 00000-0000" maxlength="15" required/>
      </div>

      <div class="field">
        <label>Endereço</label>
        <input type="text" name="endereco" value="<?= e($old['endereco'] ?? '') ?>" placeholder="Rua, número, bairro, cidade" maxlength="100" required/>
      </div>

      <div class="field-row">
        <div class="field">
          <label>Senha</label>
          <input type="password" name="senha" placeholder="Mín. 8 caracteres" minlength="8" required/>
        </div>
        <div class="field">
          <label>Confirmar senha</label>
          <input type="password" name="senha2" placeholder="Repita a senha" minlength="8" required/>
        </div>
      </div>

      <button type="submit" class="btn-primary">Criar conta</button>

      <div class="form-footer">
        Já tem conta? <a href="#" onclick="showTab('login'); return false;">Faça login</a>
      </div>
    </form>

  </div>
</div>

<script>
  function showTab(tab) {
    document.querySelectorAll('.tab-btn').forEach((b, i) => {
      b.classList.toggle('active', (tab === 'login' ? i === 0 : i === 1));
    });
    document.querySelectorAll('.form-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
  }

  // CPF mask
  document.getElementById('reg-cpf').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0, 11);
    v = v.replace(/(\d{3})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/,'$1-$2');
    this.value = v;
  });

  // Telefone mask
  document.getElementById('reg-tel').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0, 11);
    if (v.length > 10)     v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    else if (v.length > 6) v = v.replace(/(\d{2})(\d{4})(\d+)/, '($1) $2-$3');
    else if (v.length > 2) v = v.replace(/(\d{2})(\d+)/, '($1) $2');
    this.value = v;
  });
</script>
</body>
</html>
