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
