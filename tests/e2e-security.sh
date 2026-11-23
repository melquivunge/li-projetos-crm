#!/usr/bin/env bash
# Correções da auditoria de 23/11/2026 contra o Docker local: bash tests/e2e-security.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12); P2=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "S Admin" s-admin@example.com >/dev/null
php_ 'use App\Db; Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (\"S Comercial\",\"s-com@example.com\",?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), active=1", [password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=\"s-com@example.com\"")->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,\"comercial\")",[$id]); Db::q("DELETE FROM login_attempts");' $P
login(){ local J=$S/$1.jar T; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$3" $B/login; }
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
code(){ curl -s -o /dev/null -w %{http_code} -b $S/$1.jar "$B$2"; }
post(){ local j=$1 p=$2; shift 2; curl -s -o /dev/null -w %{http_code} -b $S/$j.jar -c $S/$j.jar --data-urlencode "_csrf=$(tok $j)" "$@" "$B$p"; }
loc(){ local j=$1 p=$2; shift 2; curl -s -o /dev/null -w %{redirect_url} -b $S/$j.jar -c $S/$j.jar --data-urlencode "_csrf=$(tok $j)" "$@" "$B$p"; }
login a1 s-admin@example.com $P; login a2 s-admin@example.com $P; login com s-com@example.com $P
CID=$(q "SELECT id FROM users WHERE email=?" s-com@example.com)

echo "== F03 sessões"
echo "duas sessões abertas: $(code a1 /tarefas) $(code a2 /tarefas)"
post a1 /perfil/senha --data-urlencode "current_password=$P" --data-urlencode "new_password=$P2" --data-urlencode "confirm_password=$P2" >/dev/null
echo "troca a senha na 1: a 1 continua $(code a1 /tarefas) | a 2 cai (espera 303): $(code a2 /tarefas)"
post a1 /usuarios/$CID/ativo >/dev/null; echo "desativa o comercial: sessão dele $(code com /tarefas)"
post a1 /usuarios/$CID/ativo >/dev/null; echo "reativa: cookie antigo continua morto (303): $(code com /tarefas)"
login com s-com@example.com $P; echo "login novo do comercial: $(code com /tarefas)"
post a1 /usuarios/$CID/papeis -d "roles[]=comercial" -d "roles[]=financeiro" >/dev/null; echo "muda papéis: sessão antiga dele cai (303): $(code com /tarefas)"
echo "papéis no banco: $(q "SELECT GROUP_CONCAT(role ORDER BY role) FROM user_roles WHERE user_id=?" $CID)"
php_ 'App\Db::q("DELETE FROM user_roles WHERE user_id=?", [$argv[1]]);' $CID; login com s-com@example.com $P
echo "F06 conta sem papel: clientes $(code com /clientes) | funil $(code com /funil) (espera 403)"

echo "== F10 redirect"
echo "/\\example.invalid → $(loc a1 /perfil/tema -d theme=brand --data-urlencode 'back=/\example.invalid') | interno → $(loc a1 /perfil/tema -d theme=brand --data-urlencode 'back=/tarefas')"

echo "== projeto de teste (cliente + proposta aceita)"
L=$(loc a1 /clientes --data-urlencode "name=Cliente Segurança" -d "whatsapp=61977776666" -d "email=s-cli@example.com" -d "service=Design de Interiores"); CL=${L##*/}
L=$(loc a1 /clientes/$CL/propostas --data-urlencode "title=Projeto Seg" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" --data-urlencode "payment_terms=50% + 50%" --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=20 dias"); PR=${L##*/}
post a1 /propostas/$PR/status -d status=aceita >/dev/null; PJ=$(q "SELECT id FROM projects WHERE proposal_id=?" $PR)
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/a.pdf
post a1 /projetos/$PJ/entregas --data-urlencode "title=Planta" >/dev/null; D=$(q "SELECT id FROM deliverables WHERE project_id=?" $PJ)
curl -s -o /dev/null -b $S/a1.jar --form-string _csrf=$(tok a1) -F "files[]=@$S/a.pdf" $B/entregas/$D
post a1 /entregas/$D/enviar >/dev/null; TK=$(q "SELECT public_token FROM deliverables WHERE id=?" $D)
echo "projeto $PJ, entrega $D, link /e/: $(curl -s -o /dev/null -w %{http_code} $B/e/$TK)"

echo "== F07 aprovação"
echo "marcar 'aprovado' pela situação (recusado): $(post a1 /entregas/$D -d status=aprovado) → $(q "SELECT status FROM deliverables WHERE id=?" $D)"
post a1 /entregas/$D/aprovar --data-urlencode "name=Cliente Segurança" >/dev/null; echo "registrar aprovação: $(q "SELECT status FROM deliverables WHERE id=?" $D) | aceites gravados: $(q "SELECT COUNT(*) FROM approvals WHERE entity='deliverable' AND entity_id=?" $D)"
curl -s -o /dev/null -b $S/a1.jar --form-string _csrf=$(tok a1) -F "files[]=@$S/a.pdf" $B/entregas/$D
echo "arquivo novo depois da aprovação → $(q "SELECT status FROM deliverables WHERE id=?" $D) (espera producao)"

echo "== F05 aprovação de custo em paralelo"
RV=$(php_ 'use App\Db; Db::q("INSERT INTO revision_requests (project_id, deliverable_id, type, description, is_extra, extra_cost, status, author_name) VALUES (?,?,\"revisao\",\"Teste corrida\",1,300,\"aguardando_aprovacao\",\"Teste\")", [$argv[1], $argv[2]]); echo Db::pdo()->lastInsertId();' $PJ $D)
NB=$(q "SELECT COUNT(*) FROM installments WHERE project_id=?" $PJ)
for i in 1 2 3; do (J=$S/c$i.jar; CT=$(curl -s -c $J $B/e/$TK | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4); curl -s -o /dev/null -b $J --data-urlencode "_csrf=$CT" -d action=approve_cost -d revision_id=$RV -d agree=1 --data-urlencode "signer=Cliente Teste Silva $i" $B/e/$TK) & done; wait
echo "3 aprovações ao mesmo tempo → parcelas novas: $(( $(q "SELECT COUNT(*) FROM installments WHERE project_id=?" $PJ) - NB )) | aceites: $(q "SELECT COUNT(*) FROM approvals WHERE entity='revision' AND entity_id=?" $RV) (espera 1 e 1)"

echo "== F08 pausa por pagamento"
php_ 'use App\Db; Db::q("UPDATE installments SET due_date = CURDATE() - INTERVAL 10 DAY WHERE project_id = ? AND status = \"aberta\" ORDER BY number LIMIT 1", [$argv[1]]); App\Finance::syncHold((int) $argv[1]);' $PJ
echo "status depois do cron: $(q "SELECT CONCAT(status,'/',COALESCE(hold_reason,'')) FROM projects WHERE id=?" $PJ)"
post a1 /projetos/$PJ --data-urlencode "title=Projeto Seg" -d status=ativo >/dev/null; echo "tentar voltar para ativo → $(q "SELECT status FROM projects WHERE id=?" $PJ) (espera pausado)"
php_ 'App\Db::q("UPDATE projects SET status=\"ativo\", hold_reason=NULL WHERE id=?", [$argv[1]]);' $PJ
SB=$(q "SELECT sent_at FROM deliverables WHERE id=?" $D); sleep 1; post a1 /entregas/$D/enviar >/dev/null
echo "mesmo com status ativo forçado, enviar entrega com dívida: bloqueado? $([ "$(q "SELECT sent_at FROM deliverables WHERE id=?" $D)" = "$SB" ] && echo sim || echo NÃO)"

echo "== F04 links"
php_ 'App\Db::q("UPDATE projects SET status=\"cancelado\" WHERE id=?", [$argv[1]]);' $PJ
echo "projeto cancelado → /e/ $(curl -s -o /dev/null -w %{http_code} $B/e/$TK) (espera 404)"
php_ 'App\Db::q("UPDATE projects SET status=\"ativo\" WHERE id=?", [$argv[1]]);' $PJ
W=$(loc a1 /clientes/$CL/portal); IT=$(python3 -c 'import sys,re,urllib.parse as u; m=re.search(r"/portal/convite/([a-f0-9]{64})", u.unquote(sys.argv[1])); print(m.group(1) if m else "")' "$W")
J=$S/cli.jar; CT=$(curl -s -c $J $B/portal/convite/$IT | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4)
curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$CT" -d password=senha-cliente-123 -d password2=senha-cliente-123 $B/portal/convite/$IT
echo "portal logado: $(curl -s -o /dev/null -w %{http_code} -b $J $B/portal)"
post a1 /clientes/$CL/portal >/dev/null; echo "convite renovado → sessão antiga do portal (espera 303): $(curl -s -o /dev/null -w %{http_code} -b $J $B/portal)"
post a1 /clientes/$CL/portal -d action=revoke >/dev/null; echo "acesso desativado → link antigo /e/ (espera 404): $(curl -s -o /dev/null -w %{http_code} $B/e/$TK) | token trocado: $([ "$(q "SELECT public_token FROM deliverables WHERE id=?" $D)" != "$TK" ] && echo sim || echo NÃO)"

echo "== F02 excluir cliente apaga o WhatsApp"
SEC=$(php_ 'echo env("WHATSAPP_WEBHOOK_SECRET");')
php_ 'App\Db::q("UPDATE clients SET owner_id=? WHERE id=?", [$argv[1], $argv[2]]);' $(q "SELECT id FROM users WHERE email=?" s-admin@example.com) $CL
curl -s -o /dev/null -H 'Content-Type: application/json' --data-binary '{"event":"messages.upsert","data":{"key":{"remoteJid":"5561977776666@s.whatsapp.net","fromMe":false,"id":"sec-1"},"pushName":"X","message":{"conversation":"meus dados"}}}' $B/api/whatsapp/$SEC
WC=$(q "SELECT id FROM wa_chats WHERE client_id=?" $CL); echo "conversa ligada ao cliente: $WC"
php_ 'App\Db::q("INSERT INTO user_roles VALUES (?,\"comercial\")",[$argv[1]]);' $CID; login com s-com@example.com $P
echo "comercial (cliente não é dele): conversa $(code com /whatsapp/$WC) (espera 403)"
post a1 /clientes/$CL/excluir -d confirm=EXCLUIR >/dev/null
echo "depois de excluir: conversas $(q "SELECT COUNT(*) FROM wa_chats WHERE id=?" $WC) | mensagens $(q "SELECT COUNT(*) FROM wa_messages WHERE chat_id=?" $WC) | comercial abre? $(code com /whatsapp/$WC) (espera 404)"

php_ 'use App\Db; Db::q("DELETE FROM users WHERE email LIKE \"s-%@example.com\""); Db::q("DELETE FROM login_attempts WHERE email LIKE \"%s-%@example.com\""); Db::q("DELETE FROM wa_chats WHERE phone=\"+5561977776666\"");'
rm -r "$S"
