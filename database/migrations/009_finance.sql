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
