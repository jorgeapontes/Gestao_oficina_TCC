-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 28/05/2026 às 00:53
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `gestao_oficina`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `cliente`
--

CREATE TABLE `cliente` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `nome` varchar(100) NOT NULL,
  `cpf` varchar(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(100) NOT NULL,
  `endereco` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `colaborador`
--

CREATE TABLE `colaborador` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `nome` varchar(100) NOT NULL,
  `cpf` varchar(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(100) NOT NULL,
  `cargo` varchar(50) NOT NULL,
  `setor` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `colaborador`
--

INSERT INTO `colaborador` (`id`, `nome`, `cpf`, `email`, `senha`, `cargo`, `setor`) VALUES
('66e7dbd6-3014-452b-b6eb-819d0d7a6a83', 'ABC', '12312312323', 'colaborador@gmail.com', '$2y$10$1C4qOdwOqxrnd/HP.viPDuoNiReQt0N4H7ABN8Wg/8qW1hFWISqtu', 'Mecânico', 'Mecânica Geral');

-- --------------------------------------------------------

--
-- Estrutura para tabela `itemocorrencia`
--

CREATE TABLE `itemocorrencia` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `descricao` varchar(200) NOT NULL,
  `dataHora` timestamp NOT NULL DEFAULT current_timestamp(),
  `idOcorrencia` char(36) NOT NULL,
  `idColaborador` char(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `marca`
--

CREATE TABLE `marca` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `nome` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `modelo`
--

CREATE TABLE `modelo` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `nome` varchar(100) NOT NULL,
  `idMarca` char(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `ocorrencia`
--

CREATE TABLE `ocorrencia` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `descricao` varchar(200) NOT NULL,
  `componentes` varchar(100) DEFAULT NULL,
  `dataRegistro` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('ABERTA','EM_ANDAMENTO','FECHADA','CANCELADA') NOT NULL,
  `idVeiculo` char(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `protocolo`
--

CREATE TABLE `protocolo` (
  `numero` int(11) NOT NULL,
  `dataFinalizacao` date DEFAULT NULL,
  `status` enum('ABERTO','EM_ATENDIMENTO','FINALIZADO','CANCELADO') NOT NULL,
  `responsavel` char(36) NOT NULL,
  `idOcorrencia` char(36) NOT NULL,
  `idVeiculo` char(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `veiculo`
--

CREATE TABLE `veiculo` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `placa` varchar(10) NOT NULL,
  `ano` int(11) NOT NULL,
  `cor` varchar(10) NOT NULL,
  `idModelo` char(36) NOT NULL,
  `idCliente` char(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `cliente`
--
ALTER TABLE `cliente`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cpf` (`cpf`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices de tabela `colaborador`
--
ALTER TABLE `colaborador`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cpf` (`cpf`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices de tabela `itemocorrencia`
--
ALTER TABLE `itemocorrencia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idColaborador` (`idColaborador`),
  ADD KEY `idx_item_ocorrencia` (`idOcorrencia`);

--
-- Índices de tabela `marca`
--
ALTER TABLE `marca`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Índices de tabela `modelo`
--
ALTER TABLE `modelo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idMarca` (`idMarca`);

--
-- Índices de tabela `ocorrencia`
--
ALTER TABLE `ocorrencia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ocorrencia_veiculo` (`idVeiculo`);

--
-- Índices de tabela `protocolo`
--
ALTER TABLE `protocolo`
  ADD PRIMARY KEY (`numero`),
  ADD UNIQUE KEY `idOcorrencia` (`idOcorrencia`),
  ADD KEY `idVeiculo` (`idVeiculo`),
  ADD KEY `idx_protocolo_ocorrencia` (`idOcorrencia`),
  ADD KEY `idx_protocolo_responsavel` (`responsavel`);

--
-- Índices de tabela `veiculo`
--
ALTER TABLE `veiculo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `placa` (`placa`),
  ADD KEY `idModelo` (`idModelo`),
  ADD KEY `idx_veiculo_cliente` (`idCliente`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `protocolo`
--
ALTER TABLE `protocolo`
  MODIFY `numero` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `itemocorrencia`
--
ALTER TABLE `itemocorrencia`
  ADD CONSTRAINT `itemocorrencia_ibfk_1` FOREIGN KEY (`idOcorrencia`) REFERENCES `ocorrencia` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemocorrencia_ibfk_2` FOREIGN KEY (`idColaborador`) REFERENCES `colaborador` (`id`);

--
-- Restrições para tabelas `modelo`
--
ALTER TABLE `modelo`
  ADD CONSTRAINT `modelo_ibfk_1` FOREIGN KEY (`idMarca`) REFERENCES `marca` (`id`);

--
-- Restrições para tabelas `ocorrencia`
--
ALTER TABLE `ocorrencia`
  ADD CONSTRAINT `ocorrencia_ibfk_1` FOREIGN KEY (`idVeiculo`) REFERENCES `veiculo` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `protocolo`
--
ALTER TABLE `protocolo`
  ADD CONSTRAINT `protocolo_ibfk_1` FOREIGN KEY (`responsavel`) REFERENCES `colaborador` (`id`),
  ADD CONSTRAINT `protocolo_ibfk_2` FOREIGN KEY (`idOcorrencia`) REFERENCES `ocorrencia` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `protocolo_ibfk_3` FOREIGN KEY (`idVeiculo`) REFERENCES `veiculo` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `veiculo`
--
ALTER TABLE `veiculo`
  ADD CONSTRAINT `veiculo_ibfk_1` FOREIGN KEY (`idModelo`) REFERENCES `modelo` (`id`),
  ADD CONSTRAINT `veiculo_ibfk_2` FOREIGN KEY (`idCliente`) REFERENCES `cliente` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
