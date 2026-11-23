-- 017: mídia no WhatsApp (áudio, imagem, vídeo e documento). O arquivo fica guardado no servidor (o link da DROPE vale 2 dias).
ALTER TABLE wa_messages
  ADD COLUMN media_path VARCHAR(120) NULL AFTER kind,
  ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_path,
  ADD COLUMN media_name VARCHAR(180) NULL AFTER media_mime;
