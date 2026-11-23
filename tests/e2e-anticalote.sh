#!/usr/bin/env bash
# Anti-calote contra o Docker local: bash tests/e2e-anticalote.sh. Apaga só o que cria.
set -u; B=http://localhost:8080; S=$(mktemp -d); P=$(openssl rand -hex 12)
php_(){ docker compose exec -T app php -r "require 'src/bootstrap.php'; $1" "${@:2}"; }
q(){ php_ "echo App\\Db::q(\"$1\", array_slice(\$argv, 1))->fetchColumn();" "${@:2}"; }
docker compose exec -T -e ADMIN_PASSWORD=$P app php bin/create-admin.php "AC Admin" ac-admin@example.com >/dev/null
J=$S/adm.jar; T=$(curl -s -c $J $B/login | grep -o 'name="_csrf" value="[^"]*' | cut -d'"' -f4)
curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" -d email=ac-admin@example.com --data-urlencode "password=$P" $B/login
T=$(curl -s -b $J $B/tarefas | grep -o 'name="_csrf" value="[^"]*' | head -1 | cut -d'"' -f4)
post(){ curl -s -o /dev/null -w "%{http_code}" -b $J --data-urlencode "_csrf=$T" "${@:2}" "$B$1"; }
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $J --data-urlencode "_csrf=$T" -d "name=Cliente Calote" -d "whatsapp=61977774444" -d "email=ac-cliente@example.com" -d "service=Design de Interiores" $B/clientes); CID=${L##*/}
L=$(curl -s -o /dev/null -w "%{redirect_url}" -b $J --data-urlencode "_csrf=$T" --data-urlencode "title=Projeto Calote" -d "valid_until=$(date -v+15d +%F)" -d "qty[interiores]=80" --data-urlencode "payment_terms=50% + 50%" --data-urlencode "stage[]=Conceito" --data-urlencode "duration[]=20 dias" $B/clientes/$CID/propostas); PROP=${L##*/}
post /propostas/$PROP/status -d status=aceita >/dev/null; PJ=$(q "SELECT id FROM projects WHERE proposal_id=?" $PROP)
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF' > $S/executivo.pdf
sips -s format jpeg -Z 1600 ~/Desktop/Website/assets/images/projetos/render-3d-quarto-casal-design-de-interiores-li-projetos.webp --out $S/render.jpg >/dev/null
post /projetos/$PJ/entregas -d "title=Projeto final" >/dev/null; DV=$(q "SELECT id FROM deliverables WHERE project_id=?" $PJ)
for f in executivo.pdf render.jpg; do curl -s -o /dev/null -b $J --form-string _csrf=$T -F "files[]=@$S/$f" $B/entregas/$DV; done
post /entregas/$DV/enviar >/dev/null; TK=$(q "SELECT public_token FROM deliverables WHERE id=?" $DV)
FP=$(q "SELECT id FROM files WHERE owner_id=? AND name='executivo.pdf'" $DV); FI=$(q "SELECT id FROM files WHERE owner_id=? AND name='render.jpg'" $DV)
ORIG=$(wc -c < $S/render.jpg | tr -d ' ')
echo "parcelas: $(q "SELECT COUNT(*) FROM installments WHERE project_id=?" $PJ) | contrato $(q "SELECT contract_value FROM projects WHERE id=?" $PJ)"
curl -s -o $S/prev.jpg $B/e/$TK/arquivos/$FI
echo "SEM PAGAR → imagem: $(file -b --mime-type $S/prev.jpg) $(sips -g pixelWidth $S/prev.jpg | awk '/pixelWidth/{print $2}')px (original ${ORIG} bytes, recebido $(wc -c < $S/prev.jpg | tr -d ' ')) | PDF: $(curl -s -o /dev/null -w %{http_code} $B/e/$TK/arquivos/$FP) | baixar original: $(curl -s -o /dev/null -w '%{content_type}' "$B/e/$TK/arquivos/$FI?baixar=1") | página avisa: $(curl -s $B/e/$TK | grep -c 'Liberado após a quitação')"
php_ 'App\Db::q("UPDATE installments SET due_date = CURDATE() - INTERVAL 6 DAY WHERE project_id=? AND number=1", [$argv[1]]);' $PJ
docker compose exec -T app php bin/cron.php >/dev/null; echo "6 dias de atraso: $(q "SELECT status FROM projects WHERE id=?" $PJ)"
php_ 'App\Db::q("UPDATE installments SET due_date = CURDATE() - INTERVAL 7 DAY WHERE project_id=? AND number=1", [$argv[1]]);' $PJ
docker compose exec -T app php bin/cron.php | grep -o '"pausados":[0-9]*'; echo "7 dias: $(q "SELECT CONCAT(status,' ',IFNULL(hold_reason,'-')) FROM projects WHERE id=?" $PJ) | sino: $(q "SELECT COUNT(*) FROM notifications WHERE link=? AND body LIKE '%pausado%'" /projetos/$PJ)"
echo "nova proposta para o inadimplente: $(curl -s -b $J $B/clientes/$CID/propostas/nova | grep -o 'Inadimplente:</strong> este cliente tem R\$ [0-9.,]* vencido' | head -1)"
post /projetos/$PJ/entregas -d "title=Nova entrega" >/dev/null; DV2=$(q "SELECT id FROM deliverables WHERE project_id=? AND title='Nova entrega'" $PJ)
curl -s -o /dev/null -b $J --form-string _csrf=$T -F "files[]=@$S/executivo.pdf" $B/entregas/$DV2; post /entregas/$DV2/enviar >/dev/null
echo "equipe tenta enviar entrega com projeto pausado: enviada=$(q "SELECT IF(sent_at IS NULL,'não','SIM') FROM deliverables WHERE id=?" $DV2) | aviso na ficha: $(curl -s -b $J $B/projetos/$PJ | grep -c 'Pausado por pagamento em atraso')"
I1=$(q "SELECT id FROM installments WHERE project_id=? AND number=1" $PJ); I2=$(q "SELECT id FROM installments WHERE project_id=? AND number=2" $PJ)
post /parcelas/$I1/pagar -d method=pix >/dev/null
echo "pagou a 1ª: $(q "SELECT CONCAT(status,' ',IFNULL(hold_reason,'-')) FROM projects WHERE id=?" $PJ) | PDF ainda: $(curl -s -o /dev/null -w %{http_code} $B/e/$TK/arquivos/$FP)"
post /parcelas/$I2/cancelar >/dev/null; echo "cancelou a 2ª (não quita): PDF $(curl -s -o /dev/null -w %{http_code} $B/e/$TK/arquivos/$FP)"
post /parcelas/$I2/estornar >/dev/null; post /parcelas/$I2/pagar -d method=pix >/dev/null
curl -s -o $S/full.jpg $B/e/$TK/arquivos/$FI
echo "QUITADO → PDF: $(curl -s -o /dev/null -w '%{http_code} %{content_type}' $B/e/$TK/arquivos/$FP) | imagem original: $(cmp -s $S/full.jpg $S/render.jpg && echo idêntica || echo DIFERENTE) | aviso some: $(curl -s $B/e/$TK | grep -c 'Liberado após a quitação')"
post /parcelas/$I2/estornar >/dev/null; echo "estorno volta a travar: PDF $(curl -s -o /dev/null -w %{http_code} $B/e/$TK/arquivos/$FP)"
echo "avisos do PHP: $( (curl -s -b $J $B/projetos/$PJ; curl -s $B/e/$TK) | grep -cE 'Warning:|Notice:|Fatal error|Deprecated:|Uncaught')"
php_ 'use App\Db; foreach (Db::q("SELECT * FROM files WHERE client_id IN (SELECT id FROM clients WHERE email=\"ac-cliente@example.com\")")->fetchAll() as $f) App\Files::remove($f);
Db::q("DELETE FROM approvals WHERE entity=\"proposal\" AND entity_id=?", [$argv[2]]); Db::q("DELETE FROM notifications WHERE link LIKE ?", ["/projetos/".$argv[1]."%"]);
Db::q("DELETE FROM clients WHERE email=\"ac-cliente@example.com\""); Db::q("DELETE FROM users WHERE email=\"ac-admin@example.com\""); Db::q("DELETE FROM login_attempts");' $PJ $PROP
cp $S/prev.jpg "${KEEP_PREVIEW:-/dev/null}" 2>/dev/null; rm -r "$S"
