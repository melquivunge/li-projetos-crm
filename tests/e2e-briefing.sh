#!/usr/bin/env bash
# Fase C (briefing e arquivos) contra o Docker local. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "C Admin" c-admin@example.com >/dev/null
docker compose exec -T app php -r 'require "src/bootstrap.php"; use App\Db;
Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\"equipe\") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)", ["C Comercial","c-com@example.com",password_hash($argv[1],PASSWORD_DEFAULT)]);
$id=Db::q("SELECT id FROM users WHERE email=\"c-com@example.com\"")->fetchColumn(); Db::q("DELETE FROM user_roles WHERE user_id=?",[$id]); Db::q("INSERT INTO user_roles VALUES (?, \"comercial\")",[$id]); Db::q("DELETE FROM login_attempts");' $P
login(){ J=$S/$1.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4); curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" --data-urlencode "email=$2" --data-urlencode "password=$P" $B/login; }
login adm c-admin@example.com; login com c-com@example.com
T=$(curl -s -b $S/adm.jar $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d "name=Cliente Briefing" -d "whatsapp=61944443333" -d "email=briefing@example.com" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
OID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM opportunities WHERE client_id=?",[$argv[1]])->fetchColumn();' $CID)
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" $B/oportunidades/$OID/briefing); BID=${L##*/}
echo "briefing #$BID criado com $(curl -s -b $S/adm.jar $B/briefings/$BID | grep -c 'name="a\[') perguntas"
TOKEN=$(curl -s -b $S/adm.jar $B/briefings/$BID | grep -o '/b/[a-f0-9]\{64\}' | head -1 | cut -d/ -f3)
echo "link antes do envio: $(curl -s -o /dev/null -w %{http_code} $B/b/$TOKEN)"
sips -s format jpeg -Z 400 ~/Desktop/Website/assets/images/projetos/render-3d-quarto-casal-design-de-interiores-li-projetos.webp --out $S/foto.jpg >/dev/null
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/planta.pdf; printf '<?php echo 1;' > $S/evil.jpg  # harmless: a real webshell signature is blocked by the Mac before curl reads it
curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T -F "files[]=@$S/foto.jpg" $B/briefings/$BID
echo "envio → WhatsApp: $(curl -s -o /dev/null -w %{redirect_url} -b $S/adm.jar --form-string _csrf=$T $B/briefings/$BID/enviar | cut -c1-40)… etapa: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT stage FROM opportunities WHERE id=?",[$argv[1]])->fetchColumn();' $OID)"
J=$S/cli.jar; CT=$(curl -s -c $J $B/b/$TOKEN | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
K1=$(curl -s -b $J $B/b/$TOKEN | grep -o 'name="a\[[a-f0-9]*\]"' | head -1 | grep -o '[a-f0-9]\{10\}')
R=$(curl -s -o /dev/null -w %{redirect_url} -b $J -c $J --form-string _csrf=$CT --form-string "a[$K1]=Apartamento" -F "files[]=@$S/planta.pdf;type=application/pdf" -F "files[]=@$S/evil.jpg;type=image/jpeg" -F confirm=1 --form-string signer=Ana $B/b/$TOKEN)
echo "cliente responde (nome curto + PHP disfarçado): $R | erro: $(curl -s -b $J $B/b/$TOKEN | grep -o 'evil.jpg: [^.<]*\|digite o seu nome completo' | tr '\n' ' ')"
curl -s -o /dev/null -b $J -c $J --form-string _csrf=$CT --form-string "a[$K1]=Apartamento" -F confirm=1 --form-string 'signer=Ana Cliente Teste' $B/b/$TOKEN
echo "validado: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT CONCAT(status, \" v\", version) FROM briefings WHERE id=?",[$argv[1]])->fetchColumn();' $BID) | aprovação: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT name FROM approvals WHERE entity=\"briefing\" AND entity_id=?",[$argv[1]])->fetchColumn();' $BID)"
echo "arquivos: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; foreach (App\Db::q("SELECT name, mime, from_client FROM files WHERE owner_type=\"briefing\" AND owner_id=?",[$argv[1]]) as $f) echo $f["name"], "(", $f["mime"], $f["from_client"] ? ",cliente" : ",equipe", ") ";' $BID)"
FID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM files WHERE owner_type=\"briefing\" AND owner_id=? AND from_client=0",[$argv[1]])->fetchColumn();' $BID)
CFID=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT id FROM files WHERE owner_type=\"briefing\" AND owner_id=? AND from_client=1",[$argv[1]])->fetchColumn();' $BID)
echo "arquivo: equipe $(curl -s -o /dev/null -w '%{http_code} %{content_type}' -b $S/adm.jar $B/arquivos/$FID) | sem login $(curl -s -o /dev/null -w %{http_code} $B/arquivos/$FID) | comercial de outro cliente $(curl -s -o /dev/null -w %{http_code} -b $S/com.jar $B/arquivos/$FID)"
echo "pelo link: arquivo do cliente $(curl -s -o /dev/null -w %{http_code} $B/b/$TOKEN/arquivos/$CFID) | arquivo da equipe $(curl -s -o /dev/null -w %{http_code} $B/b/$TOKEN/arquivos/$FID)"
echo "cabeçalho: $(curl -s -D - -o /dev/null -b $S/adm.jar $B/arquivos/$FID | grep -i 'content-security-policy' | tr -d '\r' | cut -c1-60)"
curl -s -o /dev/null -b $S/adm.jar --form-string _csrf=$T --form-string "a[$K1]=Casa" $B/briefings/$BID
echo "equipe altera após validação: $(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT CONCAT(status, \" v\", version) FROM briefings WHERE id=?",[$argv[1]])->fetchColumn();' $BID)"
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d kind=reuniao $B/oportunidades/$OID/briefing); MID=${L##*/}
MP=$(curl -s -b $S/adm.jar $B/briefings/$MID)
echo "reunião inicial #$MID: perguntas $(echo "$MP" | grep -c 'name="a\[') | blocos $(echo "$MP" | grep -o 'class="brief-title"' | wc -l | tr -d ' ') | falas $(echo "$MP" | grep -o 'class="brief-script"' | wc -l | tr -d ' ') | B24 $(echo "$MP" | grep -c 'B24 —') | botão de envio $(echo "$MP" | grep -c 'Enviar ao cliente')"
L2=$(curl -s -o /dev/null -w "%{redirect_url}" -b $S/adm.jar --data-urlencode "_csrf=$T" -d kind=reuniao $B/oportunidades/$OID/briefing)
MT=$(docker compose exec -T app php -r 'require "src/bootstrap.php"; echo App\Db::q("SELECT public_token FROM briefings WHERE id=?",[$argv[1]])->fetchColumn();' $MID)
echo "criar de novo abre o mesmo: $([ "${L2##*/}" = "$MID" ] && echo sim || echo NÃO) | enviar ao cliente: $(curl -s -o /dev/null -w %{http_code} -b $S/adm.jar --form-string _csrf=$T $B/briefings/$MID/enviar) | link público: $(curl -s -o /dev/null -w %{http_code} $B/b/$MT) | ficha mostra: $(curl -s -b $S/adm.jar $B/clientes/$CID | grep -c '>Reunião inicial</a>')"
echo "comercial abre briefing alheio: $(curl -s -o /dev/null -w %{http_code} -b $S/com.jar $B/briefings/$BID)"
docker compose exec -T app php -r 'require "src/bootstrap.php"; use App\Db; foreach (Db::q("SELECT * FROM files WHERE client_id IN (SELECT id FROM clients WHERE email=\"briefing@example.com\")")->fetchAll() as $f) App\Files::remove($f);
Db::q("DELETE FROM approvals WHERE entity=\"briefing\" AND name=\"Ana Cliente Teste\""); Db::q("DELETE FROM clients WHERE email=\"briefing@example.com\""); Db::q("DELETE FROM users WHERE email LIKE \"c-%@example.com\""); Db::q("DELETE FROM login_attempts");'
rm -r "$S"
