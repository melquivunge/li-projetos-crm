SET NAMES utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(20) NULL,
  job_title VARCHAR(80) NULL,
  bio VARCHAR(500) NULL,
  avatar VARCHAR(80) NULL,
  theme ENUM('light','dark','brand') NOT NULL DEFAULT 'brand',
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','equipe') NOT NULL DEFAULT 'equipe',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, attempted_at), INDEX (ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (entity, entity_id)
) ENGINE=InnoDB;

CREATE TABLE clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  whatsapp VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  city VARCHAR(120) NULL,
  service VARCHAR(80) NULL,
  area_m2 DECIMAL(8,2) NULL,
  stage ENUM('novo','contato','reuniao','proposta','fechado','perdido') NOT NULL DEFAULT 'novo',
  lost_reason VARCHAR(255) NULL,
  next_action VARCHAR(160) NULL,
  next_action_at DATE NULL,
  stage_changed_at DATETIME NULL,
  origin VARCHAR(160) NULL,
  gclid VARCHAR(255) NULL,
  owner_id INT UNSIGNED NULL,
  consent_at DATETIME NULL,
  first_contact_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (stage), INDEX (owner_id), INDEX (created_at), INDEX (next_action_at),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  type ENUM('nota','etapa','whatsapp','email','proposta','sistema') NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id, created_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE price_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(160) NOT NULL,
  unit ENUM('fixo','m2','hora') NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  min_total DECIMAL(10,2) NULL,
  max_area DECIMAL(8,2) NULL,
  credit_rank TINYINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO price_items (code,label,unit,price,min_total,max_area,credit_rank) VALUES
('planta3d','Planta 3D humanizada do apartamento','fixo',490,NULL,NULL,1),
('estudo100','Estudo preliminar (até 100 m²)','fixo',990,NULL,100,2),
('estudo200','Estudo preliminar (101 a 200 m²)','fixo',1690,NULL,200,2),
('estudo300','Estudo preliminar (201 a 300 m²)','fixo',2490,NULL,300,2),
('arquitetura','Projeto de arquitetura','m2',100,3500,NULL,0),
('interiores','Projeto de design de interiores','m2',100,3500,NULL,0),
('consultoria','Consultoria (sessão de 1 hora)','hora',250,NULL,NULL,0);

CREATE TABLE proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  status ENUM('rascunho','enviada','vista','aceita','recusada') NOT NULL DEFAULT 'rascunho',
  credit DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  valid_until DATE NULL,
  notes TEXT NULL,
  public_token CHAR(64) NOT NULL UNIQUE,
  sent_at DATETIME NULL, viewed_at DATETIME NULL, decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id), INDEX (status),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE proposal_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE site_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(40) NOT NULL,
  location VARCHAR(60) NULL,
  origin VARCHAR(160) NULL,
  client_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (event, created_at)
) ENGINE=InnoDB;

CREATE TABLE api_rate (
  ip VARCHAR(45) NOT NULL,
  bucket VARCHAR(20) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (ip, bucket)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body VARCHAR(255) NOT NULL,
  link VARCHAR(120) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, read_at, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 005: proposta profissional (escopo, entregáveis, exclusões, cronograma, pagamento, versões, aceite registrado)
ALTER TABLE proposals
  ADD COLUMN project_summary TEXT NULL AFTER notes,
  ADD COLUMN scope TEXT NULL AFTER project_summary,
  ADD COLUMN deliverables TEXT NULL AFTER scope,
  ADD COLUMN exclusions TEXT NULL AFTER deliverables,
  ADD COLUMN payment_terms TEXT NULL AFTER exclusions,
  ADD COLUMN revision_policy TEXT NULL AFTER payment_terms,
  ADD COLUMN version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER revision_policy,
  ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER version,
  MODIFY status ENUM('rascunho','enviada','vista','aceita','recusada','substituida') NOT NULL DEFAULT 'rascunho';
ALTER TABLE proposal_items ADD COLUMN code VARCHAR(40) NULL AFTER proposal_id;

CREATE TABLE proposal_schedule (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_id BIGINT UNSIGNED NOT NULL,
  stage VARCHAR(120) NOT NULL,
  duration_text VARCHAR(80) NOT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE proposal_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(80) NOT NULL UNIQUE,
  scope TEXT NOT NULL,
  deliverables TEXT NOT NULL,
  exclusions TEXT NOT NULL,
  revision_policy TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aceites e aprovações: nunca apagados (trilha de auditoria)
CREATE TABLE approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  decision VARCHAR(20) NOT NULL,
  name VARCHAR(160) NOT NULL,
  user_id INT UNSIGNED NULL,
  ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modelos iniciais a partir das descrições do site. As sócias revisam em /precos/modelos.
INSERT INTO proposal_templates (service, scope, deliverables, exclusions, revision_policy) VALUES
('Projetos de Arquitetura',
 'Projeto de arquitetura da sua casa nova ou reforma: estudo do terreno, planta, fachadas e projeto para aprovação na prefeitura (administração regional) e no condomínio.',
 'Estudo de insolação e ventilação\nPlantas, cortes e fachadas\nProjeto legal para o alvará, inclusive em condomínios regularizados\nMaquete 3D para aprovar antes da obra',
 'Execução, gerenciamento ou acompanhamento de obra',
 'Inclui 2 rodadas de revisão em cada etapa. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.'),
('Design de Interiores',
 'Projeto de interiores dos ambientes combinados: layout, escolha de materiais, cores e iluminação, e marcenaria desenhada sob medida para o seu espaço.',
 'Layout e especificação de materiais\nProjeto de marcenaria sob medida\nIluminação e cores\nRenders realistas de cada ambiente',
 'Execução, gerenciamento ou acompanhamento de obra',
 'Inclui 2 rodadas de revisão em cada etapa. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.'),
('Plantas Baixas',
 'Planta de layout com cotas e áreas e planta 3D humanizada do seu imóvel, para construir, reformar ou entender melhor o espaço.',
 'Planta de layout 2D com cotas e áreas\nPlanta 3D humanizada\nPortas, janelas e mobiliário indicados',
 'Execução, gerenciamento ou acompanhamento de obra',
 'Inclui 2 rodadas de revisão. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.'),
('Consultoria',
 'Sessões por videochamada para resolver dúvidas pontuais de layout, materiais, compra de imóvel ou reforma, sem contratar o projeto completo.',
 'Análise da sua planta, terreno ou imóvel\nRecomendações práticas para o seu caso\nAtendimento online, no seu horário',
 'Desenvolvimento de projeto completo\nExecução, gerenciamento ou acompanhamento de obra',
 'Cada sessão tem 1 hora. Novas sessões podem ser agendadas a qualquer momento.'),
('Detalhamento Executivo',
 'Pranchas técnicas para a obra: pontos elétricos e hidráulicos, forro, paginação de pisos e detalhamento de marcenaria, para o construtor executar com clareza.',
 'Planta de pontos elétricos e hidráulicos\nPlanta de forro\nPaginação de pisos\nDetalhamento de marcenaria',
 'Execução, gerenciamento ou acompanhamento de obra',
 'Inclui 2 rodadas de revisão em cada etapa. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.');
-- 006: oportunidades (vários negócios por cliente), tarefas, papéis acumuláveis, novos tipos de atividade.
-- As colunas antigas clients.next_action/next_action_at ficam (sem uso) para permitir voltar atrás.

CREATE TABLE opportunities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  service VARCHAR(80) NULL,
  estimated_value DECIMAL(10,2) NULL,
  expected_close DATE NULL,
  stage ENUM('novo','qualificado','briefing','proposta','negociacao','ganho','perdido') NOT NULL DEFAULT 'novo',
  lost_reason VARCHAR(255) NULL,
  owner_id INT UNSIGNED NULL,
  stage_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (stage), INDEX (owner_id), INDEX (client_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Etapas novas do funil (documento Li Project): mapeia as antigas
ALTER TABLE clients MODIFY stage ENUM('novo','contato','reuniao','proposta','fechado','perdido','qualificado','briefing','negociacao','ganho') NOT NULL DEFAULT 'novo';
UPDATE clients SET stage = CASE stage WHEN 'contato' THEN 'qualificado' WHEN 'reuniao' THEN 'briefing' WHEN 'fechado' THEN 'ganho' ELSE stage END;
ALTER TABLE clients MODIFY stage ENUM('novo','qualificado','briefing','proposta','negociacao','ganho','perdido') NOT NULL DEFAULT 'novo';

-- Uma oportunidade por cliente existente, com a etapa e o valor da última proposta
INSERT INTO opportunities (client_id, title, service, estimated_value, stage, lost_reason, owner_id, stage_changed_at, created_at)
SELECT c.id, COALESCE(c.service, 'Projeto'), c.service,
       (SELECT p.total FROM proposals p WHERE p.client_id = c.id ORDER BY p.id DESC LIMIT 1),
       c.stage, c.lost_reason, c.owner_id, COALESCE(c.stage_changed_at, c.updated_at), c.created_at
FROM clients c;

ALTER TABLE proposals ADD COLUMN opportunity_id BIGINT UNSIGNED NULL AFTER client_id, ADD INDEX (opportunity_id);
UPDATE proposals p JOIN opportunities o ON o.client_id = p.client_id SET p.opportunity_id = o.id;

CREATE TABLE tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  due_at DATE NOT NULL,
  priority ENUM('baixa','normal','alta') NOT NULL DEFAULT 'normal',
  owner_id INT UNSIGNED NULL,
  client_id BIGINT UNSIGNED NULL,
  opportunity_id BIGINT UNSIGNED NULL,
  done_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (owner_id, done_at, due_at), INDEX (client_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A "próxima ação" de cada cliente vira uma tarefa
INSERT INTO tasks (title, due_at, owner_id, client_id, opportunity_id, created_at)
SELECT c.next_action, c.next_action_at, c.owner_id, c.id, (SELECT o.id FROM opportunities o WHERE o.client_id = c.id LIMIT 1), NOW()
FROM clients c WHERE c.next_action IS NOT NULL AND c.next_action_at IS NOT NULL;

CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role ENUM('admin','comercial','gestor','designer','financeiro') NOT NULL,
  PRIMARY KEY (user_id, role),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO user_roles (user_id, role) SELECT id, 'admin' FROM users WHERE role = 'admin';
INSERT INTO user_roles (user_id, role) SELECT id, 'comercial' FROM users WHERE role = 'equipe';
INSERT INTO user_roles (user_id, role) SELECT id, 'designer' FROM users WHERE role = 'equipe';

ALTER TABLE client_events MODIFY type ENUM('nota','etapa','whatsapp','email','proposta','sistema','reuniao','ligacao') NOT NULL;
-- 007: briefing por serviço (perguntas editáveis), briefing por oportunidade com link para o cliente, arquivos.
CREATE TABLE briefing_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(80) NOT NULL UNIQUE,
  questions TEXT NOT NULL,            -- uma pergunta por linha: Rótulo | tipo | opções separadas por ;
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE briefings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  opportunity_id BIGINT UNSIGNED NULL,
  service VARCHAR(80) NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  questions TEXT NOT NULL,            -- cópia das perguntas no momento da criação (o modelo pode mudar depois)
  answers JSON NULL,
  status ENUM('rascunho','enviado','respondido','validado') NOT NULL DEFAULT 'rascunho',
  public_token CHAR(64) NOT NULL UNIQUE,
  created_by INT UNSIGNED NULL,
  sent_at DATETIME NULL, answered_at DATETIME NULL, validated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (client_id), INDEX (opportunity_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(30) NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size INT UNSIGNED NOT NULL,
  path VARCHAR(120) NOT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by INT UNSIGNED NULL,
  from_client TINYINT(1) NOT NULL DEFAULT 0,
  visible_to_client TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (owner_type, owner_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO briefing_templates (service, questions) VALUES
('Projetos de Arquitetura', 'Tipo de projeto | select | Casa nova;Reforma;Ampliação;Regularização\nOnde fica o terreno ou imóvel (bairro e cidade) | text\nÁrea do terreno (m²) | number\nÁrea construída pretendida (m²) | number\nO terreno é em condomínio? | select | Sim, regularizado;Sim, em regularização;Não\nQuantas pessoas vão morar e quem são | textarea\nAmbientes que não podem faltar | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nOrçamento previsto para a obra | text\nPrazo desejado para começar a obra | text\nRestrições do condomínio ou do terreno que já conhecem | textarea\nAlgo mais que devemos saber | textarea'),
('Design de Interiores', 'Imóvel | select | Apartamento;Casa;Comercial\nOnde fica (bairro e cidade) | text\nÁrea (m²) | number\nAmbientes do projeto | textarea\nO imóvel é novo ou já está mobiliado? | select | Novo, vazio;Já mobiliado, vamos aproveitar peças;Já mobiliado, vamos trocar tudo\nQuem vai usar os ambientes e como é a rotina | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nCores e materiais que gostam ou não gostam | textarea\nPrecisa de home office? | select | Sim;Não\nOrçamento previsto para marcenaria e mobiliário | text\nPrazo desejado para a obra ou a mudança | text\nAlgo mais que devemos saber | textarea'),
('Plantas Baixas', 'Para que é a planta | select | Construir;Reformar;Vender ou alugar;Entender melhor o imóvel\nImóvel | select | Apartamento;Casa;Comercial\nÁrea aproximada (m²) | number\nVocês têm a planta original ou medidas? | select | Tenho a planta original;Tenho medidas;Não tenho\nO que deve aparecer (mobiliário, cotas, áreas) | textarea\nPrazo desejado | text'),
('Consultoria', 'Assunto da consultoria | select | Layout;Materiais e acabamentos;Compra de imóvel;Reforma;Outro\nDescreva a dúvida principal | textarea\nOnde fica o imóvel (bairro e cidade) | text\nMelhores dias e horários para a videochamada | text'),
('Detalhamento Executivo', 'Vocês já têm o projeto de arquitetura ou interiores? | select | Sim, feito pela LI Projetos;Sim, feito por outro profissional;Não\nAmbientes a detalhar | textarea\nO que precisa ser detalhado | textarea\nQuem vai executar a obra (construtor, marceneiro) | text\nPrazo para começar a obra | text');

-- 008: projetos (nascem do aceite da proposta), fases editáveis, entregáveis com arquivos, tarefas do projeto, horas.
CREATE TABLE projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  opportunity_id BIGINT UNSIGNED NULL,
  proposal_id BIGINT UNSIGNED NULL UNIQUE,   -- um projeto por proposta aceita
  title VARCHAR(160) NOT NULL,
  status ENUM('ativo','pausado','concluido','cancelado') NOT NULL DEFAULT 'ativo',
  manager_id INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  due_date DATE NULL,
  done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status), INDEX (manager_id), INDEX (client_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL,
  FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE SET NULL,
  FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_phases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  due_date DATE NULL,
  status ENUM('pendente','andamento','concluida') NOT NULL DEFAULT 'pendente',
  done_at DATETIME NULL,
  INDEX (project_id, sort),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tasks
  ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER opportunity_id,
  ADD COLUMN phase_id BIGINT UNSIGNED NULL AFTER project_id,
  ADD COLUMN blocked_by BIGINT UNSIGNED NULL AFTER phase_id,
  ADD INDEX (project_id),
  ADD FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  ADD FOREIGN KEY (phase_id) REFERENCES project_phases(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (blocked_by) REFERENCES tasks(id) ON DELETE SET NULL;

CREATE TABLE deliverables (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  phase_id BIGINT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  status ENUM('pendente','producao','enviado','aprovado') NOT NULL DEFAULT 'pendente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (phase_id) REFERENCES project_phases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE time_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NULL,
  minutes SMALLINT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  note VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id, date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 009: financeiro e cobrança. Parcelas por projeto (nascem da proposta aceita), despesas, custo-hora para a margem.
-- Sem API de pagamento: chave Pix nas mensagens de cobrança e pagamento registrado à mão (Pix, cartão etc.).
-- Nada é apagado: parcela errada vira "cancelada" e toda mudança vai para o audit_log.
ALTER TABLE projects ADD COLUMN contract_value DECIMAL(10,2) NULL AFTER title;
UPDATE projects p JOIN proposals pr ON pr.id = p.proposal_id SET p.contract_value = pr.total;

CREATE TABLE installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  number SMALLINT UNSIGNED NOT NULL,
  description VARCHAR(120) NULL,
  amount DECIMAL(10,2) NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('aberta','paga','cancelada') NOT NULL DEFAULT 'aberta',
  paid_at DATE NULL,
  paid_amount DECIMAL(10,2) NULL,
  method ENUM('pix','cartao','transferencia','boleto','dinheiro') NULL,
  last_reminder_at DATETIME NULL,
  reminders SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status, due_date), INDEX (project_id), INDEX (client_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  category ENUM('impressao','deslocamento','software','terceiros','outros') NOT NULL DEFAULT 'outros',
  description VARCHAR(160) NULL,
  date DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  k VARCHAR(40) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO settings (k, v) VALUES ('hourly_cost', '0'), ('pix_key', ''), ('pix_name', '');

ALTER TABLE client_events MODIFY type ENUM('nota','etapa','whatsapp','email','proposta','sistema','reuniao','ligacao','cobranca') NOT NULL;

-- 010: revisões e aprovações. Rodadas incluídas por fase (da proposta), revisão extra/mudança de escopo só começa
-- com o custo aprovado, aprovação de entregável pelo cliente (link /e/{token}) ou registrada pela equipe.
ALTER TABLE projects ADD COLUMN revisions_included SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER contract_value;

ALTER TABLE deliverables
  MODIFY status ENUM('pendente','producao','enviado','ajustes','aprovado') NOT NULL DEFAULT 'pendente',
  ADD COLUMN public_token CHAR(64) NULL UNIQUE AFTER status,
  ADD COLUMN sent_at DATETIME NULL AFTER public_token,
  ADD COLUMN approved_at DATETIME NULL AFTER sent_at;

CREATE TABLE revision_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  phase_id BIGINT UNSIGNED NULL,
  deliverable_id BIGINT UNSIGNED NULL,
  type ENUM('correcao','revisao','escopo') NOT NULL,
  is_extra TINYINT(1) NOT NULL DEFAULT 0,          -- revisão além das incluídas, ou mudança de escopo
  round SMALLINT UNSIGNED NULL,                    -- nº da rodada na fase (só "revisao")
  description TEXT NOT NULL,
  from_client TINYINT(1) NOT NULL DEFAULT 0,
  author_name VARCHAR(160) NULL,
  created_by INT UNSIGNED NULL,
  due_date DATE NULL,
  status ENUM('aguardando_aprovacao','aberta','andamento','concluida','recusada') NOT NULL DEFAULT 'aberta',
  extra_cost DECIMAL(10,2) NULL,
  cost_approved_at DATETIME NULL,
  installment_id BIGINT UNSIGNED NULL,           -- sem FK: cliente excluído apaga parcelas e revisões por caminhos diferentes
  done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (project_id, phase_id), INDEX (deliverable_id), INDEX (status),
  -- só project_id tem FK: SET NULL em fase/entregável junto com o CASCADE do projeto faz o MySQL recusar a exclusão do cliente
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 011: portal do cliente. Um acesso por cliente; convite por link (guardado só o hash do token, vale 7 dias).
CREATE TABLE client_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  invite_hash CHAR(64) NULL UNIQUE,
  invite_expires DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 012: anti-calote. Projeto pausado automaticamente por pagamento em atraso (volta sozinho quando regulariza).
ALTER TABLE projects ADD COLUMN hold_reason VARCHAR(20) NULL AFTER status;

-- 013: perfil completo do cliente: documentos e endereço (contrato e cobrança), imóvel, dados pessoais e preferências.
ALTER TABLE clients
  ADD COLUMN document VARCHAR(18) NULL AFTER email,
  ADD COLUMN rg VARCHAR(20) NULL AFTER document,
  ADD COLUMN address_zip CHAR(9) NULL AFTER city,
  ADD COLUMN address_street VARCHAR(160) NULL AFTER address_zip,
  ADD COLUMN address_number VARCHAR(20) NULL AFTER address_street,
  ADD COLUMN address_extra VARCHAR(80) NULL AFTER address_number,
  ADD COLUMN address_district VARCHAR(80) NULL AFTER address_extra,
  ADD COLUMN address_state CHAR(2) NULL AFTER address_district,
  ADD COLUMN property_address VARCHAR(255) NULL AFTER area_m2,
  ADD COLUMN property_type ENUM('apartamento','casa','comercial','outro') NULL AFTER property_address,
  ADD COLUMN property_condo VARCHAR(120) NULL AFTER property_type,
  ADD COLUMN property_status ENUM('planta','obra','pronto','reforma') NULL AFTER property_condo,
  ADD COLUMN birth_date DATE NULL AFTER property_status,
  ADD COLUMN profession VARCHAR(120) NULL AFTER birth_date,
  ADD COLUMN partner_name VARCHAR(160) NULL AFTER profession,
  ADD COLUMN partner_whatsapp VARCHAR(20) NULL AFTER partner_name,
  ADD COLUMN contact_pref ENUM('whatsapp','ligacao','email') NULL AFTER partner_whatsapp,
  ADD COLUMN contact_time VARCHAR(60) NULL AFTER contact_pref,
  ADD COLUMN referral VARCHAR(160) NULL AFTER contact_time,
  ADD INDEX (document);

-- 014: revisão dos textos dos modelos de proposta e das perguntas do briefing.
-- Só troca o texto que ainda está igual ao da instalação (WHERE com o texto antigo): o que as sócias já editaram fica.
UPDATE proposal_templates SET scope = 'Projeto de arquitetura da sua casa nova, reforma ou ampliação: estudo do terreno e do programa da família, planta, cortes e fachadas, e o projeto legal para aprovação na administração regional ou na prefeitura e no condomínio.' WHERE service = 'Projetos de Arquitetura' AND scope = 'Projeto de arquitetura da sua casa nova ou reforma: estudo do terreno, planta, fachadas e projeto para aprovação na prefeitura (administração regional) e no condomínio.';
UPDATE proposal_templates SET deliverables = 'Estudo de insolação e ventilação\nEstudo preliminar com a planta e a volumetria\nAnteprojeto com plantas, cortes e fachadas\nProjeto legal para o alvará, inclusive em condomínios regularizados\nMaquete 3D para aprovar antes da obra' WHERE service = 'Projetos de Arquitetura' AND deliverables = 'Estudo de insolação e ventilação\nPlantas, cortes e fachadas\nProjeto legal para o alvará, inclusive em condomínios regularizados\nMaquete 3D para aprovar antes da obra';
UPDATE proposal_templates SET exclusions = 'Execução, gerenciamento ou acompanhamento de obra\nProjetos de engenharia (estrutural, elétrico, hidráulico e outros complementares)\nLevantamento topográfico e sondagem do terreno\nTaxas, emolumentos e custos de aprovação nos órgãos e no condomínio' WHERE service = 'Projetos de Arquitetura' AND exclusions = 'Execução, gerenciamento ou acompanhamento de obra';
UPDATE proposal_templates SET scope = 'Projeto de interiores dos ambientes combinados: layout, escolha de materiais, cores e iluminação, e marcenaria desenhada sob medida para o seu espaço e a sua rotina.' WHERE service = 'Design de Interiores' AND scope = 'Projeto de interiores dos ambientes combinados: layout, escolha de materiais, cores e iluminação, e marcenaria desenhada sob medida para o seu espaço.';
UPDATE proposal_templates SET deliverables = 'Layout com a disposição do mobiliário\nEspecificação de materiais, cores e acabamentos\nProjeto de iluminação decorativa\nProjeto de marcenaria sob medida\nRenders realistas de cada ambiente' WHERE service = 'Design de Interiores' AND deliverables = 'Layout e especificação de materiais\nProjeto de marcenaria sob medida\nIluminação e cores\nRenders realistas de cada ambiente';
UPDATE proposal_templates SET exclusions = 'Execução, gerenciamento ou acompanhamento de obra\nCompra de móveis, materiais e objetos de decoração\nPranchas executivas de elétrica, hidráulica, forro e pisos (contratadas à parte no Detalhamento Executivo)' WHERE service = 'Design de Interiores' AND exclusions = 'Execução, gerenciamento ou acompanhamento de obra';
UPDATE proposal_templates SET scope = 'Planta de layout com cotas e áreas e planta 3D humanizada do seu imóvel, a partir da planta original ou das medidas que você enviar, para construir, reformar, vender ou entender melhor o espaço.' WHERE service = 'Plantas Baixas' AND scope = 'Planta de layout com cotas e áreas e planta 3D humanizada do seu imóvel, para construir, reformar ou entender melhor o espaço.';
UPDATE proposal_templates SET exclusions = 'Medição no local (usamos a planta ou as medidas que você enviar)\nProjeto legal para aprovação em órgãos ou condomínio\nExecução, gerenciamento ou acompanhamento de obra' WHERE service = 'Plantas Baixas' AND exclusions = 'Execução, gerenciamento ou acompanhamento de obra';
UPDATE proposal_templates SET deliverables = 'Análise prévia da planta, das fotos ou do terreno que você enviar\nRecomendações práticas para o seu caso\nResumo por escrito com os pontos combinados na sessão\nAtendimento online, no seu horário' WHERE service = 'Consultoria' AND deliverables = 'Análise da sua planta, terreno ou imóvel\nRecomendações práticas para o seu caso\nAtendimento online, no seu horário';
UPDATE proposal_templates SET exclusions = 'Desenvolvimento de projeto completo ou desenhos técnicos\nExecução, gerenciamento ou acompanhamento de obra' WHERE service = 'Consultoria' AND exclusions = 'Desenvolvimento de projeto completo\nExecução, gerenciamento ou acompanhamento de obra';
UPDATE proposal_templates SET exclusions = 'Execução, gerenciamento ou acompanhamento de obra\nProjetos de engenharia assinados por engenheiro (estrutural, elétrico e hidráulico)\nCompra de materiais' WHERE service = 'Detalhamento Executivo' AND exclusions = 'Execução, gerenciamento ou acompanhamento de obra';
UPDATE briefing_templates SET questions = 'Tipo de projeto | select | Casa nova;Reforma;Ampliação;Regularização\nOnde fica o terreno ou imóvel (bairro e cidade) | text\nÁrea do terreno (m²) | number\nÁrea construída pretendida (m²) | number\nO terreno é em condomínio? | select | Sim, regularizado;Sim, em regularização;Não\nVocês já têm o levantamento topográfico do terreno? | select | Sim;Não;Não sei\nQuantas pessoas vão morar e quem são | textarea\nAmbientes que não podem faltar | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nOrçamento previsto para a obra | text\nPrazo desejado para começar a obra | text\nRestrições do condomínio ou do terreno que já conhecem | textarea\nAlgo mais que devemos saber | textarea' WHERE service = 'Projetos de Arquitetura' AND questions = 'Tipo de projeto | select | Casa nova;Reforma;Ampliação;Regularização\nOnde fica o terreno ou imóvel (bairro e cidade) | text\nÁrea do terreno (m²) | number\nÁrea construída pretendida (m²) | number\nO terreno é em condomínio? | select | Sim, regularizado;Sim, em regularização;Não\nQuantas pessoas vão morar e quem são | textarea\nAmbientes que não podem faltar | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nOrçamento previsto para a obra | text\nPrazo desejado para começar a obra | text\nRestrições do condomínio ou do terreno que já conhecem | textarea\nAlgo mais que devemos saber | textarea';
UPDATE briefing_templates SET questions = 'Imóvel | select | Apartamento;Casa;Comercial\nOnde fica (bairro e cidade) | text\nÁrea (m²) | number\nVocês têm a planta do imóvel? | select | Sim, a planta original;Tenho medidas;Não tenho\nAmbientes do projeto | textarea\nO imóvel é novo ou já está mobiliado? | select | Novo, vazio;Já mobiliado, vamos aproveitar peças;Já mobiliado, vamos trocar tudo\nQuem vai usar os ambientes e como é a rotina | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nCores e materiais que gostam ou não gostam | textarea\nPrecisa de home office? | select | Sim;Não\nOrçamento previsto para marcenaria e mobiliário | text\nPrazo desejado para a obra ou a mudança | text\nAlgo mais que devemos saber | textarea' WHERE service = 'Design de Interiores' AND questions = 'Imóvel | select | Apartamento;Casa;Comercial\nOnde fica (bairro e cidade) | text\nÁrea (m²) | number\nAmbientes do projeto | textarea\nO imóvel é novo ou já está mobiliado? | select | Novo, vazio;Já mobiliado, vamos aproveitar peças;Já mobiliado, vamos trocar tudo\nQuem vai usar os ambientes e como é a rotina | textarea\nEstilo que vocês gostam (pode citar referências) | textarea\nCores e materiais que gostam ou não gostam | textarea\nPrecisa de home office? | select | Sim;Não\nOrçamento previsto para marcenaria e mobiliário | text\nPrazo desejado para a obra ou a mudança | text\nAlgo mais que devemos saber | textarea';
UPDATE briefing_templates SET questions = 'Para que é a planta | select | Construir;Reformar;Vender ou alugar;Entender melhor o imóvel\nImóvel | select | Apartamento;Casa;Comercial\nOnde fica (bairro e cidade) | text\nÁrea aproximada (m²) | number\nVocês têm a planta original ou medidas? | select | Tenho a planta original;Tenho medidas;Não tenho\nO que deve aparecer (mobiliário, cotas, áreas) | textarea\nPrazo desejado | text' WHERE service = 'Plantas Baixas' AND questions = 'Para que é a planta | select | Construir;Reformar;Vender ou alugar;Entender melhor o imóvel\nImóvel | select | Apartamento;Casa;Comercial\nÁrea aproximada (m²) | number\nVocês têm a planta original ou medidas? | select | Tenho a planta original;Tenho medidas;Não tenho\nO que deve aparecer (mobiliário, cotas, áreas) | textarea\nPrazo desejado | text';
UPDATE briefing_templates SET questions = 'Assunto da consultoria | select | Layout;Materiais e acabamentos;Compra de imóvel;Reforma;Outro\nDescreva a dúvida principal | textarea\nOnde fica o imóvel (bairro e cidade) | text\nTem planta ou fotos para enviar antes da sessão? | select | Sim;Não\nMelhores dias e horários para a videochamada | text' WHERE service = 'Consultoria' AND questions = 'Assunto da consultoria | select | Layout;Materiais e acabamentos;Compra de imóvel;Reforma;Outro\nDescreva a dúvida principal | textarea\nOnde fica o imóvel (bairro e cidade) | text\nMelhores dias e horários para a videochamada | text';
UPDATE briefing_templates SET questions = 'Vocês já têm o projeto de arquitetura ou interiores? | select | Sim, feito pela LI Projetos;Sim, feito por outro profissional;Não\nAmbientes a detalhar | textarea\nÁrea (m²) | number\nVocês têm os arquivos do projeto (DWG ou PDF)? | select | Sim, DWG;Só PDF;Não\nO que precisa ser detalhado | textarea\nQuem vai executar a obra (construtor, marceneiro) | text\nPrazo para começar a obra | text' WHERE service = 'Detalhamento Executivo' AND questions = 'Vocês já têm o projeto de arquitetura ou interiores? | select | Sim, feito pela LI Projetos;Sim, feito por outro profissional;Não\nAmbientes a detalhar | textarea\nO que precisa ser detalhado | textarea\nQuem vai executar a obra (construtor, marceneiro) | text\nPrazo para começar a obra | text';
