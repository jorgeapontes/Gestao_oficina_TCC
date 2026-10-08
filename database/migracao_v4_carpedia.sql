-- ─── MIGRAÇÃO v4: CATÁLOGO DA CARPEDIA ────────────────────────────────────────
-- Para bancos já instalados (a instalação nova, gestao_oficina.sql, já inclui tudo).
-- Pode ser executada mais de uma vez. Uso (XAMPP):
--   C:\xampp\mysql\bin\mysql.exe -uroot < database\migracao_v4_carpedia.sql
USE `gestao_oficina`;

-- Cache das respostas da Carpedia (marcas, modelos e versões; a cota da API é pequena).
-- `caminho` é o recurso consultado (ex.: /marcas/fiat/modelos); `resposta` guarda o JSON.
-- Cada recurso ocupa uma única linha, sobrescrita quando expira: não há cópias históricas.
CREATE TABLE IF NOT EXISTS `carpedia_cache` (
  `caminho` varchar(190) NOT NULL,
  `resposta` mediumtext NOT NULL,
  `buscadoEm` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`caminho`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
