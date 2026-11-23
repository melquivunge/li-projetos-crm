-- 016: WhatsApp no CRM. Uma conversa por número; mensagens chegam pelo webhook do provedor (DropeStore) e saem pela API.
CREATE TABLE wa_chats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(160) NULL,
  client_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  last_preview VARCHAR(160) NULL,
  unread INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (last_message_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wa_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  provider_id VARCHAR(120) NULL UNIQUE,
  from_me TINYINT(1) NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  kind ENUM('texto','imagem','audio','video','documento','outro') NOT NULL DEFAULT 'texto',
  status ENUM('recebida','enviando','enviada','falhou') NOT NULL DEFAULT 'recebida',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (chat_id, id),
  FOREIGN KEY (chat_id) REFERENCES wa_chats(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
