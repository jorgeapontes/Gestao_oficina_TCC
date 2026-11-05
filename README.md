# IMM – Integrated Mechanical Management

Sistema de gestão de oficina mecânica (TCC) em PHP + MySQL/MariaDB. Funciona no computador e no
celular (layout responsivo), com modo claro e escuro.

## Como rodar (XAMPP)

1. Inicie o **MySQL** no painel do XAMPP.
2. Importe o banco: no phpMyAdmin, aba *Importar*, selecione `database/gestao_oficina.sql`
   (o script cria o banco `gestao_oficina`). Pelo terminal:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot < database\gestao_oficina.sql
   ```
   O `gestao_oficina.sql` **já cria tudo** (todas as tabelas e colunas atuais). As migrações são só para
   **bancos já instalados** antes de cada versão; numa instalação nova não precisa rodar nenhuma.
   Rode as que faltam, em ordem. Todas podem ser repetidas sem problema:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot < database\migracao_v3_seguranca.sql
   C:\xampp\mysql\bin\mysql.exe -uroot < database\migracao_v5_marcas.sql
   ```
   - `migracao_v3_seguranca.sql`: tabela do limite de tentativas de login (se esquecer, o sistema cria a
     tabela sozinho no primeiro login).
   - `migracao_v5_marcas.sql`: cadastra as 10 marcas da lista do cadastro de veículo e apaga a tabela
     `carpedia_cache`, que não é mais usada.
3. Sirva o projeto de uma destas formas:
   - copie/clone a pasta para `C:\xampp\htdocs\Gestao_oficina_TCC`, inicie o **Apache** e acesse
     `http://localhost/Gestao_oficina_TCC/`; **ou**
   - na pasta do projeto, rode `C:\xampp\php\php.exe -S localhost:8080 router.php` e acesse `http://localhost:8080/`.
     O `router.php` é obrigatório nesse modo: o servidor embutido ignora os `.htaccess` e, sem ele,
     o dump do banco, os arquivos de `includes/` e as fotos ficariam acessíveis pela URL.
   - Para abrir no celular, use o IP do computador na mesma rede Wi-Fi (ex.: `http://192.168.0.10/Gestao_oficina_TCC/`).

A conexão usa o padrão do XAMPP (`root` sem senha). Para outras credenciais, crie
`includes/conexao.php` (ignorado pelo git) redefinindo `$DB_HOST`, `$DB_USER`, `$DB_PASS` e `$DB_NAME`.

### Marca e modelo do veículo

No cadastro de veículo (cliente e colaborador), a marca é escolhida numa lista com as 10 marcas com mais
carros em circulação no Brasil: Chevrolet, Fiat, Ford, Honda, Hyundai, Jeep, Nissan, Renault, Toyota e
Volkswagen (constante `MARCAS_POPULARES` em `includes/app.php`). Para qualquer outra, escolha
**"Outra marca (digitar)"** e escreva o nome. Modelo e ano são sempre digitados. Marcas e modelos novos são
gravados nas tabelas `marca` e `modelo` na primeira vez em que aparecem.

As fotos dos atendimentos ficam em `uploads/fotos/` (fora do git). A pasta não é acessível pela URL: as fotos
são entregues por `comum/foto.php`, que confere se o usuário logado pode vê-las.

Erros técnicos não aparecem na tela (vão para o log do PHP). Para vê-los durante o desenvolvimento,
defina `$DEBUG = true;` em `includes/conexao.php`.

## Usuários de teste

> **Atenção:** estas contas e senhas são públicas (estão neste README e em `CREDENCIAIS.txt`).
> Antes de colocar o sistema no ar, troque as senhas ou remova essas contas.

| Perfil        | E-mail                | Senha        |
|---------------|-----------------------|--------------|
| Administrador | admin@imm.com         | admin123     |
| Colaborador   | colaborador@imm.com   | colab123     |
| Cliente       | cliente@imm.com       | cliente123   |

Novos clientes podem se cadastrar pela tela de login ("Criar conta").

## Perfis e funcionalidades

- **Cliente** – painel, cadastro/edição/remoção dos próprios veículos, acompanhamento das ocorrências em
  estilo "rastreio" (etapas, linha do tempo, previsão de entrega e fotos), histórico de atendimentos
  (com PDF), **avaliação do atendimento** (1 a 5 estrelas + comentário), lembrete de revisão preventiva,
  edição de perfil, troca de senha e exclusão de conta.
- **Colaborador** – painel com seus atendimentos (com atrasos destacados), registro de ocorrências
  (gera o protocolo), tela de atendimento (itens, etapas, km de entrada, previsão de entrega,
  **fotos**, finalizar/cancelar), clientes, veículos, histórico e veículos com revisão recomendada.
- **Administrador** – tudo do colaborador + dashboard geral, gestão de colaboradores e
  **relatórios com gráficos** (atendimentos por mês, situação atual, componentes mais frequentes,
  desempenho por colaborador, tempo médio, entregas no prazo e satisfação).
- **Todos** – **notificações** no sino (mudanças de status, fotos novas, avaliações recebidas,
  novos clientes), **modo escuro** e confirmações em janelas próprias do sistema.

## Fluxo de atendimento

1. O colaborador busca o veículo pela placa e registra a ocorrência com um ou mais itens,
   a quilometragem de entrada e a previsão de entrega.
2. É gerado um **protocolo** com status `ABERTO` (o colaborador é o responsável) e o cliente é notificado.
3. No atendimento, o protocolo passa pelas etapas `AGUARDANDO_APROVACAO`, `EM_ATENDIMENTO`,
   `AGUARDANDO_PECAS` e `PRONTO` (pronto para retirada). Cada mudança fica registrada na
   **linha do tempo** e gera uma notificação para o cliente.
4. Ao **finalizar**, o protocolo recebe a data de finalização e vai para o **histórico**,
   onde fica imutável (sem edição). O cliente pode então avaliar o atendimento.
5. Veículos sem serviço há mais de 6 meses aparecem como **revisão recomendada**.

## Estrutura

```
index.php, login.php, logout.php, router.php (servidor embutido do PHP), .htaccess (Apache)
assets/       css/app.css (estilos únicos de todas as telas, tema claro/escuro, responsivo)
              js/app.js (menu mobile, tema, notificações, modais e confirmações)
includes/     conexão (db.php), segurança: cabeçalhos/sessão/limites/senha (seguranca.php),
              autenticação/regras/helpers (app.php), limpeza de metadados de fotos (imagem.php),
              <head> comum, sidebar, notificações e componentes de layout (layout.php)
admin/        dashboard, gerenciar_colaboradores, historico, relatorios
colaborador/  dashboard, registrar_ocorrencia, atendimento, veiculos, visualizar_clientes
cliente/      dashboard, meus_veiculos, visualizar_ocorrencias, historico, perfil
comum/        protocolo.php (versão para impressão/PDF), notificacao.php, foto.php (fotos com controle de acesso)
database/     gestao_oficina.sql (instalação nova), migracao_v3_seguranca.sql e migracao_v5_marcas.sql (atualizações)
uploads/      fotos dos atendimentos
```

## Segurança

- **Autenticação:** senhas com `password_hash` (bcrypt), mínimo de 8 caracteres com letras e números;
  limite de 5 tentativas erradas por conta e 20 por IP a cada 15 minutos; mesma mensagem e mesmo
  tempo de resposta para usuário inexistente ou senha errada; limite de cadastros por IP.
- **Sessão:** cookie `HttpOnly` + `SameSite=Lax` (e `Secure` em HTTPS), ID regenerado no login,
  expiração após 2 h sem uso ou 12 h no total, logout só por POST com token CSRF.
- **Revalidação a cada página:** o usuário é recarregado do banco. Conta removida, perfil alterado
  ou senha trocada valem na hora, e trocar a senha encerra as sessões em outros dispositivos.
- **Autorização:** controle de acesso por perfil em cada página e isolamento dos dados de cada cliente.
  Só o administrador redefine a senha de um cliente, e o cliente é notificado.
  Excluir a própria conta exige a senha atual.
- **Dados:** consultas com *prepared statements*, token CSRF em todos os formulários,
  saída sempre escapada (`e()`), exportação CSV protegida contra injeção de fórmulas.
- **Fotos:** tipo real validado, nome aleatório, metadados EXIF/GPS removidos antes de salvar,
  acesso só pelo dono do veículo ou pela equipe. Os arquivos são apagados junto com o veículo ou cliente.
- **Servidor:** cabeçalhos CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy` e `Permissions-Policy`;
  pastas `includes/`, `database/`, `uploads/`, `.git` e arquivos `.sql/.md/.txt` bloqueados pela URL
  (`.htaccess` no Apache, `router.php` no servidor embutido); Chart.js carregado com SRI.
- **Erros:** detalhes técnicos só no log do servidor, nunca na tela.

### Recomendações para produção

1. Use **HTTPS**. O cookie de sessão passa a ser `Secure` e o HSTS é ativado automaticamente.
2. Crie um usuário do MySQL só para o sistema, com senha forte (exemplo em `database/migracao_v3_seguranca.sql`),
   e configure-o em `includes/conexao.php`. Nunca use `root` sem senha.
3. Troque as senhas das contas de teste ou remova-as.
4. Aponte a raiz do site para a pasta do projeto com `AllowOverride All`, para os `.htaccess` valerem.
