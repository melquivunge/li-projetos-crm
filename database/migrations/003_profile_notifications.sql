-- 003: perfil do usuário (foto, telefone, cargo, bio, tema) e notificações
ALTER TABLE users
  ADD COLUMN phone VARCHAR(20) NULL AFTER email,
  ADD COLUMN job_title VARCHAR(80) NULL AFTER phone,
  ADD COLUMN bio VARCHAR(500) NULL AFTER job_title,
  ADD COLUMN avatar VARCHAR(80) NULL AFTER bio,
  ADD COLUMN theme ENUM('light','dark','brand') NOT NULL DEFAULT 'brand' AFTER avatar;

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
