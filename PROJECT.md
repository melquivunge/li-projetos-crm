# PROJECT.md: CRM LI Projetos

Padrão: dev-toolkit (`~/Documents/dev-toolkit`, fluxo `workflows/new-project.md`; módulos `security-development`, `testing` e `implementation`). Plano aprovado: `~/.claude/plans/glimmering-discovering-gizmo-of-7143b3d7-707c-4550-bde9-0851c812736b.md`.

## Produto
CRM interno da LI Projetos (arquitetura, design de interiores, plantas baixas, consultoria e detalhamento executivo; atendimento 100% online, DF e Goiânia).
- Os leads do site (formulário, agendamento, cliques) entram sozinhos.
- Funil de clientes.
- Propostas com a tabela de preços oficial, enviadas por e-mail e WhatsApp.
- Painel de desempenho.

## Decisões
- PHP 8.3 sem framework, PDO/MySQL, Bootstrap 5.3 **local** (sem CDN, por causa da CSP). Composer: `phpmailer/phpmailer` e `dompdf/dompdf`.
- Produção: subdomínio `crm.liprojetos.com.br` na Hostinger (mesma conta do site), raiz em `public/`, `.env` fora da área pública.
- Perfis: `admin` (sócias: vê tudo, gerencia usuários e preços) e `equipe` (vê só os clientes onde `owner_id` = o próprio usuário).
- E-mail: SMTP da Hostinger (em local, Mailpit).
- WhatsApp: API **não oficial** da DropeStore (https://dropestore.com/), atrás da interface `WhatsAppSender`, com fallback em links wa.me.
- Design: uso interno ("Operate"). Seguir as regras de UX da `ui-ux-pro-max` (contraste de 4,5:1, alvos de toque de 44px, erros junto ao campo, foco visível) e a sobriedade da `taste-skill`.

## Comandos (confirmados)
```
docker compose up -d --build                          # app :8080, mysql :3307 (local), mailpit :8025
docker compose exec -T app composer install
docker compose exec -T app php bin/create-admin.php "Nome" email   # senha digitada ou via ADMIN_PASSWORD
```
O schema (`database/schema.sql`) roda sozinho no primeiro `up` (volume `dbdata`). Para recriar o banco: `docker compose down -v` (**apaga os dados locais**).

## Estado (22/11/2026)
- **Fase 1 (base e segurança): feita.**
  - Login com limite de 5 tentativas por e-mail a cada 15 minutos.
  - Sessão segura, CSRF em todo POST que não seja `/api/*`, cabeçalhos CSP e X-Frame-Options, log de auditoria.
  - Cadastro e ativação de usuários (admin) e painel com contagem por etapa.
  - **Verificado:** `/login` responde 200, `/` sem login redireciona para o login, POST sem CSRF dá 403, cabeçalhos presentes.
  - **Ainda não verificado:** login com sucesso, bloqueio após 5 erros, criação de usuário, 403 da equipe em cliente alheio (ainda não existe tela de cliente).
- Admin criada: `larissa@liprojetos.com.br`.
  - **Pendência de segurança:** a senha ficou em `storage-admin.txt`, na raiz do projeto. Anotar e apagar o arquivo. Ajustar o fluxo para a senha não ir para disco.
- **Fase 2 (clientes e funil): feita (23/11/2026).**
  - `/clientes`: lista com busca (nome, e-mail, WhatsApp, cidade), filtros por etapa e responsável, paginação de 50 e exportação CSV (com BOM, separador `;` e proteção contra fórmulas).
  - Cadastro e edição com WhatsApp normalizado para +55, funil com motivo de perda obrigatório, linha do tempo (notas, etapas, sistema), `first_contact_at` gravado na primeira saída de "Novo" e exclusão LGPD (só admin, digitando EXCLUIR).
  - Lógica em `src/Clients.php`; rotas em `src/Controllers/ClientController.php`.
  - Coluna `gclid` adicionada (`database/migrations/002_clients_gclid.sql`) para importar contratos como conversões off-line no Google Ads.
  - **Verificado:** `php tests/run.php` (10 checks) e curl de ponta a ponta. Login funciona; a equipe recebe 403 ao abrir, mudar etapa ou excluir cliente alheio e não o vê na lista nem no CSV. POST sem CSRF dá 403, XSS sai escapado, o bloqueio ocorre na 6ª tentativa (mesmo com a senha certa) e a busca por telefone funciona.
- **Fase 3 (integração com o site): feita (23/11/2026).**
  - `POST /api/lead` e `POST /api/event` (`src/Controllers/ApiController.php`). O corpo é JSON enviado como `text/plain`: é um pedido CORS simples, sem preflight, e `keepalive` continua funcionando quando a aba do WhatsApp abre.
  - Proteções: Origin exatamente igual a `SITE_ORIGIN`, chave `SITE_API_KEY` no corpo, honeypot `website` (responde 202 e não grava nada), limite por IP de 10 leads e 120 eventos por hora (tabela `api_rate`) e validação dos campos.
  - Mesma pessoa (mesmo WhatsApp ou e-mail): o contato entra no histórico do cadastro existente, sem duplicar. O pedido de reunião é aceito sem telefone, porque o contato chega pelo WhatsApp.
  - Site: `src/modules/analytics.js` (`sendToCrm`, `buildCrmBody`), o gclid guardado em `formatters.js` e o bloco `crm: { endpoint, siteKey }` em `site.config.js` (**vazio = desligado**). Os formulários têm texto de consentimento LGPD e honeypot.
  - **Verificado:** curl (origem, chave, JSON, evento inválido, honeypot, deduplicação, 429) e navegador real (site :3000 → CRM :8080: formulário, reunião e clique gravados com origem, gclid e consentimento).
  - **Para ligar em produção:** no `.env` do CRM, `SITE_ORIGIN=https://liprojetos.com.br` e uma `SITE_API_KEY` nova. No `site.config.js`, `crm.endpoint = "https://crm.liprojetos.com.br"` e `siteKey` com a mesma chave. Depois `node scripts/build.js` e publicar.
- **Fase 4 (propostas): feita (23/11/2026).**
  - O cálculo fica em `src/Pricing.php`: faixa do estudo pela área (acima de 300 m² fica "sob consulta"), mínimo de R$ 3.500 por m², e abatimento só da etapa paga mais alta, só sobre o projeto completo e nunca acima dele.
  - Telas e envio em `src/Controllers/ProposalController.php`:
    - criação a partir do cliente, com a faixa do estudo sugerida pela área;
    - PDF com Dompdf (`views/proposal_pdf.php`);
    - envio por e-mail (PHPMailer, PDF em anexo, timeout de 10 s) e por WhatsApp (link wa.me);
    - status rascunho → enviada → vista → aceita ou recusada;
    - o primeiro envio move o cliente para "Proposta" e o aceite para "Fechado".
  - Link público `/p/{token}` (64 caracteres hex, sem login): rascunho dá 404; a primeira abertura marca "vista"; aceitar ou recusar exige CSRF e não funciona com a proposta vencida.
  - `/precos` (só admin) edita a tabela. As propostas já criadas não mudam.
  - **Verificado:** `php tests/run.php` (18 checks) e `bash tests/e2e-proposals.sh` (valores R$ 12.000 − R$ 1.690 = R$ 10.310, PDF, e-mail no Mailpit com anexo, wa.me, 403 da equipe e aceite do cliente fechando o negócio).
- **Fase 6 (painel): feita (23/11/2026).**
  - Página inicial com período (7, 30 ou 90 dias, 12 meses) e filtro por responsável (admin).
  - Indicadores: leads novos, fechados e conversão, mediana até o 1º contato, ticket médio, propostas enviadas e aceitas (quantidade e valor), cliques no WhatsApp e em "Agendar" (só admin, porque são do site inteiro).
  - Gráfico de leads por dia (SVG gerado no servidor, tooltip nativo, tabela opcional), barras por origem e funil atual.
  - A cor única do gráfico, `#A16207`, passou no validador da skill dataviz.
  - CSP: os atributos `style=""` das views, que estavam sendo bloqueados em silêncio, viraram classes em `app.css`.
  - **Verificado:** testes (20 checks) e captura de tela com dados de exemplo.
- **Perfil, notificações e redesign: feito (23/11/2026).**
  - Back-end:
    - migração `003_profile_notifications.sql`;
    - `src/Notify.php`: lead novo, e proposta aberta, aceita ou recusada;
    - `ProfileController`: perfil, foto reprocessada para 256 px fora da pasta pública, senha, tema e notificações.
  - Visual (feito com /impeccable e /ui-ux-pro-max, conforme as referências em `design/referencias/`; ver `DESIGN.md`):
    - sidebar fixa no computador e menu lateral no celular;
    - topo com busca (atalho "/"), sino com dropdown e menu do avatar com troca de tema;
    - login dividido com o render do estúdio;
    - 3 temas: LI Projetos (padrão), claro e escuro;
    - fontes da marca servidas localmente.
  - **Verificado:** capturas de tela em 1440 e 390 px nos 3 temas, com o menu do celular aberto e o dropdown do sino. Upload de foto, PHP disfarçado de PNG (recusado), foto sem login (redireciona), senha atual errada, tema inválido e `back=//evil.com` (ignorado), e `tests/run.php`.
- **CRM de verdade: funil, próxima ação e propostas (23/11/2026).**
  - **`/funil`:** quadro Kanban por etapa. Arrastar envia um POST com CSRF; "Perdido" pede o motivo num `<dialog>`. Mostra o valor da última proposta, os dias na etapa, a próxima ação e o responsável. Fechados e perdidos aparecem só dos últimos 30 dias.
  - **Próxima ação:** colunas `next_action`, `next_action_at` e `stage_changed_at` (migração `004_next_action.sql`). Cartão na ficha do cliente com "Concluída" e "Trocar ação", selo de "há N dias", "hoje" ou "amanhã", e filtro "Ação atrasada".
  - **`/clientes` reorganizada:**
    - abas por etapa com contadores;
    - tabela com iniciais, projeto e m², etapa, próxima ação, origem e responsável;
    - cartões no celular;
    - ordenada por próxima ação.
  - **`/propostas`:** lista com abas por status e os totais "em aberto" e "aceitas".
  - **Documento da proposta** (`views/_proposal_doc.php`), compartilhado pela página do cliente e pela prévia interna: capa com o render, metadados, itens, total, condições e "Como seguimos". O PDF (`proposal_pdf.php`) segue o mesmo desenho.
  - **Atenção:** `tests/e2e-proposals.sh` apagava todos os clientes ao terminar. Corrigido para apagar só o cliente de teste.
  - **Verificado:** capturas da lista, do funil, da proposta e do PDF; arrastar (204), perdido sem motivo (422), sem CSRF (403); todas as páginas em 200 sem avisos do PHP; `tests/run.php`; `e2e-proposals.sh`.
- **Roadmap aprovado (23/11/2026):** documento "Li Project v1.0", plano em `~/.claude/plans/glimmering-discovering-gizmo-of-7143b3d7-707c-4550-bde9-0851c812736b.md`. Fases A → H:
  - A: proposta profissional;
  - B: papéis, oportunidades e tarefas;
  - C: briefing e arquivos;
  - D: projetos;
  - E: revisões e aprovações;
  - F: portal do cliente;
  - G: financeiro;
  - H: automações.
  Decisões: manter o PHP; fases do projeto conforme o documento; forma de pagamento e prazos preenchidos por proposta; sem módulo de obras.
- **Fase A (proposta profissional): feita (23/11/2026).**
  - Migração `005_proposals_pro.sql`:
    - campos de entendimento, escopo, entregáveis, exclusões, forma de pagamento, revisões, versão e `parent_id`;
    - status `substituida`;
    - tabelas `proposal_schedule`, `proposal_templates` (5 serviços, com texto do site) e `approvals` (nunca apagada).
  - Formulário em 5 blocos, pré-preenchido pelo modelo do serviço. Cronograma por etapa com prazo; só entram as etapas com prazo.
  - Edição: um rascunho é editado no próprio lugar; uma proposta enviada gera uma nova versão, a antiga vira "Substituída" e o link antigo redireciona para a versão nova.
  - Aceite exige nome completo e "li e concordo", e grava nome, IP, user agent e versão em `approvals`. O histórico aparece na proposta.
  - Documento com 8 seções numeradas: sobre o estúdio (só fatos reais; iniciais em vez das fotos genéricas do site), entendimento, escopo com "Você recebe" e "Não está incluído", cronograma, investimento e forma de pagamento, revisões, 3 renders reais e como seguimos. O PDF tem as mesmas seções.
  - `/precos/modelos` (admin) edita os modelos. **As sócias devem revisar os textos.**
  - **Verificado:** `tests/run.php` e `e2e-proposals.sh` (modelo, versão 2, link antigo levando à v2, aceite sem nome recusado, aceite registrado na v2); capturas da página e das 3 páginas do PDF.
- **Fase B (papéis, oportunidades e tarefas): feita (23/11/2026).** Migração `006_opportunities_tasks_roles.sql`, com backup antes em `scratchpad/backup-before-006.sql`.
  - **Oportunidades** (`src/Opportunities.php`, `OpportunityController`):
    - um cliente pode ter várias; as etapas são as do documento (Novo → Qualificado → Briefing → Proposta enviada → Negociação → Ganho/Perdido);
    - `clients.stage` espelha a oportunidade aberta mais avançada (`syncClient`);
    - o funil (`/funil`) mostra as oportunidades;
    - o lead do site cria uma oportunidade;
    - a proposta fica ligada à oportunidade: o envio move para "Proposta enviada", o aceite para "Ganho" e a recusa para "Negociação".
  - **Tarefas** (`TaskController`, `/tarefas`):
    - abas Atrasadas, Hoje, Próximas e Feitas, com as minhas e as da equipe (admin);
    - prioridade, tarefa ligada ao cliente e à oportunidade, aviso no sino quando alguém cria uma tarefa para você;
    - contador na sidebar (vermelho quando há atrasadas) e bloco "Suas tarefas para hoje" no painel;
    - a antiga "próxima ação" virou tarefa: as colunas `clients.next_action*` ficaram, sem uso, para permitir voltar atrás.
  - **Papéis acumuláveis** (`user_roles`, `Auth::can` e `permsFor`):
    - admin: tudo;
    - comercial: clientes, funil, propostas e tarefas;
    - gestor: clientes, tarefas e projetos;
    - designer: tarefas e projetos;
    - financeiro: tarefas e finanças.
    - Contas antigas sem papel valem como comercial + designer. A tela de Usuários tem papéis por pessoa, e ninguém tira o próprio admin.
  - **Atividades:** nota, ligação e reunião.
  - **Verificado:** `tests/run.php` (6 testes novos), `tests/e2e-phaseB.sh` (permissões por papel, lead criando oportunidade, arrastar, perda sem motivo, 403 em oportunidade e tarefa alheias, notificação, papéis) e `e2e-proposals.sh` (aceite levando a oportunidade para "Ganho"); capturas do funil e da ficha do cliente.
- **Fase C (briefing e arquivos): feita (23/11/2026).** Migração `007_briefing_files.sql`.
  - **Perguntas por serviço** (`briefing_templates`, 5 serviços): texto simples, `Pergunta | tipo | opções`, editável em Preços → Briefing. Cada briefing guarda uma cópia das perguntas, para que uma mudança no modelo não altere briefings antigos.
  - **Fluxo do briefing:**
    - "Criar briefing" na oportunidade;
    - a equipe preenche e anexa arquivos;
    - "Enviar ao cliente" abre o WhatsApp com o link `/b/{token}`, que só abre depois do envio, e move a oportunidade para "Briefing";
    - o cliente responde, anexa arquivos e pode confirmar com o nome completo, o que fica gravado em `approvals`;
    - a equipe recebe aviso no sino;
    - se a equipe editar depois da validação, o briefing vira a versão 2 e volta a rascunho.
  - **Arquivos** (`src/Files.php`):
    - ficam em `storage/files` (fora de public/) e são validados pelo conteúdo real (finfo): JPG, PNG, WebP, HEIC ou PDF, até 15 MB, no máximo 30 por briefing;
    - nome limpo, versão por nome;
    - servidos com permissão e CSP `sandbox`;
    - pelo link, o cliente só vê os arquivos que ele mesmo enviou.
  - **Upload:** no Docker, 16 MB por arquivo e 80 MB por envio (Dockerfile). **Na Hostinger, ajustar em hPanel → Avançado → Configuração do PHP**: `upload_max_filesize=16M`, `post_max_size=80M`.
  - **Verificado:** `tests/run.php` (4 testes novos) e `tests/e2e-briefing.sh` (12 perguntas, link 404 antes do envio, etapa "Briefing", PHP disfarçado recusado, nome curto recusado, validação gravada, 403 entre a equipe, arquivo só pelo link certo, versão 2 após edição), mais a regressão das fases B e A.
  - Observação: o próprio Mac bloqueia a leitura de arquivos com assinatura de webshell. O e2e usa `<?php echo 1;` como conteúdo disfarçado.
- **Fase D (projetos): feita (22/11/2026).** Migração `008_projects.sql` (também em `schema.sql`), com backup antes em `scratchpad/backup-before-008.sql`.
  - **Nasce do aceite:** ao aceitar a proposta (pela equipe ou pelo cliente), `Projects::fromProposal` cria o projeto com as fases do cronograma; os prazos saem do texto ("15 dias", "2 semanas", "10 dias úteis", "1 mês") em sequência a partir do aceite. Sem cronograma: as 7 fases padrão, sem prazo. Um projeto por proposta. As 3 propostas que já estavam aceitas ganharam projeto.
  - **Fases editáveis** (nome, prazo, adicionar, excluir, concluir, reabrir); a primeira em aberto fica "em andamento" e a entrega do projeto é o prazo da última fase.
  - **Tarefas do projeto** com fase e "depois de" (`blocked_by`): não dá para concluir antes da que bloqueia. Aparecem também em `/tarefas`, com link para o projeto.
  - **Entregáveis** por fase com situação (a fazer, em produção, enviado, aprovado) e arquivos (JPG, PNG, WebP, HEIC, PDF); o mesmo nome vira nova versão. Rota própria `/entregas/arquivos/{id}`, com checagem de acesso ao projeto.
  - **Horas** (`time_entries`): "1,5", "1:30" ou "2h", data até hoje, ligada ou não a uma tarefa.
  - **Visões:** lista (fase atual, progresso, entrega, tarefas atrasadas), Kanban por fase e calendário mensal (fim de fase e tarefas; no celular vira lista de dias).
  - **Quem vê:** admin e gestor veem todos; os demais veem os projetos de que são responsáveis ou em que têm tarefa. Comercial não tem acesso a projetos.
  - **Correção de fuso:** o MySQL estava em UTC e o PHP em Brasília, então das 21h à meia-noite as tarefas de hoje apareciam como atrasadas. A conexão agora usa o fuso do PHP (`Db::pdo`). Horários gravados antes disso estão 3 h adiantados (só dados de teste).
  - **Verificado:** `tests/run.php` (6 testes novos), `tests/e2e-projects.sh` (aceite → projeto, sem duplicar, permissões, bloqueio, fases, versões, PHP disfarçado recusado, arquivo protegido, horas, conclusão, zero avisos do PHP) e os e2e das fases A–C; capturas da lista, do Kanban, do calendário e da ficha, nos temas claro e escuro e no celular.
- **Depois da Fase D (22/11/2026):**
  - **Kanban de projetos com arrastar:** soltar numa fase conclui as anteriores; voltar reabre a fase e as seguintes; "Todas concluídas" fecha todas. Projeto sem a fase de destino → aviso (422). `Projects::moveTo` e `POST /projetos/{id}/fase`. O JS do funil serve aos dois quadros (`data-url` no `.board`).
  - **DWG** aceito em briefings e entregáveis (detectado pelo conteúdo, `image/vnd.dwg`), sempre como download. Limite continua 15 MB por arquivo (PHP: `upload_max_filesize=16M`).
  - **Cron** `bin/cron.php` (de hora em hora; só pela linha de comando, com trava contra execução dupla). Avisos no sino que não se repetem (`Notify::once`): resumo das tarefas do dia a partir das 7h; fase que vence hoje/amanhã ou atrasada (para o responsável do projeto, ou as sócias se não houver); proposta sem resposta que vence hoje/amanhã ou venceu ontem. Limpa `login_attempts`, `api_rate` e notificações lidas há mais de 180 dias.
    - **Para ligar na Hostinger:** hPanel → Avançado → Cron Jobs → comando `php /home/<usuário>/domains/crm.liprojetos.com.br/bin/cron.php` (ajustar ao caminho real), a cada hora.
  - **Ao vivo na tela:** `GET /api/pulse` devolve não lidas e tarefas atrasadas/hoje; o `app.js` consulta a cada minuto (só com a aba visível) e atualiza o sino, os contadores do menu e o título da aba.
  - **Atenção:** projetos de clientes sem responsável ficam sem responsável. Defina um em "Editar projeto".
- **Formulário do site ligado ao CRM (23/11/2026):** `site.config.js` com `crm.endpoint = https://crm.liprojetos.com.br` e a `siteKey` = `SITE_API_KEY` do `.env.production`. `SITE_ORIGIN` agora aceita lista: `https://liprojetos.com.br,https://www.liprojetos.com.br` (o site abre nos dois, sem redirecionar). Publicado só o `public_html/assets/js/app.bundle.js` do site (backup do anterior no scratchpad). Verificado: as duas origens passam da checagem e chegam à validação dos campos; origem desconhecida → 403.
- **Empresa (nome, logo, cor) e app instalável (23/11/2026):** `/empresa` (admin) grava em `settings` (`company_*`, `brand_*`); logos em `storage/brand` (PNG/JPG/WebP; SVG recusado por segurança). PWA: `/manifest.webmanifest` (nome da empresa), `public/sw.js` (cache só de arquivos estáticos; páginas nunca), `offline.html`, ícones em `public/assets/app/`. O ícone do app continua o "LI" mesmo se o logo mudar.
- **Staging (23/11/2026):** https://crm-staging.liprojetos.com.br — mesmo código, banco próprio (`u886922570_licrmstg`), pasta de arquivos própria (`/home/u886922570/domains/crm-staging.liprojetos.com.br/crm-storage`), raiz `domains/crm-staging.liprojetos.com.br/public_html`.
  - `.env.staging` (fora do git) tem `APP_ENV=staging` e a `SEED_PASSWORD` das contas de demonstração. Com `APP_ENV=staging`: faixa "Ambiente de testes" no topo e **e-mails não são enviados** (`Mail::send` só registra no log).
  - **Dados fictícios:** `bin/seed-demo.php` (24 clientes em todas as etapas, briefings, propostas, 5 projetos com fases, tarefas, horas, despesas, parcelas — 2 vencidas —, entregáveis com link de aprovação e uma revisão extra esperando o custo). Recusa rodar se `APP_ENV` não for staging/local. Telefones falsos `+5500…`, e-mails `@example.com`. `--repair` refaz os dados se uma execução parou no meio.
  - Contas: socia@, comercial@, gestora@, designer@, financeiro@demo.example.com (uma para cada papel).
  - **Bug achado no staging e corrigido também em produção:** o MariaDB da Hostinger abria a conexão em `utf8mb4_general_ci` e as tabelas são `utf8mb4_unicode_ci`; criar ou mover uma oportunidade dava "Illegal mix of collations". `Db::pdo` agora fixa `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`.
  - **Fluxo recomendado:** publicar primeiro no staging, testar, depois na produção.
- **Fase E (revisões e aprovações): feita (23/11/2026).** Migração `010_revisions.sql`.
  - **Rodadas por fase:** lidas da "Política de revisões" da proposta ("Inclui 2 rodadas…"; padrão 2), em `projects.revisions_included`. Contador por fase na ficha do projeto (amarelo no limite, vermelho acima).
  - **Tipos:** revisão (conta rodada), correção (erro nosso, não conta) e mudança de escopo (sempre extra).
  - **Trava:** a rodada além das incluídas e a mudança de escopo nascem "Aguardando aprovação do custo" e não podem começar. A equipe define o valor; o cliente aprova pelo link, ou a equipe registra a aprovação dada por WhatsApp. O valor vira uma parcela no Financeiro (vence em 7 dias).
  - **Link do cliente `/e/{token}`** (enviado por WhatsApp em "Enviar para aprovação"): arquivos mais recentes, "Aprovar" (nome completo + "conferi e aprovo") e "Pedir ajustes" (vira pedido de revisão já classificado e avisa a equipe no sino).
  - **Aprovações** ficam em `approvals` com nome, data, versão do arquivo, IP e se foram pelo link ou registradas pela equipe.
  - **Bug evitado:** em `revision_requests`, só `project_id` tem chave estrangeira. Com SET NULL em fase/entregável/parcela, o MySQL recusava excluir o cliente (cascata por dois caminhos).
  - **Verificado:** `tests/run.php` (5 testes novos), `tests/e2e-revisions.sh`, os e2e das fases anteriores, instalação limpa e a migração a partir do backup; capturas da seção de revisões e da página do cliente no celular.
- **Fase F (portal do cliente): feita (23/11/2026).** Migração `011_client_portal.sql` (também em `schema.sql`).
  - **Acesso:** um por cliente (`client_users`); o login é o e-mail do cadastro. Na ficha do cliente, card "Portal do cliente": "Convidar" abre o WhatsApp com o link `/portal/convite/{token}` (vale 7 dias; só o hash sha256 fica no banco), "Enviar novo convite" serve também para senha esquecida, "Desativar acesso" derruba a sessão aberta. Sem WhatsApp: o link aparece no aviso para copiar. Precisa da permissão `crm` e de ver o cliente.
  - **Sessão separada:** cookie `li_portal` com path `/portal` (o da equipe é `li_crm`); CSRF próprio. Mesmo limite de tentativas da equipe, contado à parte (`portal:<email>` em `login_attempts`). Senha de 10 a 200 caracteres.
  - **O que o cliente vê** (`/portal`): "O que precisa de você" (entregas para aprovar, custo extra para aprovar, parcelas vencidas ou que vencem em 7 dias), e por projeto: fases (concluída / em andamento / a seguir), entregas já enviadas com os arquivos visíveis (versão mais nova), pagamentos (pago × a pagar, Pix se configurado). Projetos cancelados não aparecem.
  - **Aprovar e comentar** reaproveita o link `/e/{token}` da Fase E (mesmas regras de rodadas e custo extra).
  - **Arquivos** por `/portal/arquivos/{id}`: só de entregáveis do próprio cliente, já enviados e marcados como visíveis. Rascunho não enviado → 404.
  - **Verificado:** `tests/run.php` (2 testes novos), `tests/e2e-portal.sh` (convite só pelo responsável, token não fica em claro, e-mail de outro cliente recusado, senha curta/diferente 422, sem CSRF 403, convite usado 404, cliente não vê projeto nem arquivo do outro, arquivo não enviado 404, sessões separadas, bloqueio após 5 tentativas, desativar derruba a sessão, zero avisos do PHP) e os e2e das fases A–E e G; capturas em 1440 e 390 px. O portal usa o tema da marca (as páginas do cliente não têm tema escuro, como `/p/` e `/e/`).
  - **Para publicar:** `php bin/migrate.php` no staging e depois na produção.
- **Anti-calote (23/11/2026).** Migração `012_payment_hold.sql` (`projects.hold_reason`).
  - **Originais só com tudo quitado** (`Finance::paidUp`: nenhuma parcela em aberto e o pago cobre o contrato; cancelar parcela não quita). Antes disso, no portal e no link `/e/{token}`, as imagens saem como prévia (`Files::sendPreview`: JPEG de até 1000 px, 70% de qualidade, marca d'água "PRÉVIA · LI PROJETOS" repetida), e PDF, DWG e HEIC dão 403 com "Liberado após a quitação". O original nunca sai do servidor. A equipe continua vendo tudo.
  - **Pausa automática:** o cron pausa o projeto ativo com parcela 7+ dias atrasada (`hold_reason = 'pagamento'`) e avisa sócias e financeiro. Pausado assim, a equipe não consegue enviar novas entregas, e o portal mostra "Projeto pausado por pagamento em atraso". Registrar o pagamento retoma na hora; estornar volta a travar.
  - **Verificado:** `tests/run.php` (2 testes novos), `tests/e2e-anticalote.sh` (prévia de 1000 px, PDF 403, 6 dias não pausa, 7 pausa, envio bloqueado, pagar a 1ª retoma mas não libera, cancelar não quita, quitado = original idêntico, estorno trava de novo) e os e2e de portal, revisões, financeiro e projetos.
  - **Recomendação para o contrato:** a última parcela vence antes da entrega final (ex.: "40% na assinatura, 40% no anteprojeto, 20% antes da entrega"), e a cláusula de que os arquivos editáveis e em alta resolução são entregues após a quitação.
- **Perfil completo do cliente (23/11/2026).** Migração `013_client_profile.sql`.
  - **Campos novos** (todos opcionais, em seções no formulário): CPF ou CNPJ (validado pelos dígitos verificadores e guardado formatado), RG, endereço de cobrança (CEP, rua, número, complemento, bairro, UF; a cidade é o campo que já existia), imóvel (endereço se diferente, tipo, situação, condomínio), nascimento, profissão, cônjuge/decisor com WhatsApp, canal e horário preferidos, como nos conheceu.
  - `Clients::validateProfile` + `saveProfile`; a API do site só usa `validate`, então um lead novo nunca apaga o perfil.
  - **Ficha:** um cartão "Perfil" com contato, documentos e endereço, imóvel e origem; aviso "Falta para o contrato" (CPF/CNPJ, endereço, e-mail) com link para completar.
  - **Histórico de pagamento** (`Finance::payerSummary`): selo para toda a equipe (Bom pagador, Pagou com atraso, Em atraso, Inadimplente, Sem histórico); valores (contratado, pago, em aberto, vencido, parcelas pagas com atraso) só para quem tem `finance`.
  - **Verificado:** `tests/run.php` (5 testes novos), `tests/e2e-profile.sh` (CPF inválido 422 mantendo os valores, todos os campos salvos e mostrados, edição troca para CNPJ, zero avisos do PHP); capturas da ficha e do formulário.
- **Extras do perfil e do anti-calote (23/11/2026):**
  - **Endereço pelo CEP:** ao digitar 8 números no CEP, o formulário preenche rua, bairro, cidade e UF (só campos vazios). Passa pelo servidor (`GET /api/cep/{cep}`, `ClientController::cep`, ViaCEP com timeout de 4 s) porque a CSP não deixa o navegador chamar outro site. Só o CEP é enviado. Fora do ar → o usuário digita.
  - **Nova proposta para cliente com atraso:** aviso vermelho (inadimplente, 7+ dias) ou amarelo (em atraso / já pagou com atraso) no topo do formulário, com o valor vencido.
  - **Verificado:** `e2e-profile.sh` (CEP real, inexistente 404, curto 422, sem login 303) e `e2e-anticalote.sh` (aviso com o valor vencido).
- **Contrato e revisão de textos (23/11/2026).** Migração `014_texts_review.sql`.
  - **Última parcela antes da entrega:** ao aceitar, a última parcela vence quando começa a fase final (`Projects::beforeFinal`: fim da penúltima fase; com uma fase só, no dia da entrega), descrita como "Antes da entrega final". Nenhuma parcela intermediária vence depois dela.
  - **Cláusula fixa** em toda proposta (página e PDF), em "Pagamento e entrega dos arquivos" (`Finance::DELIVERY_CLAUSE`): última parcela antes da entrega final; arquivos finais (editáveis, alta resolução, PDF e DWG) só após a quitação; prévias no portal até lá; 7+ dias de atraso pausa o projeto e os prazos voltam a contar a partir do pagamento (ajustar as datas das fases à mão ao retomar). O cliente aceita com "li e concordo".
  - **Forma de pagamento** continua preenchida por proposta (sem padrão); a dica do campo sugere "40% na assinatura, 40% na aprovação do anteprojeto e 20% antes da entrega final".
  - **Textos revisados** dos modelos de proposta (exclusões mais claras: engenharia, topografia, taxas, compra de móveis, medição no local, desenhos técnicos; entregáveis por etapa na arquitetura; resumo escrito na consultoria) e 6 perguntas novas no briefing (topografia, planta do imóvel, localização, fotos antes da consultoria, área e arquivos DWG/PDF no detalhamento). A migração só troca texto que ainda está como o da instalação: o que as sócias editaram fica. **As sócias ainda devem ler** em Preços → Modelos e Preços → Briefing.
  - **Verificado:** `tests/run.php` (3 testes novos), e2e de todas as fases, instalação limpa pelo `schema.sql` (5/5 modelos e 5/5 briefings atualizados), cláusula na página e no texto do PDF.
- **Roteiro da reunião inicial (23/11/2026).** Migração `015_meeting_script.sql` (`briefings.kind`). Fonte: documento "LI Projetos — Estratégia de Posicionamento e Aumento de Rentabilidade" (23/11/2026), seção 15.
  - Na oportunidade: "Roteiro da reunião" cria um briefing `kind = 'reuniao'` com o modelo "Reunião inicial": blocos com tempo (abertura 0–3, imóvel e momento 3–8, rotina e prioridades 8–15, segurança e investimento 15–23, síntese 23–30), perguntas B01–B24 do documento, falas sugeridas e o "Registro após a conversa". Uma por oportunidade (criar de novo abre a mesma).
  - É só da equipe: sem botão de envio, `/briefings/{id}/enviar` → 400, link público → 404. O briefing por serviço continua sendo o que o cliente responde (detalhes técnicos).
  - Tipos novos no modelo: `titulo` (seção) e `roteiro` (fala ou dica, sem resposta). O modelo é editável em Preços → Briefing.
  - **Verificado:** `tests/run.php` (1 teste novo), `e2e-briefing.sh` (37 campos, 6 blocos, 4 falas, sem envio, 404 público), captura da tela.
  - **Ainda não aplicado do documento** (decisão das sócias): mensagem de envio da proposta (seção 16), registro de objeção/desconto/motivo nas propostas e o painel de rentabilidade (seção 11, entra na fase H).
- **Publicado no staging e na produção (23/11/2026):** portal, anti-calote, perfil, contrato, textos e roteiro da reunião (migrações 011–015).
  - **`bin/backup.php`:** `mysqldump | gzip` em `STORAGE_DIR/backups` (fora do site), guarda os 10 mais recentes; senha por `MYSQL_PWD`, nunca no cron. Testado no staging.
  - **Como migrar em produção:** publicar o pacote e criar um cron temporário `php bin/backup.php && php bin/migrate.php` (a migração só roda se o backup der certo); ler a saída e apagar o cron. Assim foi feito em 23/11 (011–015 aplicadas).
  - Permissão das ferramentas da Hostinger liberada em `~/Desktop/Website/.claude/settings.local.json` (pedido do Melqui).
  - **Staging não tem o cron de hora em hora** (só a produção): lá os projetos não pausam sozinhos por atraso.
- **Fase G (financeiro e cobrança): feita (23/11/2026), adiantada a pedido.** Migração `009_finance.sql`.
  - **Sem API de pagamento** (decisão do Melqui): a chave Pix vai na mensagem de cobrança e o pagamento é registrado à mão, com a forma (Pix, cartão, transferência, boleto, dinheiro).
  - **Parcelas nascem do aceite**, a partir do texto "Forma de pagamento" da proposta: "50% + 50%" (a última vence na entrega do projeto), "3x" (a cada 30 dias), "30% / 40% / 30%". Sem regra legível: pagamento único. Editáveis; aviso quando a soma difere do contrato.
  - **/financeiro** (admin e financeiro): Vencidas, A receber, Recebidas; totais de vencido, a vencer em 7 dias, a receber e recebido no mês. Botões Cobrar (WhatsApp com a mensagem pronta), E-mail e Recebi. Contador vermelho no menu.
  - **Nada é apagado:** pagamento errado → Estornar; parcela errada → Cancelar. Tudo no `audit_log` e no histórico do cliente (tipo "cobrança").
  - **Cron:** avisa o financeiro e as sócias 3 dias antes, no dia e com 1, 7, 15 e 30 dias de atraso. A cobrança ao cliente continua sendo um clique (não sai sozinha).
  - **Margem por projeto:** contrato − despesas − horas × custo da hora (configurável em /financeiro).
  - **`bin/migrate.php`** aplica migrações pendentes (tabela `schema_migrations`). Em produção, a primeira vez é com `--baseline=008`.
  - **Verificado:** `tests/run.php` (10 testes novos), `tests/e2e-finance.sh` e os e2e das fases anteriores; capturas de /financeiro e do cartão no projeto.
- **Produção na Hostinger (22/11/2026):** https://crm.liprojetos.com.br (conta `u886922570`, raiz `public_html/crm`).
  - Publicação por pacote `.zip` (API da Hostinger): arquivos do git + `vendor/` + `.env.production` renomeado para `.env`. **Cada publicação apaga a pasta do site**; por isso os arquivos enviados ficam em `STORAGE_DIR=/home/u886922570/domains/liprojetos.com.br/crm-storage`, fora dela.
  - `.htaccess` na raiz manda tudo para `public/` e bloqueia `.env`, `.sql`, `.md` etc.
  - Banco `u886922570_licrm` (host `localhost`), criado com `bin/install.php` (só roda em banco vazio).
  - **PHP no cron:** `/usr/bin/php` é 7.4. Usar sempre `/opt/alt/php83/usr/bin/php`. A Hostinger roda o comando dentro de `timeout`, então variáveis vão com `/usr/bin/env VAR=x`.
  - Cron de hora em hora: `/opt/alt/php83/usr/bin/php /home/u886922570/domains/liprojetos.com.br/public_html/crm/bin/cron.php`.
  - Admin: `larissa@liprojetos.com.br`. E-mail de envio: `propostas@liprojetos.com.br` (smtp.hostinger.com, 465/ssl).
  - `.env.production` só existe no computador do Melqui (fora do git). Sem ele não dá para publicar de novo.
- Migrações: em banco já criado, rodar cada `database/migrations/*.sql` em ordem. O `schema.sql` já inclui todas.
- O schema já tem as tabelas de todas as fases: clients, client_events, price_items (semeada), proposals, proposal_items, site_events, api_rate.

## Próximas fases (ordem do plano)
2. ~~Clientes e funil~~ (feita)
   - CRUD com validação (WhatsApp no formato +55), etapas, motivo de perda e linha do tempo (`client_events`).
   - Filtros e busca, exportação e exclusão (LGPD).
   - Usar `Auth::canSeeClient` em todo acesso e **testar o 403**.
3. ~~Integração com o site~~ (feita) (`~/Desktop/Website`)
   - Endpoints: `POST /api/lead` e `POST /api/event`.
   - Segurança: origem igual a `SITE_ORIGIN`, cabeçalho com `SITE_API_KEY`, honeypot, limite de requisições pela tabela `api_rate`, validação dos campos.
   - No site: enviar em paralelo nos pontos que disparam `li:lead` (`src/modules/analytics.js`, `contact-form.js`, `meeting-scheduler.js`), depois `node scripts/build.js` e publicar por FTP. Incluir texto de consentimento LGPD.
4. ~~Propostas~~ (feita)
   - Itens a partir de `price_items`: faixas de estudo, mínimo de R$ 3.500 no projeto, abatimento da etapa mais alta paga (`credit_rank`).
   - PDF com Dompdf e link público com `public_token`.
   - Status: rascunho → enviada → vista → aceita/recusada.
   - Envio por PHPMailer e por wa.me.
6. ~~Painel~~ (feito): leads por dia e por origem, cliques no WhatsApp, conversão por etapa, tempo até o primeiro contato, valor de propostas enviadas e aceitas, ticket médio.
5. **DropeStore**: só com a documentação da API e as credenciais (`DROPESTORE_URL`, `DROPESTORE_TOKEN`, `DROPESTORE_INSTANCE` no `.env`).

## Riscos
- **API não oficial do WhatsApp:** pode levar ao **bloqueio do número**. Usar número dedicado, volume baixo e manter o fallback wa.me.
- **Hospedagem compartilhada:** sem fila de tarefas; envios síncronos com timeout curto; configurar SPF/DKIM do e-mail no hPanel.

## Contexto do site (para integração e preços)
- Site estático em `~/Desktop/Website`, publicado por FTP na Hostinger (`public_html`). Domínio liprojetos.com.br: o DNS aponta para a Hostinger, mas faltava o registro A apontando para 46.202.199.58.
- Preços oficiais: planta 3D R$ 490; estudo preliminar R$ 990 (até 100 m²), R$ 1.690 (até 200 m²), R$ 2.490 (até 300 m²), acima disso sob consulta; projeto R$ 100/m² com mínimo de R$ 3.500; consultoria R$ 250/h; 2 rodadas de revisão por etapa; só a etapa paga mais alta é abatida do projeto.
- WhatsApp da empresa: +55 61 99241-4304. Instagram: @li.projetos.
