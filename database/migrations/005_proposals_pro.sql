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
