#!/usr/bin/env bash
# Perfil do cliente contra o Docker local: bash tests/e2e-profile.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "PF Admin" pf-admin@example.com >/dev/null
J=$S/adm.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4)
curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" -d email=pf-admin@example.com --data-urlencode "password=$P" $B/login
T=$(curl -s -b $J $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
base=(--data-urlencode "_csrf=$T" -d "name=Cliente Perfil" -d "whatsapp=61977773333" -d "email=pf-cliente@example.com" -d "service=Design de Interiores" -d "city=Brasília")
echo "CPF inválido: $(curl -s -o $S/err.html -w %{http_code} -b $J "${base[@]}" -d "document=529.982.247-24" $B/clientes) $(grep -o 'CPF ou CNPJ inválido' $S/err.html | head -1) | valores mantidos: $(grep -c 'value="Cliente Perfil"' $S/err.html)"
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $J "${base[@]}" -d "document=52998224725" -d "rg=1234567 SSP/DF" -d "address_zip=70040010" --data-urlencode "address_street=SQN 210 Bloco A" -d "address_number=101" -d "address_district=Asa Norte" -d "address_state=df" \
  -d "property_type=apartamento" -d "property_status=reforma" --data-urlencode "property_condo=Edifício Ipê" -d "birth_date=1988-03-15" -d "profession=Médica" --data-urlencode "partner_name=Carlos Perfil" -d "partner_whatsapp=61988887777" -d "contact_pref=whatsapp" --data-urlencode "contact_time=depois das 18h" --data-urlencode "referral=indicação da Ana" $B/clientes); CID=${L##*/}
echo "salvo: $(php_ 'echo implode(" | ", App\Db::q("SELECT document, address_zip, address_state, property_type, birth_date, partner_whatsapp, contact_pref FROM clients WHERE id=?",[$argv[1]])->fetch(PDO::FETCH_NUM));' $CID)"
H=$(curl -s -b $J $B/clientes/$CID)
echo "ficha: CPF $(echo "$H" | grep -c '529.982.247-25') | endereço $(echo "$H" | grep -c 'SQN 210 Bloco A 101') | imóvel $(echo "$H" | grep -c 'Apartamento · Para reformar · Edifício Ipê') | idade $(echo "$H" | grep -c 'anos)') | selo $(echo "$H" | grep -o 'Sem histórico' | head -1) | aviso de faltando $(echo "$H" | grep -c 'Falta para o contrato')"
curl -s -o /dev/null -b $J --data-urlencode "_csrf=$T" -d "name=Cliente Perfil" -d "whatsapp=61977773333" -d "email=pf-cliente@example.com" -d "service=Design de Interiores" -d "city=Brasília" -d "document=11.222.333/0001-81" $B/clientes/$CID
echo "edição troca para CNPJ e limpa o resto: $(q "SELECT CONCAT(document,' ',IFNULL(address_street,'(vazio)'))FROM clients WHERE id=?" $CID) | ficha avisa: $(curl -s -b $J $B/clientes/$CID | grep -o 'Falta para o contrato: endereço' | head -1)"
echo "lead do site não apaga o perfil (API usa validate, não saveProfile): $(grep -c saveProfile src/Controllers/ApiController.php)"
echo "CEP 70040010: $(curl -s -b $J $B/api/cep/70040010) | CEP inexistente: $(curl -s -o /dev/null -w %{http_code} -b $J $B/api/cep/99999999) | CEP curto: $(curl -s -o /dev/null -w %{http_code} -b $J $B/api/cep/123) | sem login: $(curl -s -o /dev/null -w %{http_code} $B/api/cep/70040010)"
echo "avisos do PHP: $( (curl -s -b $J $B/clientes/$CID; curl -s -b $J $B/clientes/$CID/editar; curl -s -b $J $B/clientes) | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"
php_ 'App\Db::q("DELETE FROM clients WHERE email=\"pf-cliente@example.com\""); App\Db::q("DELETE FROM users WHERE email=\"pf-admin@example.com\""); App\Db::q("DELETE FROM login_attempts");'
rm -r "$S"
