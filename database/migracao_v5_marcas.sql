-- ─── MIGRAÇÃO v5: MARCAS FIXAS (REMOÇÃO DA CARPEDIA) ─────────────────────────
-- Para bancos já instalados (a instalação nova, gestao_oficina.sql, já inclui tudo).
-- Pode ser executada mais de uma vez. Uso (XAMPP):
--   C:\xampp\mysql\bin\mysql.exe -uroot < database\migracao_v5_marcas.sql
USE `gestao_oficina`;

-- As 10 marcas com mais carros em circulação no Brasil (as que já existem são mantidas).
-- Outras marcas continuam podendo ser digitadas no cadastro de veículo ("Outra marca").
INSERT IGNORE INTO `marca` (`nome`) VALUES
('Chevrolet'), ('Fiat'), ('Ford'), ('Honda'), ('Hyundai'),
('Jeep'), ('Nissan'), ('Renault'), ('Toyota'), ('Volkswagen');

-- O catálogo da Carpedia foi removido do sistema: o cache dele não é mais usado.
DROP TABLE IF EXISTS `carpedia_cache`;
