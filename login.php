<?php
require __DIR__ . '/includes/app.php';

// Já logado: vai direto para o painel do perfil.
if (usuario()) redirecionar(home_do_perfil(usuario()['perfil']));
verificar_csrf();

$aba      = 'login';
$erroLogin = '';
$erroCad   = '';
$old       = [];

// PRG (Post/Redirect/Get): quando login ou cadastro falham, os dados abaixo são
// guardados na sessão e a página é recarregada via redirect (GET). Isso evita o
// aviso do navegador "Confirmar reenvio do formulário" ao atualizar a página.
if (!empty($_SESSION['_auth_flash'])) {
    $flash = $_SESSION['_auth_flash'];
    unset($_SESSION['_auth_flash']);
    $aba = $flash['aba'] ?? $aba;
    $old = $flash['old'] ?? $old;
    if ($aba === 'register') {
        $erroCad = $flash['erro'] ?? '';
    } else {
        $erroLogin = $flash['erro'] ?? '';
    }
}

// ─── LOGIN ───────────────────────────────────────────────────────────────────
// Procura primeiro em colaborador (perfil COLABORADOR/ADMIN), depois em cliente.
// O usuário pode entrar tanto com e-mail quanto com CPF (CPF só existe para clientes).
if (acao() === 'login') {
    $metodoLogin = post('login_metodo') === 'cpf' ? 'cpf' : 'email';
    $senha       = $_POST['senha'] ?? '';
    $old['login_metodo'] = $metodoLogin;

    if ($metodoLogin === 'cpf') {
        $old['login_cpf'] = post('cpf');
        $cpfLogin = so_digitos(post('cpf'));

        if ($cpfLogin === '' || $senha === '') {
            $erroLogin = 'Preencha todos os campos.';
        } else {
            $u = db_one("SELECT id, nome, email, senha, '' AS cargo, 'CLIENTE' AS perfil FROM cliente WHERE cpf = ?", [$cpfLogin]);
            if ($u && password_verify($senha, $u['senha'])) {
                session_regenerate_id(true);
                unset($u['senha']);
                $_SESSION['user'] = $u;
                redirecionar(home_do_perfil($u['perfil']));
            }
            $erroLogin = 'CPF ou senha incorretos. Tente novamente.';
        }
    } else {
        $email = post('email');
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

    if ($erroLogin !== '') {
        $_SESSION['_auth_flash'] = ['aba' => 'login', 'erro' => $erroLogin, 'old' => $old];
        redirecionar('login.php');
    }
}

// Valida CPF pelo algoritmo oficial dos dígitos verificadores (módulo 11),
// rejeitando também sequências de dígitos repetidos (ex.: 000.000.000-00).
if (!function_exists('cpf_valido')) {
    function cpf_valido(string $cpf): bool {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11) return false;
        if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t = 9; $t <= 10; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$cpf[$i] * (($t + 1) - $i);
            }
            $digito = (($soma * 10) % 11) % 10;
            if ((int)$cpf[$t] !== $digito) return false;
        }
        return true;
    }
}

// ─── CADASTRO DE CLIENTE ─────────────────────────────────────────────────────
if (acao() === 'cadastro') {
    $aba = 'register';
    $old = [
        'nome' => post('nome'), 'cpf' => post('cpf'), 'email' => post('email'),
        'telefone' => post('telefone'),
        'cep' => post('cep'), 'rua' => post('rua'), 'numero' => post('numero'),
        'complemento' => post('complemento'), 'bairro' => post('bairro'), 'cidade' => post('cidade'),
    ];
    $cpf = so_digitos($old['cpf']);
    $cep = so_digitos($old['cep']);
    $senha = $_POST['senha'] ?? '';

    // Endereço completo é montado em uma única string para a coluna "endereco".
    $cepFormatado = strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $old['cep'];
    $enderecoPartes = array_filter([
        trim($old['rua']) !== '' ? trim($old['rua']) . ', ' . trim($old['numero']) : '',
        trim($old['complemento']),
        trim($old['bairro']),
        trim($old['cidade']),
        $cepFormatado,
    ], fn($parte) => $parte !== '');
    $endereco = implode(' - ', $enderecoPartes);

    if (!$old['nome'] || !$cpf || !$old['email'] || !$old['telefone'] || $senha === ''
        || !$cep || !$old['rua'] || !$old['numero'] || !$old['bairro'] || !$old['cidade']) {
        $erroCad = 'Preencha todos os campos obrigatórios.';
    } elseif (!cpf_valido($cpf)) {
        $erroCad = 'CPF inválido';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $erroCad = 'E-mail inválido.';
    } elseif (strlen($cep) !== 8) {
        $erroCad = 'CEP inválido: informe os 8 dígitos.';
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
                so_digitos($old['telefone']), $endereco,
            ]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $id, 'nome' => $old['nome'], 'email' => $old['email'], 'cargo' => '', 'perfil' => 'CLIENTE'];
            flash('ok', 'Cadastro realizado com sucesso! Bem-vindo(a).');
            redirecionar('cliente/dashboard.php');
        } catch (mysqli_sql_exception $ex) {
            $erroCad = erro_db($ex);
        }
    }

    if ($erroCad !== '') {
        $_SESSION['_auth_flash'] = ['aba' => 'register', 'erro' => $erroCad, 'old' => $old];
        redirecionar('login.php');
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
      position: relative;
      display: flex;
      gap: 0;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 4px;
      margin-bottom: 36px;
    }
    .tabs-slider {
      position: absolute;
      top: 4px;
      left: 4px;
      width: calc(50% - 4px);
      height: calc(100% - 8px);
      background: var(--surface);
      border-radius: 7px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      transition: transform 0.3s cubic-bezier(0.65, 0, 0.35, 1);
      z-index: 0;
    }
    .tabs.tab-register .tabs-slider {
      transform: translateX(100%);
    }
    .tab-btn {
      position: relative;
      z-index: 1;
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
      transition: color 0.2s;
    }
    .tab-btn.active {
      color: var(--text);
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

    .field-row-cep {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 12px;
    }

    .error-msg {
      font-size: 12px;
      color: var(--error);
      margin-top: 4px;
      display: none;
    }
    .error-msg.visible { display: block; }

    /* login method toggle (E-mail / CPF) */
    .method-toggle {
      position: relative;
      display: grid;
      grid-template-columns: 1fr 1fr;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 3px;
      margin-bottom: 6px;
      width: fit-content;
    }
    .method-slider {
      position: absolute;
      top: 3px;
      left: 3px;
      width: calc(50% - 3px);
      height: calc(100% - 6px);
      background: var(--surface);
      border-radius: 5px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      transition: transform 0.3s cubic-bezier(0.65, 0, 0.35, 1);
      z-index: 0;
    }
    .method-toggle.method-cpf .method-slider {
      transform: translateX(100%);
    }
    .method-btn {
      position: relative;
      z-index: 1;
      padding: 5px 14px;
      border: none;
      border-radius: 6px;
      background: transparent;
      font-family: inherit;
      font-size: 11.5px;
      font-weight: 500;
      color: var(--muted);
      cursor: pointer;
      transition: color 0.2s;
    }
    .method-btn.active {
      color: var(--text);
    }
    .credential-field { display: none; }
    .credential-field.active { display: block; }

    .btn-secondary {
      padding: 10px 18px;
      background: var(--text);
      color: #fff;
      border: 1px solid var(--text);
      border-radius: 8px;
      font-family: inherit;
      font-size: 12.5px;
      font-weight: 500;
      cursor: pointer;
      white-space: nowrap;
      transition: opacity 0.15s;
    }
    .btn-secondary:hover { opacity: 0.85; }
    .btn-secondary:disabled { opacity: 0.5; cursor: default; }

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

    <div class="tabs<?= $aba === 'register' ? ' tab-register' : '' ?>" id="tabs">
      <div class="tabs-slider"></div>
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

      <?php $metodoAtual = ($old['login_metodo'] ?? 'email') === 'cpf' ? 'cpf' : 'email'; ?>
      <input type="hidden" name="login_metodo" id="login-metodo" value="<?= e($metodoAtual) ?>"/>

      <div class="field">
        <div class="method-toggle<?= $metodoAtual === 'cpf' ? ' method-cpf' : '' ?>" id="method-toggle">
          <div class="method-slider"></div>
          <button type="button" class="method-btn<?= $metodoAtual === 'email' ? ' active' : '' ?>" id="method-email-btn" onclick="showLoginMethod('email')">E-mail</button>
          <button type="button" class="method-btn<?= $metodoAtual === 'cpf' ? ' active' : '' ?>" id="method-cpf-btn" onclick="showLoginMethod('cpf')">CPF</button>
        </div>

        <div class="credential-field<?= $metodoAtual === 'email' ? ' active' : '' ?>" id="login-field-email">
          <input type="email" name="email" id="login-email" value="<?= e($old['login_email'] ?? '') ?>" placeholder="seu@email.com" <?= $metodoAtual === 'email' ? 'autofocus' : '' ?>/>
        </div>
        <div class="credential-field<?= $metodoAtual === 'cpf' ? ' active' : '' ?>" id="login-field-cpf">
          <input type="text" name="cpf" id="login-cpf" value="<?= e($old['login_cpf'] ?? '') ?>" placeholder="000.000.000-00" maxlength="14" <?= $metodoAtual === 'cpf' ? 'autofocus' : '' ?>/>
        </div>
        <div class="form-sub" style="margin-top: 5px; font-size: 12px">Escolha qual dado utilizar para fazer login</div>
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
          <div class="error-msg" id="cpf-error-msg">CPF inválido</div>
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
        <label>CEP</label>
        <div class="field-row-cep">
          <input type="text" name="cep" id="reg-cep" value="<?= e($old['cep'] ?? '') ?>" placeholder="00000-000" maxlength="9" required/>
          <button type="button" class="btn-secondary" id="btn-buscar-cep" onclick="buscarCep()">Buscar CEP</button>
        </div>
        <div class="error-msg" id="cep-error-msg">CEP não encontrado.</div>
      </div>

      <div class="field">
        <label>Rua</label>
        <input type="text" name="rua" id="reg-rua" value="<?= e($old['rua'] ?? '') ?>" placeholder="Nome da rua" maxlength="120" required/>
      </div>

      <div class="field-row">
        <div class="field">
          <label>Número</label>
          <input type="text" name="numero" id="reg-numero" value="<?= e($old['numero'] ?? '') ?>" placeholder="123" maxlength="10" required/>
        </div>
        <div class="field">
          <label>Complemento</label>
          <input type="text" name="complemento" id="reg-complemento" value="<?= e($old['complemento'] ?? '') ?>" placeholder="Apto, bloco... (opcional)" maxlength="60"/>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label>Bairro</label>
          <input type="text" name="bairro" id="reg-bairro" value="<?= e($old['bairro'] ?? '') ?>" placeholder="Bairro" maxlength="80" required/>
        </div>
        <div class="field">
          <label>Cidade</label>
          <input type="text" name="cidade" id="reg-cidade" value="<?= e($old['cidade'] ?? '') ?>" placeholder="Cidade" maxlength="80" required/>
        </div>
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
    document.getElementById('tabs').classList.toggle('tab-register', tab === 'register');
    document.querySelectorAll('.form-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
  }

  // Alterna entre login por E-mail ou por CPF
  function showLoginMethod(metodo) {
    document.getElementById('method-email-btn').classList.toggle('active', metodo === 'email');
    document.getElementById('method-cpf-btn').classList.toggle('active', metodo === 'cpf');
    document.getElementById('method-toggle').classList.toggle('method-cpf', metodo === 'cpf');
    document.getElementById('login-field-email').classList.toggle('active', metodo === 'email');
    document.getElementById('login-field-cpf').classList.toggle('active', metodo === 'cpf');
    document.getElementById('login-metodo').value = metodo;

    // Limpa o que foi digitado nos dois campos: evita que um valor incompleto
    // deixado no campo escondido (ex.: e-mail inválido) bloqueie o envio do
    // formulário quando o login é feito pelo outro campo.
    document.getElementById('login-email').value = '';
    document.getElementById('login-cpf').value = '';
  }

  // Aplica a máscara de CPF (000.000.000-00) a um campo, aceitando tanto
  // dígitos puros quanto os caracteres já digitados pelo próprio usuário.
  function maskCpfField(el) {
    el.addEventListener('input', function() {
      let v = this.value.replace(/\D/g,'').substring(0, 11);
      v = v.replace(/(\d{3})(\d)/,'$1.$2');
      v = v.replace(/(\d{3})(\d)/,'$1.$2');
      v = v.replace(/(\d{3})(\d{1,2})$/,'$1-$2');
      this.value = v;
    });
  }
  maskCpfField(document.getElementById('reg-cpf'));
  maskCpfField(document.getElementById('login-cpf'));

  // Telefone mask
  document.getElementById('reg-tel').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0, 11);
    if (v.length > 10)     v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    else if (v.length > 6) v = v.replace(/(\d{2})(\d{4})(\d+)/, '($1) $2-$3');
    else if (v.length > 2) v = v.replace(/(\d{2})(\d+)/, '($1) $2');
    this.value = v;
  });

  // CEP mask (00000-000)
  document.getElementById('reg-cep').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0, 8);
    v = v.replace(/(\d{5})(\d)/,'$1-$2');
    this.value = v;
    document.getElementById('cep-error-msg').classList.remove('visible');
  });

  // Busca o CEP digitado e autocompleta Rua, Bairro e Cidade
  function buscarCep() {
    const cepInput = document.getElementById('reg-cep');
    const cep = cepInput.value.replace(/\D/g,'');
    const errorMsg = document.getElementById('cep-error-msg');
    const btn = document.getElementById('btn-buscar-cep');
    errorMsg.classList.remove('visible');

    if (cep.length !== 8) {
      errorMsg.textContent = 'Informe um CEP com 8 dígitos.';
      errorMsg.classList.add('visible');
      return;
    }

    btn.disabled = true;
    const textoOriginal = btn.textContent;
    btn.textContent = 'Buscando...';

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
      .then(resp => resp.json())
      .then(data => {
        if (data.erro) {
          errorMsg.textContent = 'CEP não encontrado.';
          errorMsg.classList.add('visible');
          return;
        }
        document.getElementById('reg-rua').value = data.logradouro || '';
        document.getElementById('reg-bairro').value = data.bairro || '';
        document.getElementById('reg-cidade').value = data.localidade || '';
        if (data.logradouro) {
          document.getElementById('reg-numero').focus();
        }
      })
      .catch(() => {
        errorMsg.textContent = 'Não foi possível buscar o CEP agora. Preencha manualmente.';
        errorMsg.classList.add('visible');
      })
      .finally(() => {
        btn.disabled = false;
        btn.textContent = textoOriginal;
      });
  }

  // Validação do CPF (dígitos verificadores) no navegador, para feedback imediato
  function cpfValido(cpf) {
    cpf = cpf.replace(/\D/g,'');
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (let t = 9; t <= 10; t++) {
      let soma = 0;
      for (let i = 0; i < t; i++) soma += parseInt(cpf.charAt(i), 10) * ((t + 1) - i);
      const digito = ((soma * 10) % 11) % 10;
      if (digito !== parseInt(cpf.charAt(t), 10)) return false;
    }
    return true;
  }

  // Impede o envio do cadastro caso o CPF seja inválido
  document.getElementById('panel-register').addEventListener('submit', function(ev) {
    const cpfInput = document.getElementById('reg-cpf');
    const cpfError = document.getElementById('cpf-error-msg');
    if (!cpfValido(cpfInput.value)) {
      ev.preventDefault();
      cpfInput.classList.add('error');
      cpfError.classList.add('visible');
      cpfInput.focus();
    } else {
      cpfInput.classList.remove('error');
      cpfError.classList.remove('visible');
    }
  });
</script>
</body>
</html>