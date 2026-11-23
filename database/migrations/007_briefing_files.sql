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
