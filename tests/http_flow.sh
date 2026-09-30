#!/usr/bin/env bash
# Genomkör arrangörens och deltagarens flöde mot den inbyggda PHP-servern.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8097}"
BASE="http://127.0.0.1:${PORT}"
WORKDIR="$(mktemp -d)"
ADMIN="$WORKDIR/admin.jar"
PART="$WORKDIR/part.jar"
STAMP="$(date +%s)"
PASS="ett-langt-losenord"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" "$ROOT/public/router.php" >"$WORKDIR/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  if curl -sf -o /dev/null "$BASE/login"; then
    break
  fi
  sleep 0.1
done

csrf_from() {
  python3 -c 'import re,sys; html=sys.stdin.read(); m=re.search(r"name=\"_csrf\" value=\"([^\"]+)\"", html) or re.search(r"name=\"csrf-token\" content=\"([^\"]+)\"", html); print(m.group(1) if m else "")'
}

fail() {
  echo "FEL $1" >&2
  cat "$WORKDIR/server.log" >&2 || true
  exit 1
}

assert_not_leak() {
  local file="$1"
  if grep -E -q 'SQLSTATE|password_hash|stack trace|config.php' "$file"; then
    fail "svaret läcker interna detaljer"
  fi
}

echo "POST utan CSRF ska avvisas"
code=$(curl -s -o "$WORKDIR/nocsrf.html" -w '%{http_code}' -c "$ADMIN" -b "$ADMIN" \
  -d "email=a@example.com&password=x" "$BASE/login")
[[ "$code" == "419" ]] || fail "förväntade 419 utan CSRF, fick $code"
assert_not_leak "$WORKDIR/nocsrf.html"

echo "Skapa konto och logga in"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/register")
token=$(printf '%s' "$page" | csrf_from)
[[ -n "$token" ]] || fail "saknar CSRF på registrering"
curl -s -c "$ADMIN" -b "$ADMIN" -o "$WORKDIR/reg.html" -D "$WORKDIR/reg.hdr" \
  -d "name=Test+Arrangor&email=admin-${STAMP}@example.com&password=${PASS}&password_confirmation=${PASS}&_csrf=${token}" \
  "$BASE/register"
grep -q '/dashboard' "$WORKDIR/reg.hdr" || fail "registrering skickade inte vidare till översikten"
assert_not_leak "$WORKDIR/reg.html"

echo "Skapa möte"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/create")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -D "$WORKDIR/meet.hdr" -o "$WORKDIR/meet.html" \
  --data-urlencode "title=Testmöte ${STAMP}" \
  --data-urlencode "description=Ett möte" \
  --data-urlencode "meeting_date=2026-09-30" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/create"
location=$(awk 'tolower($1)=="location:" {print $2}' "$WORKDIR/meet.hdr" | tr -d '\r')
public_id=$(printf '%s' "$location" | sed -n 's#.*/meeting/\([A-Z0-9]*\).*#\1#p')
[[ -n "$public_id" ]] || fail "saknar mötes-id i $location"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}")
code_value=$(printf '%s' "$page" | python3 -c 'import re,sys; html=sys.stdin.read(); m=re.search(r"data-meeting-code=\"([^\"]+)\"", html); print(m.group(1) if m else "")')
[[ -n "$code_value" ]] || fail "saknar möteskod"
assert_not_leak "$WORKDIR/meet.html"

echo "Annan arrangör ska inte se mötet"
other="$WORKDIR/other.jar"
page=$(curl -s -c "$other" -b "$other" "$BASE/register")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$other" -b "$other" -o /dev/null \
  -d "name=Annan&email=annan-${STAMP}@example.com&password=${PASS}&password_confirmation=${PASS}&_csrf=${token}" \
  "$BASE/register"
code=$(curl -s -o "$WORKDIR/forbidden.html" -w '%{http_code}' -c "$other" -b "$other" "$BASE/meeting/${public_id}")
[[ "$code" == "404" ]] || fail "annan användare fick $code i stället för 404"
assert_not_leak "$WORKDIR/forbidden.html"

echo "Lägg till fält och öppna mötet"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "label=Kommun" \
  --data-urlencode "field_type=select" \
  --data-urlencode "required=1" \
  --data-urlencode $'options=Uppsala\nLund' \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/fields"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "status=open&_csrf=${token}" \
  "$BASE/meeting/${public_id}/status"

echo "Deltagare anmäler sig och väntar"
page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/register")
printf '%s' "$page" | grep -q 'Kommun' || fail "registreringsfältet saknas"
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$PART" -b "$PART" -D "$WORKDIR/join.hdr" -o "$WORKDIR/join.html" \
  --data-urlencode "name=Anna Andersson" \
  --data-urlencode "email=anna-${STAMP}@example.com" \
  --data-urlencode "field[kommun]=Uppsala" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/m/${code_value}/register"
grep -q '/waiting' "$WORKDIR/join.hdr" || fail "deltagaren hamnade inte i vänteläge"
wait_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/waiting")
printf '%s' "$wait_page" | grep -q 'Väntar på godkännande' || fail "väntesidan saknar text"
printf '%s' "$wait_page" | grep -q 'Spara mejlet' || fail "bekräftelsen om mejlet saknas"
mail_file=$(grep -l "anna-${STAMP}@example.com" "$ROOT"/storage/mail/*.txt 2>/dev/null | tail -n 1 || true)
[[ -n "$mail_file" ]] || fail "mejlet skrevs inte"
if grep -q 'Din röst' "$mail_file"; then
  fail "mejlet nämner en röst"
fi
return_url=$(python3 -c 'import re,sys; text=open(sys.argv[1], encoding="utf-8").read(); m=re.search(r"https?://\S+/ater/[a-f0-9]{64}", text); print(m.group(0) if m else "")' "$mail_file")
[[ -n "$return_url" ]] || fail "mejlet saknar återlänk"
printf '%s' "$return_url" | grep -q '/ater/' || fail "återlänken har fel adress"
fresh="$WORKDIR/return.jar"
curl -s -c "$fresh" -b "$fresh" -L -o "$WORKDIR/return.html" "$return_url"
grep -q 'MX_PARTICIPANT' "$fresh" || fail "återlänken satte ingen cookie"
printf '%s' "$(cat "$WORKDIR/return.html")" | grep -q 'Anna Andersson' || fail "återlänken öppnade inte samma anmälan"
printf '%s' "$(cat "$WORKDIR/return.html")" | grep -q 'Väntar på godkännande' || fail "återlänken hamnade inte i vänteläge"
bad=$(curl -s -o "$WORKDIR/badlink.html" -w '%{http_code}' "$BASE/ater/$(python3 -c 'print("ab"*32)')")
[[ "$bad" == "404" ]] || fail "ogiltig återlänk gav $bad"
assert_not_leak "$WORKDIR/badlink.html"
state=$(curl -s -c "$PART" -b "$PART" "$BASE/api/participant/state")
printf '%s' "$state" | grep -q '"participant_status":"pending"' || fail "status-API är inte pending"
code=$(curl -s -o "$WORKDIR/earlyvote.json" -w '%{http_code}' -c "$PART" -b "$PART" "$BASE/api/vote/current")
[[ "$code" == "403" ]] || fail "pending kunde läsa omröstningen ($code)"
assert_not_leak "$WORKDIR/earlyvote.json"

echo "Godkänn och ge rösträtt"
people=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/participants")
printf '%s' "$people" | grep -q 'Anna Andersson' || fail "deltagaren syns inte"
printf '%s' "$people" | grep -q 'Uppsala' || fail "egna fältet syns inte"
participant_id=$(printf '%s' "$people" | sed -n 's#.*/participants/\([A-Z0-9]\{12\}\).*#\1#p' | head -n 1)
[[ -n "$participant_id" ]] || fail "saknar deltagar-id"
token=$(printf '%s' "$people" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=approve&_csrf=${token}" \
  "$BASE/meeting/${public_id}/participants/${participant_id}"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/participants")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=grant&_csrf=${token}" \
  "$BASE/meeting/${public_id}/participants/${participant_id}"
vote_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$vote_page" | grep -q 'Väntar på nästa omröstning' || fail "godkänd deltagare ser inte mötesvyn"

echo "Skapa och öppna omröstning"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "title=Godkänns förslaget?" \
  --data-urlencode "description=" \
  --data-urlencode "show_results_to_participants=1" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
poll_id=$(printf '%s' "$page" | sed -n 's#.*/votes/\([A-Z0-9]\{12\}\).*#\1#p' | head -n 1)
[[ -n "$poll_id" ]] || fail "saknar omröstnings-id"
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=open&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${poll_id}"

echo "Fördelning ska inte synas medan omröstningen är öppen"
open_page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
if printf '%s' "$open_page" | grep -q 'data-result-row'; then
  fail "admin såg fördelning under pågående omröstning"
fi
admin_state=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/api/admin/meeting/state?meeting=${public_id}")
if printf '%s' "$admin_state" | grep -q 'percent'; then
  fail "admin-API innehöll procent"
fi
if printf '%s' "$admin_state" | grep -q 'option_key'; then
  fail "admin-API innehöll alternativ"
fi
current=$(curl -s -c "$PART" -b "$PART" "$BASE/api/vote/current")
printf '%s' "$current" | grep -q '"results":null' || fail "deltagar-API lämnade ut resultat under öppen omröstning"
printf '%s' "$current" | grep -q '"mode":"vote"' || fail "deltagaren fick inte röstläge"

echo "Rösta via formulär och försök rösta igen"
vote_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
token=$(printf '%s' "$vote_page" | csrf_from)
curl -s -c "$PART" -b "$PART" -D "$WORKDIR/cast.hdr" -o "$WORKDIR/cast.html" \
  --data-urlencode "option=yes" \
  --data-urlencode "poll_public_id=${poll_id}" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/m/${code_value}/vote"
grep -qi 'vote' "$WORKDIR/cast.hdr" || fail "rösten skickade inte tillbaka till mötesvyn"
done_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$done_page" | grep -q 'Din röst har registrerats' || fail "bekräftelse saknas"
if printf '%s' "$done_page" | grep -q 'data-result-row'; then
  fail "deltagaren såg fördelning efter sin röst"
fi
token=$(printf '%s' "$done_page" | csrf_from)
code=$(curl -s -o "$WORKDIR/again.json" -w '%{http_code}' -c "$PART" -b "$PART" \
  -H "X-CSRF-Token: ${token}" \
  -d "option=no&poll_public_id=${poll_id}" \
  "$BASE/api/vote/submit")
[[ "$code" == "409" ]] || fail "andra rösten gav $code"
code=$(curl -s -o "$WORKDIR/nocsrfvote.json" -w '%{http_code}' -c "$PART" -b "$PART" \
  -d "option=no&poll_public_id=${poll_id}" \
  "$BASE/api/vote/submit")
[[ "$code" == "419" ]] || fail "röst utan CSRF gav $code"
assert_not_leak "$WORKDIR/again.json"

echo "Antal röster syns, sedan stängs omröstningen"
sleep 1
admin_state=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/api/admin/meeting/state?meeting=${public_id}")
printf '%s' "$admin_state" | grep -q '"submitted_count":1' || fail "antalet röster uppdaterades inte"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=close&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${poll_id}"
closed=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
printf '%s' "$closed" | grep -q 'data-result-row="yes"' || fail "admin såg inte JA efter stängning"
result=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$result" | grep -q 'data-results-panel="1"' || fail "deltagaren såg inte resultatet"
printf '%s' "$result" | grep -q 'data-result-row="yes"' || fail "JA saknas i resultatet"

echo "Dolt resultat visas för admin men inte för deltagare"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "title=Utan resultat" \
  --data-urlencode "show_results_to_participants=0" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
hidden_id=$(printf '%s' "$page" | sed -n 's#.*/votes/\([A-Z0-9]\{12\}\).*#\1#p' | head -n 1)
[[ "$hidden_id" != "$poll_id" ]] || hidden_id=$(printf '%s' "$page" | sed -n 's#.*/votes/\([A-Z0-9]\{12\}\).*#\1#p' | sed -n '2p')
[[ -n "$hidden_id" && "$hidden_id" != "$poll_id" ]] || fail "hittade inte den dolda omröstningen"
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=open&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${hidden_id}"
vote_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
token=$(printf '%s' "$vote_page" | csrf_from)
curl -s -c "$PART" -b "$PART" -o /dev/null \
  --data-urlencode "option=no" \
  --data-urlencode "poll_public_id=${hidden_id}" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/m/${code_value}/vote"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=close&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${hidden_id}"
hidden_admin=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
printf '%s' "$hidden_admin" | grep -q 'data-result-row="no"' || fail "admin såg inte det dolda resultatet"
hidden_part=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
if printf '%s' "$hidden_part" | grep -q 'data-results-panel'; then
  fail "deltagaren såg ett dolt resultat"
fi
printf '%s' "$hidden_part" | grep -q 'Resultatet visas inte för deltagarna' || fail "deltagaren fick ingen förklaring"

echo "Eget val med svarsalternativ som arrangören anger"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
printf '%s' "$page" | grep -q 'Eget val, välj ett' || fail "formuläret saknar eget val"
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "title=Val av ordförande" \
  --data-urlencode "voting_type=single_choice" \
  --data-urlencode $'options=Karin Holm\nBo Berg' \
  --data-urlencode "include_abstain=1" \
  --data-urlencode "show_results_to_participants=1" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes"
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
person_id=$(printf '%s' "$page" | sed -n 's#.*/votes/\([A-Z0-9]\{12\}\).*#\1#p' | head -n 1)
[[ -n "$person_id" && "$person_id" != "$poll_id" && "$person_id" != "$hidden_id" ]] || fail "hittade inte personvalet"
printf '%s' "$page" | grep -q 'Karin Holm' || fail "alternativet syns inte i utkastet"
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=open&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${person_id}"
vote_page=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$vote_page" | grep -q 'Karin Holm' || fail "Karin saknas på röstsidan"
printf '%s' "$vote_page" | grep -q 'Bo Berg' || fail "Bo saknas på röstsidan"
printf '%s' "$vote_page" | grep -q 'btn-choice' || fail "personknappen saknar egen stil"
if printf '%s' "$vote_page" | grep -q 'data-result-row'; then
  fail "deltagaren såg fördelning i ett öppet personval"
fi
token=$(printf '%s' "$vote_page" | csrf_from)
curl -s -c "$PART" -b "$PART" -o /dev/null \
  --data-urlencode "option=opt1" \
  --data-urlencode "poll_public_id=${person_id}" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/m/${code_value}/vote"
chosen=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$chosen" | grep -q 'Ditt val: Karin Holm' || fail "bekräftelsen saknar det valda namnet"
if printf '%s' "$chosen" | grep -q 'data-result-row'; then
  fail "deltagaren såg fördelning efter personvalet"
fi
admin_state=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/api/admin/meeting/state?meeting=${public_id}")
if printf '%s' "$admin_state" | grep -q 'Karin'; then
  fail "admin-API nämnde en kandidat"
fi
if printf '%s' "$admin_state" | grep -q 'opt1'; then
  fail "admin-API nämnde alternativnyckel"
fi
page=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/votes")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  -d "action=close&_csrf=${token}" \
  "$BASE/meeting/${public_id}/votes/${person_id}"
person_result=$(curl -s -c "$PART" -b "$PART" "$BASE/m/${code_value}/vote")
printf '%s' "$person_result" | grep -q 'data-result-row="opt1"' || fail "personvalets resultat saknar opt1"
printf '%s' "$person_result" | grep -q 'Karin Holm' || fail "personvalets resultat saknar namnet"

echo "Admin rättar e-post och skickar ny länk"
maja="$WORKDIR/maja.jar"
page=$(curl -s -c "$maja" -b "$maja" "$BASE/m/${code_value}/register")
token=$(printf '%s' "$page" | csrf_from)
curl -s -c "$maja" -b "$maja" -o /dev/null \
  --data-urlencode "name=Maja Fel" \
  --data-urlencode "email=fel-${STAMP}@example.com" \
  --data-urlencode "field[kommun]=Lund" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/m/${code_value}/register"
old_mail=$(grep -l "fel-${STAMP}@example.com" "$ROOT"/storage/mail/*.txt 2>/dev/null | tail -n 1 || true)
[[ -n "$old_mail" ]] || fail "mejlet till den felskrivna adressen saknas"
old_url=$(python3 -c 'import re,sys; text=open(sys.argv[1], encoding="utf-8").read(); m=re.search(r"https?://\S+/ater/[a-f0-9]{64}", text); print(m.group(0) if m else "")' "$old_mail")
[[ -n "$old_url" ]] || fail "den första återlänken saknas"
people=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/participants")
printf '%s' "$people" | grep -q 'Spara och skicka länk' || fail "admin saknar formulär för e-post"
maja_id=$(python3 -c 'import re,sys; html=sys.stdin.read(); i=html.find("Maja Fel"); chunk=html[i:i+1200]; m=re.search(r"/participants/([A-Z0-9]{12})", chunk); print(m.group(1) if m else "")' <<<"$people")
[[ -n "$maja_id" ]] || fail "hittade inte Majas anmälan"
token=$(printf '%s' "$people" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "action=email" \
  --data-urlencode "email=ratt-${STAMP}@example.com" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/participants/${maja_id}"
old_code=$(curl -s -o "$WORKDIR/oldlink.html" -w '%{http_code}' "$old_url")
[[ "$old_code" == "404" ]] || fail "gamla länken fungerade efter adressbytet ($old_code)"
new_mail=$(grep -l "ratt-${STAMP}@example.com" "$ROOT"/storage/mail/*.txt 2>/dev/null | tail -n 1 || true)
[[ -n "$new_mail" ]] || fail "mejlet till den rättade adressen saknas"
new_url=$(python3 -c 'import re,sys; text=open(sys.argv[1], encoding="utf-8").read(); m=re.search(r"https?://\S+/ater/[a-f0-9]{64}", text); print(m.group(0) if m else "")' "$new_mail")
[[ -n "$new_url" && "$new_url" != "$old_url" ]] || fail "nya länken saknas"
fixed="$WORKDIR/fixed.jar"
curl -s -c "$fixed" -b "$fixed" -L -o "$WORKDIR/fixed.html" "$new_url"
printf '%s' "$(cat "$WORKDIR/fixed.html")" | grep -q 'Maja Fel' || fail "nya länken öppnade inte Majas anmälan"
people=$(curl -s -c "$ADMIN" -b "$ADMIN" "$BASE/meeting/${public_id}/participants")
token=$(printf '%s' "$people" | csrf_from)
curl -s -c "$ADMIN" -b "$ADMIN" -o /dev/null \
  --data-urlencode "action=resend" \
  --data-urlencode "_csrf=${token}" \
  "$BASE/meeting/${public_id}/participants/${maja_id}"
replaced=$(curl -s -o /dev/null -w '%{http_code}' "$new_url")
[[ "$replaced" == "404" ]] || fail "omskickad länk ersatte inte den förra ($replaced)"
resent_mail=$(grep -l "ratt-${STAMP}@example.com" "$ROOT"/storage/mail/*.txt 2>/dev/null | tail -n 1 || true)
resent_url=$(python3 -c 'import re,sys; text=open(sys.argv[1], encoding="utf-8").read(); m=re.search(r"https?://\S+/ater/[a-f0-9]{64}", text); print(m.group(0) if m else "")' "$resent_mail")
[[ -n "$resent_url" && "$resent_url" != "$new_url" ]] || fail "omskicket saknar en ny länk"
still=$(curl -s -o /dev/null -w '%{http_code}' -c "$fixed" -b "$fixed" -L "$BASE/m/${code_value}/vote")
[[ "$still" == "200" ]] || fail "pågående besök avslutades vid omskick ($still)"
dash=$(curl -s -c "$other" -b "$other" "$BASE/dashboard")
otoken=$(printf '%s' "$dash" | csrf_from)
other_code=$(curl -s -o "$WORKDIR/othermail.html" -w '%{http_code}' -c "$other" -b "$other" \
  --data-urlencode "action=email" \
  --data-urlencode "email=kapad-${STAMP}@example.com" \
  --data-urlencode "_csrf=${otoken}" \
  "$BASE/meeting/${public_id}/participants/${maja_id}")
[[ "$other_code" == "404" ]] || fail "annan arrangör kunde byta e-post ($other_code)"
assert_not_leak "$WORKDIR/othermail.html"

echo "HTTP-flödet lyckades"
