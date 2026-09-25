<?php
require __DIR__ . '/includes/app.php';

$u = usuario();
redirecionar($u ? home_do_perfil($u['perfil']) : 'login.php');
