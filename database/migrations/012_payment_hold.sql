-- 012: anti-calote. Projeto pausado automaticamente por pagamento em atraso (volta sozinho quando regulariza).
ALTER TABLE projects ADD COLUMN hold_reason VARCHAR(20) NULL AFTER status;
