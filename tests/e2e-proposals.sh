#!/usr/bin/env bash
# Fluxo de propostas contra o Docker local: bash tests/e2e-proposals.sh
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "Teste Admin" teste-admin@example.com >/dev/null
docker compose exec -T app php -r 'require "src/bootstrap.php"; App\Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)",["Teste Equipe","teste-equipe@example.com",password_hash($argv[1],PASSWORD_DEFAULT)]); App\Db::q("DELETE FROM login_attempts");' $P
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
tok(){ curl -s -b $S/$1.jar $B/clientes/novo | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
login adm teste-admin@example.com; login eq teste-equipe@example.com; T=$(tok adm)
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d "name=Carla Proposta" -d "whatsapp=61955554444" -d "email=carla@example.com" -d "area_m2=120" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
echo "faixa sugerida: $(curl -s -b $S/adm.jar $B/clientes/$CID/propostas/nova | grep 'faixa sugerida' | grep -o 'Estudo preliminar ([^)]*)' | head -1)"
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "title=Interiores 120 m²" -d "valid_until=$(date -v+15d +%F 2>/dev/null || date -d +15days +%F)" -d "qty[interiores]=120" -d "paid[]=planta3d" -d "paid[]=estudo200" $B/clientes/$CID/propostas); PID=${L##*/}; echo "proposta: $L"
echo "valores: $(curl -s -b $S/adm.jar $B/propostas/$PID | grep -o 'R\$ [0-9.,]*' | tr '\n' ' ')"
echo "PDF: $(curl -s -b $S/adm.jar $B/propostas/$PID/pdf | head -c 4)"
echo "equipe → proposta alheia: $(curl -s -o /dev/null -w %{http_code} -b $S/eq.jar $B/propostas/$PID) pdf: $(curl -s -o /dev/null -w %{http_code} -b $S/eq.jar $B/propostas/$PID/pdf) nova: $(curl -s -o /dev/null -w %{http_code} -b $S/eq.jar $B/clientes/$CID/propostas/nova) preços: $(curl -s -o /dev/null -w %{http_code} -b $S/eq.jar $B/precos)"
TOKEN=$(curl -s -b $S/adm.jar $B/propostas/$PID | grep -o '/p/[a-f0-9]\{64\}' | head -1 | cut -d/ -f3)
echo "público em rascunho: $(curl -s -o /dev/null -w %{http_code} $B/p/$TOKEN)"
echo "e-mail → $(curl -s -o /dev/null -w %{redirect_url} -b $S/adm.jar --data-urlencode _csrf=$T $B/propostas/$PID/email)"
curl -s http://localhost:8025/api/v1/messages | python3 -c "import json,sys; d=json.load(sys.stdin)['messages']; m=d[0] if d else {}; print('mailpit:', [t['Address'] for t in m.get('To',[])], '|', m.get('Subject'), '| anexos:', m.get('Attachments'))"
echo "whatsapp → $(curl -s -o /dev/null -w %{redirect_url} -b $S/adm.jar --data-urlencode _csrf=$T $B/propostas/$PID/whatsapp | cut -c1-90)…"
ET=$(curl -s -b $S/adm.jar $B/propostas/$PID/editar | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
L2=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$ET" --data-urlencode "title=Interiores 120 m² (revisada)" -d "valid_until=$(date -v+20d +%F 2>/dev/null || date -d +20days +%F)" -d "qty[interiores]=110" --data-urlencode "payment_terms=Teste: 50% + 50%" $B/propostas/$PID); PID2=${L2##*/}
echo "editar enviada → nova versão #$PID2: $(curl -s -b $S/adm.jar $B/propostas/$PID2 | grep -o 'versão 2' | head -1) | antiga: $(curl -s -b $S/adm.jar $B/propostas/$PID | grep -o 'Substituída' | head -1)"
OLDTOKEN=$TOKEN; TOKEN=$(curl -s -b $S/adm.jar $B/propostas/$PID2 | grep -o '/p/[a-f0-9]\{64\}' | head -1 | cut -d/ -f3)
curl -s -o /dev/null -b $S/adm.jar --data-urlencode _csrf=$T $B/propostas/$PID2/whatsapp
echo "link antigo leva à v2: $(curl -s -o /dev/null -w %{redirect_url} $B/p/$OLDTOKEN | grep -c $TOKEN)"
J=$S/anon.jar; echo "cliente vê: $(curl -s -c $J $B/p/$TOKEN | grep -o 'Aceitar proposta' | head -1)"
AT=$(curl -s -b $J -c $J $B/p/$TOKEN | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
echo "aceite sem CSRF: $(curl -s -o /dev/null -w %{http_code} -b $J -d answer=aceitar $B/p/$TOKEN/resposta)"
echo "aceite sem nome: $(curl -s -o /dev/null -w %{redirect_url} -b $J -c $J --data-urlencode _csrf=$AT -d answer=aceitar $B/p/$TOKEN/resposta | grep -o '#aceite')"
curl -s -o /dev/null -b $J -c $J --data-urlencode _csrf=$AT -d answer=aceitar --data-urlencode 'signer=Carla Proposta Teste' -d agree=1 $B/p/$TOKEN/resposta; echo "após aceite: $(curl -s -b $J $B/p/$TOKEN | grep -o 'Proposta aceita\.')"
docker compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT status, credit, total, sent_at IS NOT NULL sent, viewed_at IS NOT NULL viewed FROM proposals WHERE client_id IN (SELECT id FROM clients WHERE email = \"carla@example.com\"); SELECT decision, name, version FROM approvals ORDER BY id DESC LIMIT 1; SELECT stage FROM clients WHERE email = \"carla@example.com\";"' 2>/dev/null
docker compose exec -T app php -r 'require "src/bootstrap.php"; App\Db::q("DELETE FROM clients WHERE email = \"carla@example.com\""); App\Db::q("DELETE FROM users WHERE email LIKE \"teste-%@example.com\""); App\Db::q("DELETE FROM login_attempts");'
rm -r "$S"
