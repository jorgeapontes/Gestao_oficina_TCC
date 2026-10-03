<?php
// Sai do sistema. Só aceita POST com o token CSRF: assim um link ou imagem em
// outro site não consegue deslogar o usuário sem ele perceber.
require __DIR__ . '/includes/app.php';

if (is_post()) {
    verificar_csrf();
    encerrar_sessao();
    redirecionar('login.php');
}
// Acesso direto pela URL: volta para o painel (ou para o login, se não houver sessão).
$u = usuario();
redirecionar($u ? home_do_perfil($u['perfil']) : 'login.php');
