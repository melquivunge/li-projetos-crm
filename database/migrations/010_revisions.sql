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
