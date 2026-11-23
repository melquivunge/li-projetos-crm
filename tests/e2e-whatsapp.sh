#!/usr/bin/env bash
# WhatsApp no CRM contra o Docker local (WHATSAPP_DRIVER=teste): bash tests/e2e-whatsapp.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }
SECRET=$(php_ 'echo env("WHATSAPP_WEBHOOK_SECRET");')
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "W Admin" w-admin@example.com >/dev/null
php_ 'use App\Db; Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (\"W Comercial\",\"w-com@example.com\",?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=\"w-com@example.com\"")->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,\"comercial\")",[$id]); Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm w-admin@example.com; login com w-com@example.com
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
T=$(tok adm)
ADM=$(q "SELECT id FROM users WHERE email=?" w-admin@example.com)
hook(){ curl -s -o $S/hook.json -w "%{http_code}" -H 'Content-Type: application/json' --data-binary "$2" "$B/api/whatsapp/$1"; }
evo(){ # jid id text [fromMe]
  printf '{"event":"messages.upsert","instance":"li","data":{"key":{"remoteJid":"%s","fromMe":%s,"id":"%s"},"pushName":"Maria Teste WA","message":{"conversation":"%s"},"messageTimestamp":%s}}' "$1" "${4:-false}" "$2" "$3" "$(date +%s)"; }
N8=8$(printf '%07d' $((RANDOM * 30 + RANDOM % 30))); PH="+55619$N8"; JID="5561$N8@s.whatsapp.net" # chega sem o 9
ID1=w-$(openssl rand -hex 6); ID2=w-$(openssl rand -hex 6)
NB=$(q "SELECT COUNT(*) FROM notifications WHERE user_id=? AND body LIKE 'WhatsApp:%'" $ADM)

echo "segredo errado: $(hook $(openssl rand -hex 32) "$(evo $JID $ID1 oi)") | JSON inválido: $(hook $SECRET 'nada')"
echo "mensagem nova: $(hook $SECRET "$(evo $JID $ID1 'Oi, quero orçamento de interiores')") $(cat $S/hook.json)"
C=$(q "SELECT id FROM wa_chats WHERE phone=?" $PH)
echo "conversa criada com o 9: $([ -n "$C" ] && echo sim || echo NÃO) | não lidas: $(q "SELECT unread FROM wa_chats WHERE id=?" $C) | aviso no sino: $(( $(q "SELECT COUNT(*) FROM notifications WHERE user_id=? AND body LIKE 'WhatsApp:%'" $ADM) - NB ))"
echo "mesmo id de novo: $(hook $SECRET "$(evo $JID $ID1 'Oi, quero orçamento de interiores')") $(cat $S/hook.json) | mensagens: $(q "SELECT COUNT(*) FROM wa_messages WHERE chat_id=?" $C)"
hook $SECRET "$(evo $JID $ID2 'Apartamento de 90 m2')" >/dev/null
echo "2ª mensagem: não lidas $(q "SELECT unread FROM wa_chats WHERE id=?" $C) | avisos (continua 1): $(( $(q "SELECT COUNT(*) FROM notifications WHERE user_id=? AND body LIKE 'WhatsApp:%'" $ADM) - NB ))"
echo "pulse com contador: $(curl -s -b $S/adm.jar $B/api/pulse | grep -o '"wa":[0-9]*')"
echo "caixa (admin): $(curl -s -b $S/adm.jar $B/whatsapp | grep -c 'Maria Teste WA') | comercial vê contato novo: $(curl -s -b $S/com.jar -o /dev/null -w %{http_code} $B/whatsapp/$C)"
H=$(curl -s -b $S/adm.jar $B/whatsapp/$C); echo "$H" > $S/chat.html
echo "conversa: mensagens $(echo "$H" | grep -c 'class="wa-msg') | botão cadastrar $(echo "$H" | grep -c 'clientes/novo?wa='"$C") | não lidas depois de abrir: $(q "SELECT unread FROM wa_chats WHERE id=?" $C)"
echo "poll JSON: $(curl -s -b $S/adm.jar "$B/whatsapp/$C/mensagens?depois=0" | grep -o '"id"' | wc -l | tr -d ' ') mensagens"
echo "responder sem CSRF: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar -d text=oi $B/whatsapp/$C/enviar) | com CSRF: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode 'text=Olá Maria! Posso te ligar?' $B/whatsapp/$C/enviar) | status: $(q "SELECT status FROM wa_messages WHERE chat_id=? AND from_me=1 ORDER BY id DESC LIMIT 1" $C) | vazio: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar --data-urlencode "_csrf=$T" -d text= $B/whatsapp/$C/enviar)"
echo "mensagem do próprio celular: $(hook $SECRET "$(evo $JID w-$(openssl rand -hex 6) 'mandei do celular' true)") | não lidas: $(q "SELECT unread FROM wa_chats WHERE id=?" $C)"

F=$(curl -s -b $S/adm.jar "$B/clientes/novo?wa=$C")
echo "formulário pré-preenchido: nome $(echo "$F" | grep -c 'value="Maria Teste WA"') | número $(echo "$F" | grep -c "value=\"$PH\"") | origem $(echo "$F" | grep -c 'value="WhatsApp"') | vínculo $(echo "$F" | grep -c "name=\"wa_chat\" value=\"$C\"")"
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "name=Maria Teste WA" --data-urlencode "whatsapp=$PH" -d origin=WhatsApp -d wa_chat=$C -d email=w-maria@example.com $B/clientes); CID=${L##*/}
echo "cadastrar: cliente $CID | conversa ligada: $(q "SELECT client_id FROM wa_chats WHERE id=?" $C) | oportunidade: $(q "SELECT stage FROM opportunities WHERE client_id=?" $CID) | linha do tempo: $(q "SELECT COUNT(*) FROM client_events WHERE client_id=? AND body LIKE 'Cadastrado a partir do WhatsApp%'" $CID)"
echo "ficha com card WhatsApp: $(curl -s -b $S/adm.jar $B/clientes/$CID | grep -c 'Abrir conversa') | conversa mostra 'Abrir ficha': $(curl -s -b $S/adm.jar $B/whatsapp/$C | grep -c '>ficha</a>')"
echo "comercial, cliente de outra pessoa: conversa $(curl -s -b $S/com.jar -o /dev/null -w %{http_code} $B/whatsapp/$C) | envio $(curl -s -o /dev/null -w %{http_code} -b $S/com.jar --data-urlencode "_csrf=$(tok com)" -d text=x $B/whatsapp/$C/enviar) | na lista: $(curl -s -b $S/com.jar $B/whatsapp | grep -c 'Maria Teste WA')"

N2=8$(printf '%07d' $((RANDOM * 30 + RANDOM % 30))); PH2="+55619$N2"
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "name=Cliente Já Cadastrado" --data-urlencode "whatsapp=$PH2" -d email=w-ja@example.com $B/clientes); CID2=${L##*/}
hook $SECRET "$(evo 5561$N2@s.whatsapp.net w-$(openssl rand -hex 6) 'oi de novo')" >/dev/null
echo "cliente já cadastrado escreve: ligada sozinha $([ "$(q "SELECT client_id FROM wa_chats WHERE phone=?" $PH2)" = "$CID2" ] && echo sim || echo NÃO) | linha do tempo: $(q "SELECT COUNT(*) FROM client_events WHERE client_id=? AND body LIKE 'WhatsApp:%'" $CID2)"
DP=8$(printf '%07d' $((RANDOM * 30 + RANDOM % 30))); PH3="+55619$DP"
DB=$(printf '{"EventType":"messages","message":{"chatid":"5561%s@s.whatsapp.net","messageid":"w-d%s","fromMe":false,"isGroup":false,"senderName":"Paulo Drope","messageType":"Conversation","text":"Oi pela DROPE","messageTimestamp":%s000}}' $DP $(openssl rand -hex 5) $(date +%s))
echo "formato DROPE: $(curl -s -o /dev/null -w %{http_code} -H 'X-DROPE-Event: messages' -H 'Content-Type: application/json' --data-binary "$DB" $B/api/whatsapp/$SECRET) | gravada: $(q "SELECT COUNT(*) FROM wa_messages m JOIN wa_chats w ON w.id=m.chat_id WHERE w.phone=? AND m.body='Oi pela DROPE'" $PH3)"
php_ 'App\Db::q("INSERT INTO settings (k,v) VALUES (\"wa_signing_secret\",\"segredo-teste\") ON DUPLICATE KEY UPDATE v=VALUES(v)");'
BODY="{\"EventType\":\"messages\",\"message\":{\"chatid\":\"5561$DP@s.whatsapp.net\",\"messageid\":\"w-s$(openssl rand -hex 5)\",\"fromMe\":false,\"text\":\"assinada\",\"messageType\":\"Conversation\"}}"
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac segredo-teste | awk '{print $NF}')"
echo "assinatura: sem $(curl -s -o /dev/null -w %{http_code} -H 'Content-Type: application/json' --data-binary "$BODY" $B/api/whatsapp/$SECRET) | errada $(curl -s -o /dev/null -w %{http_code} -H 'X-DROPE-Signature: sha256=00' -H 'Content-Type: application/json' --data-binary "$BODY" $B/api/whatsapp/$SECRET) | certa $(curl -s -o /dev/null -w %{http_code} -H "X-DROPE-Signature: $SIG" -H 'Content-Type: application/json' --data-binary "$BODY" $B/api/whatsapp/$SECRET)"
php_ 'App\Db::q("DELETE FROM settings WHERE k=\"wa_signing_secret\"");'
echo "grupo ignorado: $(hook $SECRET "$(evo 120363000@g.us w-$(openssl rand -hex 6) grupo)") $(cat $S/hook.json)"
echo "avisos do PHP: $( (cat $S/chat.html; curl -s -b $S/adm.jar $B/whatsapp; curl -s -b $S/adm.jar "$B/whatsapp?q=maria"; curl -s -b $S/adm.jar $B/clientes/$CID) | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"

php_ 'use App\Db; $ids = Db::q("SELECT id FROM wa_chats WHERE phone IN (?,?,?)", [$argv[1], $argv[2], $argv[3]])->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $i) Db::q("DELETE FROM notifications WHERE link = ?", ["/whatsapp/$i"]);
Db::q("DELETE FROM wa_chats WHERE phone IN (?,?,?)", [$argv[1], $argv[2], $argv[3]]); Db::q("DELETE FROM clients WHERE email LIKE \"w-%@example.com\"");
Db::q("DELETE FROM users WHERE email LIKE \"w-%@example.com\""); Db::q("DELETE FROM login_attempts WHERE email LIKE \"%w-%@example.com\"");' $PH $PH2 $PH3
rm -r "$S"
