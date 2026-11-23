#!/usr/bin/env bash
# Fase E (revisões e aprovações) contra o Docker local: bash tests/e2e-revisions.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "E Admin" e-admin@example.com >/dev/null
php_ 'use App\Db; foreach ([["E Designer","e-des@example.com","designer"],["E Comercial","e-com@example.com","comercial"]] as [$n,$m,$r]) {
  Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [$n,$m,password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=?",[$m])->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,?)",[$id,$r]); } Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm e-admin@example.com; login des e-des@example.com; login com e-com@example.com
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
T=$(tok adm); TD=$(tok des); TC=$(tok com)
post(){ local t=$T; [ $1 = des ] && t=$TD; [ $1 = com ] && t=$TC; curl -s -o /dev/null -w "%{http_code}" -b $S/$1.jar --data-urlencode "_csrf=$t" "${@:3}" "$B$2"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }

L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d "name=Cliente Revisão" -d "whatsapp=61977776666" -d "email=revisao@example.com" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "title=Interiores revisão" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" \
  --data-urlencode "revision_policy=Inclui 1 rodada de revisão em cada etapa." --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=20 dias" $B/clientes/$CID/propostas); PROP=${L##*/}
post adm /propostas/$PROP/status -d status=aceita >/dev/null
PJ=$(q "SELECT id FROM projects WHERE proposal_id=?" $PROP); PH=$(q "SELECT id FROM project_phases WHERE project_id=? ORDER BY sort LIMIT 1" $PJ)
echo "projeto #$PJ: rodadas incluídas = $(q "SELECT revisions_included FROM projects WHERE id=?" $PJ) (proposta diz 1)"

post adm /projetos/$PJ/entregas -d "title=Conceito e moodboard" -d phase_id=$PH >/dev/null; post adm /projetos/$PJ/entregas -d "title=Outro" >/dev/null
DV=$(q "SELECT id FROM deliverables WHERE project_id=? AND title='Conceito e moodboard'" $PJ); DV2=$(q "SELECT id FROM deliverables WHERE project_id=? AND title='Outro'" $PJ)
echo "enviar sem arquivo: $(post adm /entregas/$DV/enviar >/dev/null; q "SELECT IFNULL(public_token,'sem link') FROM deliverables WHERE id=?" $DV | cut -c1-8)"
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/conceito.pdf
sips -s format jpeg -Z 300 ~/Desktop/Website/assets/images/projetos/render-3d-quarto-casal-design-de-interiores-li-projetos.webp --out $S/moodboard.jpg >/dev/null
for f in conceito.pdf moodboard.jpg; do curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/$f" $B/entregas/$DV; done
curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/conceito.pdf" $B/entregas/$DV2
W=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" $B/entregas/$DV/enviar)
TK=$(q "SELECT public_token FROM deliverables WHERE id=?" $DV)
echo "enviar → $(echo $W | cut -c1-30)… link no texto: $(python3 -c 'import sys,urllib.parse as u; print("sim" if "/e/"+sys.argv[2] in u.unquote(sys.argv[1]) else "NÃO")' "$W" "$TK") | status: $(q "SELECT status FROM deliverables WHERE id=?" $DV)"
echo "link público: $(curl -s -o /dev/null -w %{http_code} $B/e/$TK) | imagem $(curl -s -o /dev/null -w '%{http_code} %{content_type}' $B/e/$TK/arquivos/$(q "SELECT id FROM files WHERE owner_type='deliverable' AND owner_id=? AND name='moodboard.jpg'" $DV)) | arquivo de outro entregável $(curl -s -o /dev/null -w %{http_code} $B/e/$TK/arquivos/$(q "SELECT id FROM files WHERE owner_type='deliverable' AND owner_id=?" $DV2)) | token falso $(curl -s -o /dev/null -w %{http_code} $B/e/$(openssl rand -hex 32))"

J=$S/cli.jar; CT=$(curl -s -c $J $B/e/$TK | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
cpost(){ curl -s -o /dev/null -w "%{redirect_url}" -b $J -c $J --data-urlencode "_csrf=$CT" "$@" $B/e/$TK; }
cpost -d action=adjust --data-urlencode "description=Trocar o sofá por um verde" -d "signer=Ana" >/dev/null
echo "pedido com nome curto: $(q "SELECT COUNT(*) FROM revision_requests WHERE project_id=?" $PJ) pedidos | msg: $(curl -s -b $J $B/e/$TK | grep -o 'Digite o seu nome completo' | head -1)"
cpost -d action=adjust --data-urlencode "description=Trocar o sofá por um verde" --data-urlencode "signer=Ana Cliente" >/dev/null
echo "1ª rodada do cliente: $(php_ 'echo implode(" ", App\Db::q("SELECT type, round, is_extra, status, from_client FROM revision_requests WHERE project_id=? ORDER BY id DESC LIMIT 1",[$argv[1]])->fetch(PDO::FETCH_NUM));' $PJ) | entregável: $(q "SELECT status FROM deliverables WHERE id=?" $DV) | sino da equipe: $(q "SELECT COUNT(*) FROM notifications WHERE body LIKE '%pediu ajustes%' AND link LIKE ?" "/projetos/$PJ%")"
cpost -d action=adjust --data-urlencode "description=Mudar também a cor da parede" --data-urlencode "signer=Ana Cliente" >/dev/null
R2=$(q "SELECT id FROM revision_requests WHERE project_id=? ORDER BY id DESC LIMIT 1" $PJ)
echo "2ª rodada (só 1 incluída): $(php_ 'echo implode(" ", App\Db::q("SELECT round, is_extra, status FROM revision_requests WHERE id=?",[$argv[1]])->fetch(PDO::FETCH_NUM));' $R2) | aviso ao cliente: $(curl -s -b $J $B/e/$TK | grep -o 'passa das rodadas de revisão incluídas' | head -1)"
post adm /revisoes/$R2 -d action=start >/dev/null
cpost -d action=approve_cost -d revision_id=$R2 -d agree=1 --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "começar sem custo aprovado / cliente aprova sem valor definido: $(q "SELECT status FROM revision_requests WHERE id=?" $R2)"
post adm /revisoes/$R2 -d action=cost --data-urlencode "extra_cost=300,00" >/dev/null
echo "valor na página do cliente: $(curl -s -b $J $B/e/$TK | grep -o 'Concordo com o valor de R\$ 300,00' | head -1)"
cpost -d action=approve_cost -d revision_id=$R2 --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "sem marcar 'concordo': $(q "SELECT status FROM revision_requests WHERE id=?" $R2)"
cpost -d action=approve_cost -d revision_id=$R2 -d agree=1 --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "cliente aprovou o custo: $(q "SELECT status FROM revision_requests WHERE id=?" $R2) | parcela: $(php_ 'echo implode(" ", App\Db::q("SELECT i.description, i.amount FROM installments i JOIN revision_requests r ON r.installment_id = i.id WHERE r.id=?",[$argv[1]])->fetch(PDO::FETCH_NUM) ?: ["nenhuma"]);' $R2) | aprovação: $(q "SELECT CONCAT(decision,' por ',name) FROM approvals WHERE entity='revision' AND entity_id=?" $R2)"
post adm /revisoes/$R2 -d action=start >/dev/null; s1=$(q "SELECT status FROM revision_requests WHERE id=?" $R2); post adm /revisoes/$R2 -d action=done >/dev/null
echo "começar → $s1 → concluir → $(q "SELECT status FROM revision_requests WHERE id=?" $R2)"

post adm /projetos/$PJ/revisoes -d type=correcao -d phase_id=$PH --data-urlencode "description=Cota errada na planta" >/dev/null
post adm /projetos/$PJ/revisoes -d type=escopo -d phase_id=$PH --data-urlencode "description=Incluir a varanda no projeto" --data-urlencode "extra_cost=1.500,00" >/dev/null
RE=$(q "SELECT id FROM revision_requests WHERE project_id=? AND type='escopo'" $PJ)
echo "correção: $(q "SELECT CONCAT(status,' extra=',is_extra) FROM revision_requests WHERE project_id=? AND type='correcao'" $PJ) | escopo: $(q "SELECT CONCAT(status,' extra=',is_extra,' ',extra_cost) FROM revision_requests WHERE id=?" $RE)"
post adm /revisoes/$RE -d action=approve_cost -d "name=Ana por WhatsApp" >/dev/null
echo "equipe registra aprovação do escopo: $(q "SELECT status FROM revision_requests WHERE id=?" $RE) | parcelas extras: $(q "SELECT COUNT(*) FROM installments WHERE project_id=? AND (description LIKE 'Revisão extra%' OR description LIKE 'Mudança de escopo%')" $PJ)"
echo "permissões: comercial mexe $(post com /revisoes/$RE -d action=done) | designer sem tarefa no projeto $(post des /projetos/$PJ/revisoes -d type=revisao --data-urlencode 'description=x') | ação inválida $(post adm /revisoes/$RE -d action=hack)"

curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/conceito.pdf" $B/entregas/$DV
cpost -d action=approve --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "aprovar sem 'conferi': $(q "SELECT status FROM deliverables WHERE id=?" $DV)"
cpost -d action=approve -d agree=1 --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "cliente aprovou: $(q "SELECT status FROM deliverables WHERE id=?" $DV) | registro: $(php_ 'echo implode(" ", App\Db::q("SELECT decision, name, version, IF(user_id IS NULL, \"pelo link\", \"equipe\") FROM approvals WHERE entity=\"deliverable\" AND entity_id=?",[$argv[1]])->fetch(PDO::FETCH_NUM));' $DV) | página: $(curl -s $B/e/$TK | grep -o 'Aprovado por Ana Cliente' | head -1)"
cpost -d action=adjust --data-urlencode "description=Mais uma coisa" --data-urlencode 'signer=Ana Cliente' >/dev/null
echo "ajuste depois de aprovado: $(curl -s -b $J $B/e/$TK | grep -o 'já foi aprovado' | head -1) | sem CSRF: $(curl -s -o /dev/null -w %{http_code} -d action=approve $B/e/$TK)"
post adm /entregas/$DV2/aprovar -d "name=Ana em reunião" >/dev/null
echo "equipe registra aprovação: $(q "SELECT CONCAT(decision,' por ',name,' v',version) FROM approvals WHERE entity='deliverable' AND entity_id=?" $DV2)"
echo "avisos do PHP: $( (for u in /projetos/$PJ /financeiro /clientes/$CID; do curl -s -b $S/adm.jar "$B$u"; done; curl -s $B/e/$TK) | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"

php_ 'use App\Db; foreach (Db::q("SELECT * FROM files WHERE client_id IN (SELECT id FROM clients WHERE email=\"revisao@example.com\")")->fetchAll() as $f) App\Files::remove($f);
Db::q("DELETE FROM approvals WHERE (entity=\"deliverable\" AND entity_id IN (SELECT id FROM deliverables WHERE project_id=?)) OR (entity=\"revision\" AND entity_id IN (SELECT id FROM revision_requests WHERE project_id=?))", [$argv[1], $argv[1]]);
Db::q("DELETE FROM notifications WHERE link LIKE ?", ["/projetos/".$argv[1]."%"]); Db::q("DELETE FROM clients WHERE email=\"revisao@example.com\""); Db::q("DELETE FROM users WHERE email LIKE \"e-%@example.com\""); Db::q("DELETE FROM login_attempts");' $PJ
rm -r "$S"
