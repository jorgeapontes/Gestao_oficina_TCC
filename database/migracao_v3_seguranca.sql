-- ─── MIGRAÇÃO v3: SEGURANÇA ──────────────────────────────────────────────────
-- Para bancos já instalados. Uso (XAMPP):
--   C:\xampp\mysql\bin\mysql.exe -uroot < database\migracao_v3_seguranca.sql
-- O sistema também cria esta tabela sozinho no primeiro login, se ela não existir.
USE `gestao_oficina`;

-- Tentativas de login/cadastro (limite contra força bruta). A chave é o hash
-- SHA-256 do IP, e-mail ou CPF: o dado em si não fica gravado.
CREATE TABLE IF NOT EXISTS `tentativa_login` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` varchar(20) NOT NULL,
  `chave` char(64) NOT NULL,
  `dataHora` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tentativa` (`tipo`, `chave`, `dataHora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- RECOMENDADO EM PRODUÇÃO: um usuário do MySQL só para o sistema, com senha forte
-- e sem privilégios de administração. Troque a senha abaixo, descomente, rode e
-- informe o usuário/senha em includes/conexao.php ($DB_USER / $DB_PASS).
-- CREATE USER 'imm_app'@'localhost' IDENTIFIED BY 'troque-por-uma-senha-forte';
-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON `gestao_oficina`.* TO 'imm_app'@'localhost';
-- FLUSH PRIVILEGES;
