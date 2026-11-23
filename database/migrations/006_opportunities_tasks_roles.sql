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
