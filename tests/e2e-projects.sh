#!/usr/bin/env bash
# Fase D (projetos) contra o Docker local: bash tests/e2e-projects.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "D Admin" d-admin@example.com >/dev/null
php_ 'use App\Db; foreach ([["D Designer","d-des@example.com","designer"],["D Comercial","d-com@example.com","comercial"]] as [$n,$m,$r]) {
  Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [$n,$m,password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=?",[$m])->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,?)",[$id,$r]); } Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm d-admin@example.com; login des d-des@example.com; login com d-com@example.com
code(){ curl -s -o /dev/null -w %{http_code} -b $S/$1.jar "$B$2"; }
T=$(curl -s -b $S/adm.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
TD=$(curl -s -b $S/des.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
post(){ curl -s -o /dev/null -w "%{http_code}" -b $S/$1.jar --data-urlencode "_csrf=$([ $1 = des ] && echo $TD || echo $T)" "${@:3}" "$B$2"; }

L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d "name=Cliente Projeto" -d "whatsapp=61955554444" -d "email=projeto@example.com" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "title=Interiores teste" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" \
  --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=10 dias" --data-urlencode "stage[]=Anteprojeto" --data-urlencode "duration[]=2 semanas" --data-urlencode "stage[]=Entrega" --data-urlencode "duration[]=5 dias" $B/clientes/$CID/propostas); PROP=${L##*/}
post adm /propostas/$PROP/status -d status=aceita >/dev/null
PJ=$(php_ 'echo App\Db::q("SELECT id FROM projects WHERE proposal_id=?",[$argv[1]])->fetchColumn();' $PROP)
echo "aceite → projeto #$PJ | fases: $(php_ 'foreach (App\Db::q("SELECT name, due_date, status FROM project_phases WHERE project_id=? ORDER BY sort",[$argv[1]]) as $f) echo $f["name"], "=", $f["due_date"], "/", $f["status"], " ";' $PJ)"
post adm /propostas/$PROP/status -d status=aceita >/dev/null
echo "aceitar de novo não duplica: $(php_ 'echo App\Db::q("SELECT COUNT(*) FROM projects WHERE proposal_id=?",[$argv[1]])->fetchColumn();' $PROP) projeto"
echo "páginas (admin): lista $(code adm /projetos) kanban $(code adm '/projetos?ver=kanban') calendário $(code adm '/projetos?ver=calendario&mes=2026-12') projeto $(code adm /projetos/$PJ) | mês inválido $(code adm '/projetos?ver=calendario&mes=2026-13')"
echo "comercial: /projetos $(code com /projetos) | designer fora do projeto: $(code des /projetos/$PJ)"

DES=$(php_ 'echo App\Db::q("SELECT id FROM users WHERE email=\"d-des@example.com\"")->fetchColumn();')
PH1=$(php_ 'echo App\Db::q("SELECT id FROM project_phases WHERE project_id=? ORDER BY sort LIMIT 1",[$argv[1]])->fetchColumn();' $PJ)
post adm /projetos/$PJ/tarefas -d "title=Medir o apartamento" -d "due_at=$(date -v-1d +%F)" -d owner_id=$DES -d phase_id=$PH1 >/dev/null
TA=$(php_ 'echo App\Db::q("SELECT id FROM tasks WHERE project_id=? ORDER BY id LIMIT 1",[$argv[1]])->fetchColumn();' $PJ)
post adm /projetos/$PJ/tarefas -d "title=Layout" -d "due_at=$(date +%F)" -d owner_id=$DES -d blocked_by=$TA >/dev/null
TB=$(php_ 'echo App\Db::q("SELECT id FROM tasks WHERE project_id=? ORDER BY id DESC LIMIT 1",[$argv[1]])->fetchColumn();' $PJ)
echo "designer com tarefa no projeto: $(code des /projetos/$PJ) | aviso no sino: $(php_ 'echo App\Db::q("SELECT COUNT(*) FROM notifications WHERE user_id=? AND body LIKE \"%Layout%\"",[$argv[1]])->fetchColumn();' $DES)"
echo "atrasada destacada na lista: $(curl -s -b $S/adm.jar $B/projetos | grep -o '1 tarefa atrasada' | head -1)"
post des /tarefas/$TB/feita -d "back=/projetos/$PJ" >/dev/null
echo "concluir tarefa bloqueada: $(php_ 'echo App\Db::q("SELECT IF(done_at IS NULL, \"recusado\", \"ERRO: concluiu\") FROM tasks WHERE id=?",[$argv[1]])->fetchColumn();' $TB)"
post des /tarefas/$TA/feita -d "back=/projetos/$PJ" >/dev/null; post des /tarefas/$TB/feita -d "back=/projetos/$PJ" >/dev/null
echo "depois da que bloqueia: $(php_ 'echo App\Db::q("SELECT IF(done_at IS NULL, \"ERRO: aberta\", \"concluída\") FROM tasks WHERE id=?",[$argv[1]])->fetchColumn();' $TB)"

post des /fases/$PH1 -d action=done >/dev/null
echo "fase concluída → atual: $(php_ 'echo App\Db::q("SELECT name FROM project_phases WHERE project_id=? AND status=\"andamento\"",[$argv[1]])->fetchColumn();' $PJ)"
post adm /fases/$PH1 -d action=save -d name=Conceito -d due_date=2027-01-31 >/dev/null
post adm /projetos/$PJ/fases -d "name=Projeto legal" -d due_date=2027-03-15 >/dev/null
echo "nova fase no fim → entrega do projeto: $(php_ 'echo App\Db::q("SELECT due_date FROM projects WHERE id=?",[$argv[1]])->fetchColumn();' $PJ)"
echo "ação de fase inválida: $(post adm /fases/$PH1 -d action=hack) | sem CSRF: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar -d action=done $B/fases/$PH1)"

post adm /projetos/$PJ/entregas -d "title=Planta de layout" -d phase_id=$PH1 >/dev/null
DV=$(php_ 'echo App\Db::q("SELECT id FROM deliverables WHERE project_id=?",[$argv[1]])->fetchColumn();' $PJ)
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/planta.pdf; printf '<?php echo 1;' > $S/evil.pdf
for i in 1 2; do curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T --form-string status=enviado -F "files[]=@$S/planta.pdf;type=application/pdf" $B/entregas/$DV; done
curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/evil.pdf;type=application/pdf" $B/entregas/$DV
echo "entregável: $(php_ 'echo App\Db::q("SELECT status FROM deliverables WHERE id=?",[$argv[1]])->fetchColumn(), " | versões: "; foreach (App\Db::q("SELECT name, version FROM files WHERE owner_type=\"deliverable\" AND owner_id=?",[$argv[1]]) as $f) echo $f["name"], " v", $f["version"], " ";' $DV)"
FID=$(php_ 'echo App\Db::q("SELECT id FROM files WHERE owner_type=\"deliverable\" AND owner_id=? LIMIT 1",[$argv[1]])->fetchColumn();' $DV)
echo "arquivo: admin $(code adm /entregas/arquivos/$FID) | designer do projeto $(code des /entregas/arquivos/$FID) | comercial $(code com /entregas/arquivos/$FID) | sem login $(curl -s -o /dev/null -w %{http_code} $B/entregas/arquivos/$FID) | rota de briefing $(code adm /arquivos/$FID)"

post des /projetos/$PJ/horas -d hours=1:30 -d date=$(date +%F) -d task_id=$TA -d "note=Medição" >/dev/null
post des /projetos/$PJ/horas -d hours=0,5 -d date=$(date +%F) >/dev/null
post des /projetos/$PJ/horas -d hours=1 -d date=$(date -v+1d +%F) >/dev/null
echo "horas: $(php_ 'echo App\Db::q("SELECT SUM(minutes) FROM time_entries WHERE project_id=?",[$argv[1]])->fetchColumn();' $PJ) min (esperado 120; data futura recusada)"
echo "Kanban: arrastar p/ Entrega $(post adm /projetos/$PJ/fase --data-urlencode stage=Entrega) → atual: $(php_ 'echo App\Db::q("SELECT name FROM project_phases WHERE project_id=? AND status=\"andamento\"",[$argv[1]])->fetchColumn();' $PJ) | voltar p/ Anteprojeto $(post adm /projetos/$PJ/fase --data-urlencode stage=Anteprojeto) → $(php_ 'echo App\Db::q("SELECT COUNT(*) FROM project_phases WHERE project_id=? AND status=\"concluida\"",[$argv[1]])->fetchColumn();' $PJ) concluída(s) | fase inexistente $(post adm /projetos/$PJ/fase --data-urlencode stage=Detalhamento) | designer $(post des /projetos/$PJ/fase --data-urlencode stage=Entrega) | comercial $(post com /projetos/$PJ/fase --data-urlencode stage=Entrega)"
printf 'AC1032' > $S/planta.dwg; head -c 3000 /dev/zero >> $S/planta.dwg
curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/planta.dwg" $B/entregas/$DV
DW=$(php_ 'echo App\Db::q("SELECT id FROM files WHERE owner_type=\"deliverable\" AND owner_id=? AND mime=\"image/vnd.dwg\"",[$argv[1]])->fetchColumn();' $DV)
echo "DWG: salvo #$DW | $(curl -s -D - -o /dev/null -b $S/adm.jar $B/entregas/arquivos/$DW | grep -i 'content-disposition' | cut -d';' -f1 | tr -d '\r')"
php_ 'App\Db::q("UPDATE project_phases SET due_date = CURDATE() - INTERVAL 2 DAY WHERE project_id=? AND status=\"andamento\"",[$argv[1]]);' $PJ
echo "cron 1ª vez: $(docker compose exec -T app php bin/cron.php | cut -d' ' -f3-) | 2ª vez: $(docker compose exec -T app php bin/cron.php | cut -d' ' -f3-) | pela web: $(curl -s -o /dev/null -w %{http_code} $B/../bin/cron.php)"
echo "aviso de fase atrasada (projeto sem responsável → sócias): $(php_ 'echo App\Db::q("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.role=\"admin\" AND n.body LIKE \"%está atrasada%\" AND n.link LIKE ?",["/projetos/".$argv[1]."%"])->fetchColumn();' $PJ) admin(s)"
echo "pulse: $(curl -s -b $S/des.jar $B/api/pulse) | sem login: $(curl -s -o /dev/null -w %{http_code} $B/api/pulse)"
post adm /projetos/$PJ -d "title=Interiores teste" -d status=concluido -d manager_id=$DES >/dev/null
echo "concluído: $(php_ 'echo App\Db::q("SELECT CONCAT(status, \" done_at=\", done_at IS NOT NULL) FROM projects WHERE id=?",[$argv[1]])->fetchColumn();' $PJ) | aba encerrados: $(code adm '/projetos?status=encerrados')"
echo "avisos do PHP nas páginas: $(for u in /projetos "/projetos?ver=kanban" "/projetos?ver=calendario" /projetos/$PJ /tarefas /clientes/$CID /propostas/$PROP; do curl -s -b $S/adm.jar "$B$u"; done | grep -ci 'warning\|notice\|fatal\|deprecated')"

php_ 'use App\Db; foreach (Db::q("SELECT * FROM files WHERE client_id IN (SELECT id FROM clients WHERE email=\"projeto@example.com\")")->fetchAll() as $f) App\Files::remove($f);
Db::q("DELETE FROM notifications WHERE link LIKE ?", ["/projetos/".$argv[1]."%"]); Db::q("DELETE FROM clients WHERE email=\"projeto@example.com\""); Db::q("DELETE FROM users WHERE email LIKE \"d-%@example.com\""); Db::q("DELETE FROM login_attempts");' $PJ
rm -r "$S"
