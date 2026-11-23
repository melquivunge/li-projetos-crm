-- 018: auditoria de segurança. session_version derruba sessões antigas (troca de senha, desativação, papéis, convite);
-- contas antigas sem papel explícito ganham os papéis que já tinham implicitamente (o sistema passa a negar quem não tem papel).
ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE client_users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1;
CREATE TEMPORARY TABLE legacy_users AS SELECT u.id, u.role FROM users u WHERE NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id);
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, 'admin' FROM legacy_users WHERE role = 'admin';
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, 'comercial' FROM legacy_users WHERE role <> 'admin';
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, 'designer' FROM legacy_users WHERE role <> 'admin';
DROP TEMPORARY TABLE legacy_users;
