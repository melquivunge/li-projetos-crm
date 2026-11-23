#!/usr/bin/env bash
# Fase B (papéis, oportunidades, tarefas) contra o Docker local. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
O=$(grep ^SITE_ORIGIN= .env | cut -d= -f2-); K=$(grep ^SITE_API_KEY= .env | cut -d= -f2-)
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "B Admin" b-admin@example.com >/dev/null
docker compose exec -T app php -r 'require "src/bootstrap.php"; use App\Db;
foreach (["b-designer@example.com" => ["designer"], "b-comercial@example.com" => ["comercial"]] as $e => $roles) {
  Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [$e, $e, password_hash($argv[1], PASSWORD_DEFAULT)]);
  $id = Db::q("SELECT id FROM users WHERE email=?", [$e])->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?", [$id]);
  foreach ($roles as $r) Db::q("INSERT INTO user_roles VALUES (?,?)", [$id, $r]); }
Db::q("DELETE FROM login_attempts"); Db::q("DELETE FROM api_rate");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
code(){ curl -s -o /dev/null -w %{http_code} -b $S/$1.jar "$B$2"; }
login adm b-admin@example.com; login des b-designer@example.com; login com b-comercial@example.com
echo "designer → clientes $(code des /clientes) funil $(code des /funil) propostas $(code des /propostas) tarefas $(code des /tarefas) usuários $(code des /usuarios)"
echo "comercial → clientes $(code com /clientes) funil $(code com /funil) propostas $(code com /propostas) tarefas $(code com /tarefas) usuários $(code com /usuarios)"
# lead pelo site cria cliente + oportunidade
curl -s -o /dev/null -H "Origin: $O" -H 'Content-Type: text/plain' --data "{\"key\":\"$K\",\"type\":\"contact_form\",\"name\":\"Fase B Lead\",\"phone\":\"61933332222\",\"email\":\"faseb@example.com\",\"scope\":\"Design de Interiores\"}" $B/api/lead
CID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM clients WHERE email=\"faseb@example.com\"")->fetchColumn();')
OID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM opportunities WHERE client_id=?",[$argv[1]])->fetchColumn();' $CID)
echo "lead do site → oportunidade #$OID ($(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT CONCAT(title, \" / \", stage) FROM opportunities WHERE id=?",[$argv[1]])->fetchColumn();' $OID))"
T=$(tok adm)
echo "2ª oportunidade: $(curl -s -o /dev/null -w %{redirect_url} -b $S/adm.jar --data-urlencode _csrf=$T --data-urlencode 'title=Planta 3D da casa de campo' -d service='Plantas Baixas' -d estimated_value=490 $B/clientes/$CID/oportunidades)"
echo "arrastar p/ briefing: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar -H 'X-Requested-With: fetch' -F _csrf=$T -F stage=briefing $B/oportunidades/$OID/etapa) → cliente: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT stage FROM clients WHERE id=?",[$argv[1]])->fetchColumn();' $CID)"
echo "perdido sem motivo: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar -H 'X-Requested-With: fetch' -F _csrf=$T -F stage=perdido $B/oportunidades/$OID/etapa)"
echo "comercial mexe em oportunidade alheia: $(curl -s -o /dev/null -w %{http_code} -b $S/com.jar -H 'X-Requested-With: fetch' -F _csrf=$(tok com) -F stage=ganho $B/oportunidades/$OID/etapa)"
echo "designer mexe em oportunidade: $(curl -s -o /dev/null -w %{http_code} -b $S/des.jar -H 'X-Requested-With: fetch' -F _csrf=$(tok des) -F stage=ganho $B/oportunidades/$OID/etapa)"
# tarefas
DES=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM users WHERE email=\"b-designer@example.com\"")->fetchColumn();')
curl -s -o /dev/null -b $S/adm.jar --data-urlencode _csrf=$T --data-urlencode 'title=Ligar para o cliente' -d due_at=$(date -v-1d +%F) -d priority=alta -d client_id=$CID -d opportunity_id=$OID $B/tarefas
curl -s -o /dev/null -b $S/adm.jar --data-urlencode _csrf=$T --data-urlencode 'title=Revisar planta' -d due_at=$(date +%F) -d owner_id=$DES $B/tarefas
TID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM tasks WHERE client_id=?",[$argv[1]])->fetchColumn();' $CID)
echo "tarefa atrasada aparece na lista de clientes: $(curl -s -b $S/adm.jar "$B/clientes?atrasadas=1" | grep -c 'Fase B Lead')"
echo "designer recebeu notificação: $(curl -s -b $S/des.jar $B/notificacoes | grep -c 'criou uma tarefa para você')"
echo "designer vê a tarefa dele: $(curl -s -b $S/des.jar '/dev/null' $B/tarefas?ver=hoje | grep -c 'Revisar planta')"
echo "designer conclui tarefa alheia: $(curl -s -o /dev/null -w %{http_code} -b $S/des.jar --data-urlencode _csrf=$(tok des) $B/tarefas/$TID/feita)"
echo "admin conclui: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar --data-urlencode _csrf=$T $B/tarefas/$TID/feita) → $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT IF(done_at IS NULL,\"aberta\",\"feita\") FROM tasks WHERE id=?",[$argv[1]])->fetchColumn();' $TID)"
# papéis
ADM=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM users WHERE email=\"b-admin@example.com\"")->fetchColumn();')
echo "tirar o próprio admin: $(curl -s -b $S/adm.jar -c $S/adm.jar --data-urlencode _csrf=$T -d 'roles[]=comercial' -L $B/usuarios/$ADM/papeis | grep -o 'Você não pode tirar o seu próprio acesso de admin' | head -1)"
curl -s -o /dev/null -b $S/adm.jar --data-urlencode _csrf=$T -d 'roles[]=designer' -d 'roles[]=comercial' $B/usuarios/$DES/papeis
echo "designer virou também comercial → sessão antiga cai (303): $(code des /clientes)"
login des b-designer@example.com; T=$(curl -s -b $S/adm.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
echo "novo login → clientes $(code des /clientes) (espera 200)"
echo "comercial salva papéis: $(curl -s -o /dev/null -w %{http_code} -b $S/com.jar --data-urlencode _csrf=$(tok com) -d 'roles[]=admin' $B/usuarios/$DES/papeis)"
docker compose exec -T app php -r 'require "src/bootstrap.php"; use App\Db; Db::q("DELETE FROM clients WHERE email=\"faseb@example.com\""); Db::q("DELETE FROM tasks WHERE title IN (\"Revisar planta\")"); Db::q("DELETE FROM users WHERE email LIKE \"b-%@example.com\""); Db::q("DELETE FROM login_attempts"); Db::q("DELETE FROM api_rate");'
rm -r "$S"
