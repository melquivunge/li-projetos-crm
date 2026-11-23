#!/usr/bin/env bash
# Fase F (portal do cliente) contra o Docker local: bash tests/e2e-portal.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12); CP="senha-cliente-$(openssl rand -hex 4)"
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "F Admin" f-admin@example.com >/dev/null
php_ 'use App\Db; Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (\"F Comercial\",\"f-com@example.com\",?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", [password_hash($argv[1],PASSWORD_DEFAULT)]);
  $id=Db::q("SELECT id FROM users WHERE email=\"f-com@example.com\"")->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?,\"comercial\")",[$id]); Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm f-admin@example.com; login com f-com@example.com
tok(){ curl -s -b $S/$1.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4; }
T=$(tok adm); TC=$(tok com)
post(){ local t=$T; [ $1 = com ] && t=$TC; curl -s -o /dev/null -w "%{http_code}" -b $S/$1.jar --data-urlencode "_csrf=$t" "${@:3}" "$B$2"; }
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/planta.pdf

# Two clients, each with an accepted proposal → project; A gets one sent and one unsent deliverable, B one sent
mk(){ # name email → "CID PJ"
  local L CID PROP; L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "name=$1" -d "whatsapp=61977775555" -d "email=$2" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
  L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" --data-urlencode "title=Projeto $1" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" --data-urlencode "payment_terms=50% + 50%" --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=20 dias" $B/clientes/$CID/propostas); PROP=${L##*/}
  post adm /propostas/$PROP/status -d status=aceita >/dev/null; echo "$CID $(q "SELECT id FROM projects WHERE proposal_id=?" $PROP)"; }
deliv(){ # project title send? → deliverable id
  post adm /projetos/$1/entregas --data-urlencode "title=$2" >/dev/null; local D; D=$(q "SELECT id FROM deliverables WHERE project_id=? AND title=?" $1 "$2")
  curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/planta.pdf" $B/entregas/$D; [ $3 = 1 ] && post adm /entregas/$D/enviar >/dev/null; echo $D; }
read CA PA <<<"$(mk 'Cliente Portal A' f-portal-a@example.com)"; read CB PB <<<"$(mk 'Cliente Portal B' f-portal-b@example.com)"
DA=$(deliv $PA "Planta humanizada" 1); DU=$(deliv $PA "Rascunho interno" 0); DB=$(deliv $PB "Planta do B" 1)
FA=$(q "SELECT id FROM files WHERE owner_type='deliverable' AND owner_id=?" $DA); FU=$(q "SELECT id FROM files WHERE owner_type='deliverable' AND owner_id=?" $DU); FB=$(q "SELECT id FROM files WHERE owner_type='deliverable' AND owner_id=?" $DB)

echo "comercial convida cliente de outra pessoa: $(post com /clientes/$CA/portal)"
W=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" $B/clientes/$CA/portal)
IT=$(python3 -c 'import sys,re,urllib.parse as u; m=re.search(r"/portal/convite/([a-f0-9]{64})", u.unquote(sys.argv[1])); print(m.group(1) if m else "")' "$W")
echo "convite pelo WhatsApp: $([ -n "$IT" ] && echo "link ok" || echo "SEM LINK: $W") | token guardado em claro: $(q "SELECT COUNT(*) FROM client_users WHERE invite_hash=?" $IT)"
echo "e-mail de outro cliente: $(php_ 'var_export(App\Portal::invite((int)$argv[1], "f-portal-a@example.com"));' $CB)"
echo "convite: $(curl -s -o /dev/null -w %{http_code} $B/portal/convite/$IT) | token falso: $(curl -s -o /dev/null -w %{http_code} $B/portal/convite/$(openssl rand -hex 32))"
J=$S/cli.jar; CT=$(curl -s -c $J $B/portal/convite/$IT | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4)
cpost(){ curl -s -o /dev/null -w "%{http_code}" -b $J -c $J --data-urlencode "_csrf=$CT" "${@:2}" "$B$1"; }
echo "senha curta: $(cpost /portal/convite/$IT -d password=curta -d password2=curta) | senhas diferentes: $(cpost /portal/convite/$IT -d password=$CP -d password2=${CP}x) | sem CSRF: $(curl -s -o /dev/null -w %{http_code} -b $J -d password=$CP -d password2=$CP $B/portal/convite/$IT)"
echo "cria a senha: $(cpost /portal/convite/$IT -d password=$CP -d password2=$CP) | convite de novo: $(curl -s -o /dev/null -w %{http_code} $B/portal/convite/$IT) | cookie: $(grep -o 'li_portal' $J | head -1) path $(awk '/li_portal/{print $3}' $J)"
H=$(curl -s -b $J $B/portal); echo "$H" > $S/portal.html
echo "portal: projeto A $(echo "$H" | grep -c 'Projeto Cliente Portal A') | projeto B $(echo "$H" | grep -c 'Portal B') | entrega enviada $(echo "$H" | grep -c 'Planta humanizada') | rascunho $(echo "$H" | grep -c 'Rascunho interno') | pendência $(echo "$H" | grep -c 'Ver e aprovar') | parcelas $(echo "$H" | grep -c 'Em aberto\|Vencida')"
echo "PDF enviado, projeto sem quitar (anti-calote, espera 403): $(curl -s -o /dev/null -w '%{http_code} %{content_type}' -b $J $B/portal/arquivos/$FA) | não enviado: $(curl -s -o /dev/null -w %{http_code} -b $J $B/portal/arquivos/$FU) | de outro cliente: $(curl -s -o /dev/null -w %{http_code} -b $J $B/portal/arquivos/$FB) | sem login: $(curl -s -o /dev/null -w %{http_code} $B/portal/arquivos/$FA)"
echo "sessões separadas: equipe em /portal → $(curl -s -o /dev/null -w %{redirect_url} -b $S/adm.jar $B/portal | sed 's#.*8080##') | cliente em /clientes → $(curl -s -o /dev/null -w %{redirect_url} -b $J $B/clientes | sed 's#.*8080##')"
cpost /portal/sair >/dev/null; echo "depois de sair: $(curl -s -o /dev/null -w %{redirect_url} -b $J $B/portal | sed 's#.*8080##')"

L2=$S/l.jar; LT=$(curl -s -c $L2 $B/portal/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4)
lpost(){ curl -s -o /dev/null -w "%{http_code}" -b $L2 -c $L2 --data-urlencode "_csrf=$LT" -d email=f-portal-a@example.com --data-urlencode "password=$1" $B/portal/login; }
for i in 1 2 3 4 5; do lpost errada >/dev/null; done
echo "5 senhas erradas, depois a certa: $(curl -s -b $L2 -c $L2 --data-urlencode "_csrf=$LT" -d email=f-portal-a@example.com --data-urlencode "password=$CP" $B/portal/login | grep -o "Muitas tentativas" | head -1) | conta da equipe com o mesmo limite intacta: $(q "SELECT COUNT(*) FROM login_attempts WHERE email='f-portal-a@example.com'")"
php_ 'App\Db::q("DELETE FROM login_attempts WHERE email LIKE \"portal:f-portal%\"");'
echo "login certo: $(lpost $CP) → $(curl -s -o /dev/null -w %{http_code} -b $L2 $B/portal)"
post adm /clientes/$CA/portal -d action=revoke >/dev/null
echo "acesso desativado: sessão aberta → $(curl -s -o /dev/null -w %{redirect_url} -b $L2 $B/portal | sed 's#.*8080##') | login → $(lpost $CP)"
echo "ficha do cliente: $(curl -s -b $S/adm.jar $B/clientes/$CA | grep -o 'Portal do cliente' | head -1)"
echo "avisos do PHP: $( (curl -s -b $S/adm.jar $B/clientes/$CA; cat $S/portal.html; curl -s $B/portal/login; curl -s $B/portal/convite/$IT) | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"

php_ 'use App\Db; foreach (Db::q("SELECT * FROM files WHERE client_id IN (SELECT id FROM clients WHERE email LIKE \"f-portal-%@example.com\")")->fetchAll() as $f) App\Files::remove($f);
Db::q("DELETE FROM approvals WHERE entity=\"proposal\" AND entity_id IN (SELECT id FROM proposals WHERE client_id IN (SELECT id FROM clients WHERE email LIKE \"f-portal-%@example.com\"))");
Db::q("DELETE FROM notifications WHERE link LIKE ? OR link LIKE ?", ["/projetos/".$argv[1]."%", "/projetos/".$argv[2]."%"]);
Db::q("DELETE FROM clients WHERE email LIKE \"f-portal-%@example.com\""); Db::q("DELETE FROM users WHERE email LIKE \"f-%@example.com\""); Db::q("DELETE FROM login_attempts WHERE email LIKE \"%f-%@example.com\"");' $PA $PB
rm -r "$S"
