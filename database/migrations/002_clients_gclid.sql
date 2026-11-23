-- 002: guarda o gclid do Google Ads para importar contratos como conversões off-line
ALTER TABLE clients ADD COLUMN gclid VARCHAR(255) NULL AFTER origin;
