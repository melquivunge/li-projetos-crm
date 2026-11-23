#!/usr/bin/env bash
# Fase G (financeiro e cobrança) contra o Docker local: bash tests/e2e-finance.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "G Admin" g-admin@example.com >/dev/null
php_ 'use App\Db; foreach ([["G Financeiro","g-fin@example.com","financeiro"],["G Gestor","g-ges@example.com","gestor"]] as [$n,$m,$r]) {
  Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [$n,$m,password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=?",[$m])->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,?)",[$id,$r]); } Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm g-admin@example.com; login fin g-fin@example.com; login ges g-ges@example.com
code(){ curl -s -o /dev/null -w %{http_code} -b $S/$1.jar "$B$2"; }
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
T=$(tok adm); TF=$(tok fin); TG=$(tok ges)
post(){ local t=$T; [ $1 = fin ] && t=$TF; [ $1 = ges ] && t=$TG; curl -s -o /dev/null -w "%{http_code}" -b $S/$1.jar --data-urlencode "_csrf=$t" "${@:3}" "$B$2"; }
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null

L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d "name=Cliente Cobrança" -d "whatsapp=61966665555" -d "email=cobranca@example.com" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "title=Interiores cobrança" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" \
  --data-urlencode "payment_terms=50% na assinatura e 50% na entrega" --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=20 dias" $B/clientes/$CID/propostas); PROP=${L##*/}
post adm /propostas/$PROP/status -d status=aceita >/dev/null
PJ=$(php_ 'echo App\Db::q("SELECT id FROM projects WHERE proposal_id=?",[$argv[1]])->fetchColumn();' $PROP)
echo "aceite → parcelas: $(php_ 'foreach (App\Db::q("SELECT description, amount, due_date FROM installments WHERE project_id=? ORDER BY number",[$argv[1]]) as $i) echo $i["description"], "=", $i["amount"], "@", $i["due_date"], " "; echo "| contrato ", App\Db::q("SELECT contract_value FROM projects WHERE id=?",[$argv[1]])->fetchColumn();' $PJ)"
I1=$(php_ 'echo App\Db::q("SELECT id FROM installments WHERE project_id=? AND number=1",[$argv[1]])->fetchColumn();' $PJ)
I2=$(php_ 'echo App\Db::q("SELECT id FROM installments WHERE project_id=? AND number=2",[$argv[1]])->fetchColumn();' $PJ)
echo "acesso /financeiro: admin $(code adm /financeiro) financeiro $(code fin /financeiro) gestor $(code ges /financeiro) | gestor paga $(post ges /parcelas/$I1/pagar -d method=pix) | card no projeto: admin $(curl -s -b $S/adm.jar $B/projetos/$PJ | grep -c 'id="financeiro"') gestor $(curl -s -b $S/ges.jar $B/projetos/$PJ | grep -c 'id="financeiro"')"

echo "config: financeiro $(post fin /financeiro/config -d pix_key=x) | admin $(post adm /financeiro/config --data-urlencode 'pix_key=pix@liprojetos.com.br' --data-urlencode 'pix_name=LI Projetos' --data-urlencode 'hourly_cost=60,00')"
echo "pagamento no futuro: $(post fin /parcelas/$I1/pagar -d paid_at=$(date -v+2d +%F) -d method=cartao >/dev/null; php_ 'echo App\Db::q("SELECT status FROM installments WHERE id=?",[$argv[1]])->fetchColumn();' $I1)"
post fin /parcelas/$I1/pagar -d paid_at=$(date +%F) -d method=cartao --data-urlencode "paid_amount=$(php_ 'echo number_format((float) App\Db::q("SELECT amount FROM installments WHERE id=?",[$argv[1]])->fetchColumn(), 2, ",", ".");' $I1)" >/dev/null
echo "pago no cartão: $(php_ 'echo implode(" ", App\Db::q("SELECT status, method, paid_amount FROM installments WHERE id=?",[$argv[1]])->fetch(PDO::FETCH_NUM));' $I1) | estorno: $(post fin /parcelas/$I1/estornar >/dev/null; php_ 'echo App\Db::q("SELECT status FROM installments WHERE id=?",[$argv[1]])->fetchColumn();' $I1) → paga de novo: $(post fin /parcelas/$I1/pagar -d method=pix >/dev/null; php_ 'echo App\Db::q("SELECT CONCAT(status, \" \", method) FROM installments WHERE id=?",[$argv[1]])->fetchColumn();' $I1)"
echo "auditoria da parcela: $(php_ 'echo implode(",", App\Db::q("SELECT action FROM audit_log WHERE entity=\"installment\" AND entity_id=? ORDER BY id",[$argv[1]])->fetchAll(PDO::FETCH_COLUMN));' $I1)"

php_ 'App\Db::q("UPDATE installments SET due_date = CURDATE() - INTERVAL 7 DAY WHERE id=?",[$argv[1]]);' $I2
W=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/fin.jar --data-urlencode "_csrf=$TF" -d canal=whatsapp $B/parcelas/$I2/cobrar)
echo "cobrar WhatsApp → $(echo $W | cut -c1-40)… | tem Pix: $(python3 -c 'import sys,urllib.parse as u; t=u.unquote(sys.argv[1]); print("sim" if "Pix: pix@liprojetos.com.br" in t and "ainda está em aberto" in t else "NÃO")' "$W")"
post fin /parcelas/$I2/cobrar -d canal=email >/dev/null
echo "cobrar e-mail → mailpit: $(curl -s http://localhost:8025/api/v1/messages | python3 -c 'import json,sys; m=json.load(sys.stdin)["messages"]; print(m[0]["Subject"] if m else "NADA", "→", m[0]["To"][0]["Address"] if m else "")')"
echo "registro: $(php_ 'echo App\Db::q("SELECT reminders FROM installments WHERE id=?",[$argv[1]])->fetchColumn(), "× | histórico: ", App\Db::q("SELECT COUNT(*) FROM client_events WHERE client_id=? AND type=\"cobranca\"",[$argv[2]])->fetchColumn(), " eventos";' $I2 $CID)"
echo "lista vencidas: $(curl -s -b $S/fin.jar "$B/financeiro?ver=vencidas" | grep -o '7 dias de atraso' | head -1) | menu: $(curl -s -b $S/fin.jar $B/financeiro | grep -o 'title="Parcelas vencidas">[0-9]*' | head -1)"
echo "cron: $(docker compose exec -T app php bin/cron.php | grep -o '"parcelas":[0-9]*') | 2ª vez: $(docker compose exec -T app php bin/cron.php | grep -o '"parcelas":[0-9]*') | aviso p/ financeiro: $(php_ 'echo App\Db::q("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.email=\"g-fin@example.com\" AND n.body LIKE \"%atraso há 7 dias%\"")->fetchColumn();')"

echo "despesa: $(post fin /projetos/$PJ/despesas --data-urlencode "amount=350,00" -d category=impressao -d "description=Plotagem")"
post fin /parcelas/$I2 --data-urlencode "amount=1,00" -d due_date=$(date +%F) >/dev/null
echo "parcelas ≠ contrato avisa: $(curl -s -b $S/adm.jar $B/projetos/$PJ | grep -c 'As parcelas somam') | margem: $(curl -s -b $S/adm.jar $B/projetos/$PJ | grep -A1 '<dt>Margem' | grep -o 'R\$ [0-9.,]*' | head -1)"
echo "valor inválido: $(post fin /projetos/$PJ/parcelas -d amount=abc -d due_date=$(date +%F) >/dev/null; php_ 'echo App\Db::q("SELECT COUNT(*) FROM installments WHERE project_id=?",[$argv[1]])->fetchColumn();' $PJ) parcelas (continua 2) → válida: $(post fin /projetos/$PJ/parcelas --data-urlencode "amount=500,00" -d due_date=$(date +%F) -d "description=Aditivo" >/dev/null; php_ 'echo App\Db::q("SELECT COUNT(*) FROM installments WHERE project_id=?",[$argv[1]])->fetchColumn();' $PJ) | sem CSRF: $(curl -s -o /dev/null -w %{http_code} -b $S/fin.jar -d method=pix $B/parcelas/$I2/pagar)"
echo "avisos do PHP: $(for u in /financeiro "/financeiro?ver=pagas" "/financeiro?ver=abertas" /projetos/$PJ /clientes/$CID; do curl -s -b $S/adm.jar "$B$u"; done | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"

php_ 'use App\Db; Db::q("UPDATE settings SET v=\"\" WHERE k IN (\"pix_key\",\"pix_name\")"); Db::q("UPDATE settings SET v=\"0\" WHERE k=\"hourly_cost\"");
Db::q("DELETE FROM notifications WHERE body LIKE \"%Cliente Cobrança%\" OR link LIKE ?", ["/projetos/".$argv[1]."%"]); Db::q("DELETE FROM clients WHERE email=\"cobranca@example.com\""); Db::q("DELETE FROM users WHERE email LIKE \"g-%@example.com\""); Db::q("DELETE FROM login_attempts");' $PJ
rm -r "$S"
