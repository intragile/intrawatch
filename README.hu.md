# IntraWatch

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[English](README.md) | Magyar

Ingyenes, saját szerveren futtatható uptime-monitor és státuszoldal. Egyetlen
konténer, külső szolgáltatás nélkül, az összes állapot egyetlen kötetben.

## Gyors indítás

```bash
docker run -d --name intrawatch --restart unless-stopped -p 3000:3000 \
  -v intrawatch-data:/data ghcr.io/intragile/intrawatch:latest
```

A registry-kép még nincs publikálva. Addig építsd meg helyben:

```bash
docker build -f docker/Dockerfile -t intrawatch .
docker run -d --name intrawatch --restart unless-stopped -p 3000:3000 \
  -v intrawatch-data:/data intrawatch
```

Tartsd meg az újraindítási szabályt: ha a belső programok egyike (webszerver,
queue worker-ek, ellenőrző worker-ek, ütemező, websocket-szerver) nem tartható
életben, a konténer nem nulla kilépési kóddal leáll, hogy a Docker lecserélje.

Ezután nyisd meg a `http://localhost:3000/` címet a böngészőben. Ez átirányít
az `/admin/` alatti admin felületre, amely az első indítási varázslóval kezdődik:
előbb a tulajdonosi fiók, majd az első munkaterület és annak időzónája. Hogy a
felület mit fed le, azt az [Admin](#admin) szakasz mutatja. Ha a konténer másik
gépen vagy porton érhető el, állítsd az `APP_URL` értékét arra a címre, amelyet a
böngésző használ (például `-e APP_URL=http://monitor.example.com:8080`). A
sütialapú munkamenet csak erről a címről (origin) működik.

Ugyanez a beállítás szkriptelhető az API-n keresztül. A végpont csak egyszer
használható, és sütialapú munkamenettel léptet be, ezért úgy kell hívni, ahogy a
webes felület tenné: először le kell kérni a CSRF-sütit, majd `X-XSRF-TOKEN`
fejlécben visszaküldeni egy olyan `Origin` mellett, amely megegyezik az
`APP_URL`-lel:

```bash
curl -s -c jar.txt -b jar.txt -H 'Origin: http://localhost:3000' \
  http://localhost:3000/sanctum/csrf-cookie
XSRF="$(printf '%b' "$(awk '$6 == "XSRF-TOKEN" { print $7 }' jar.txt | sed 's/%/\\x/g')")"
curl -s -c jar.txt -b jar.txt -X POST http://localhost:3000/api/v1/setup \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Origin: http://localhost:3000' -H "X-XSRF-TOKEN: $XSRF" \
  -d '{"name":"Ada Owner","email":"ada@example.com","password":"at-least-10-chars","workspace_name":"Acme Ops"}'
curl -s -b jar.txt -H 'Origin: http://localhost:3000' -H 'Accept: application/json' \
  http://localhost:3000/api/v1/me
```

A `/api/v1/me` és a `/api/v1/workspaces` minden munkaterülete tartalmaz egy
`channel` és egy `can` mezőt. A `channel` a monitorok élő frissítéséhez tartozó
privát Reverb-csatorna (`workspace.{id}`; `null`, ha a token nem rendelkezik
`monitors:read` jogosultsággal), így a kliensek a nevet innen veszik, nem maguk
állítják össze. A `can` a munkaterület-jogosultságokat (`view`, `update`,
`manageMonitors`, ...) képezi le arra, hogy a hívó birtokolja-e őket; API-token
esetén ezt a token saját jogosultságai tovább szűkítik. A jelszó-visszaállító
e-mailek a `{APP_URL}/admin/reset-password/{token}` címre mutatnak.

Munkamenet nélküli kérésre (például ha az `Origin` nem egyezik) `400` és
`urn:problem:session-unavailable` a válasz, nem pedig egy olyan bejelentkezés,
amely nem marad meg. A `GET /api/v1/setup` megmondja, szükség van-e még a
beállításra.

További felhasználókat a parancssorból lehet létrehozni (és utána
munkaterületekre meghívni, lásd [Tagok és meghívók](#tagok-és-meghívók)); az `--owner-of` a
felhasználót az adott slug-ú munkaterület tulajdonosává teszi (ha még nem
létezik, létrehozza), a jelszót pedig bekéri, ha nem adod meg a `--password`
kapcsolót:

```bash
docker exec -it intrawatch php artisan intrawatch:user:create bob@example.com \
  --name="Bob Example" --owner-of=acme-ops
```

Az API-referencia a `/api/v1/openapi.json` címen érhető el. A konténer
healthcheck-je (`/usr/local/bin/healthcheck.sh`) azt követeli meg, hogy a
`GET /up` válaszoljon, és a konténer minden ellenőrző worker-e jelentkezzen az
elmúlt 60 másodpercben (`php artisan intrawatch:worker:status --local`); minden
lépésnek saját időkorlátja van, így egy elakadt adatbázis nem lefagyasztja az
ellenőrzést, hanem a konténert nem egészségesnek jelöli. Amíg az adatbázis nem
elérhető, a konténer ezért nem egészségesnek látszik, bár a worker-ek tovább
ellenőriznek, és legfeljebb 10 000 eredményt pufferelnek, amíg az adatbázis
vissza nem jön. Az újraindítás eldobja ezt a puffert, ezért ne használd ezt a
healthcheck-et újraindítási (liveness) kiváltóként egy orkesztrátorban, vagy
állíts be neki olyan hibaküszöböt, amely hosszabb azoknál az adatbázis-
kiesésnél, amelyeket át akarsz vészelni.

### Admin

Az admin felület az `APP_URL` hosztján, az `/admin/` címen érhető el (a `/`
oda irányít át). A beállítási varázsló után az első munkaterület
monitorlistáját nyitja meg.

- **Monitorok**: szerveroldali kereséssel és szűrőkkel ellátott lista, igény
  szerint kinyitható csoportok, tömeges műveletek, valamint egy részletező oldal
  a rendelkezésre állással, válaszidővel, ellenőrzési eredményekkel,
  eseményekkel és riasztásokkal. A szerkesztő minden monitortípust lefed (HTTP
  kulcsszavas, JSON- és fejléc-ellenőrzéssel, TCP, ping, DNS, TLS,
  domain-lejárat, push, cron és csoportok).
- **Értesítési csatornák**: szolgáltatónként egy szerkesztő, csak írható
  titkokkal, üzenetsablonokkal, a nem mentett vázlat próbaküldésével és a
  csatorna tömeges monitorokhoz kötésével. A **riasztási előzmények** minden
  riasztást és a kézbesítéseit listázzák.
- **Incidensek** frissítési idővonallal, valamint **karbantartási ablakok**
  (egyszeri, intervallumos, heti, havi vagy cron) a következő ablak
  előnézetével.
- **Státuszoldalak**: beállítások, szekciók és a hozzájuk tartozó monitorok,
  publikálás és egyedi domain.
- **Munkaterület**: beállítások, tagok, meghívók és szerepkörök, API-tokenek
  és az auditnapló; további munkaterületeket a munkaterület-váltóból hozhatnak
  létre azok, akiket a `WORKSPACE_CREATORS` enged (lásd [Tagok és
  meghívók](#tagok-és-meghívók)).
- **Fiók és biztonság**: kétfaktoros hitelesítés helyreállító kódokkal. A
  felület angolul és magyarul érhető el (a böngésző nyelve szerint, vagy a
  fiókmenüből).

A frissítések élőben érkeznek: a monitorok állapotváltozásai, az ellenőrzési
eredmények és a riasztások a munkaterület privát websocket-csatornáján jönnek
(Reverb az `/app` címen, a `/broadcasting/auth` végponton a sütialapú
munkamenettel hitelesítve), így a monitorlista és a részletező oldalak
újratöltés nélkül változnak. A máshol létrehozott vagy törölt monitorok akkor
jelennek meg, amikor az ablak újra fókuszt kap, vagy a kapcsolat helyreáll. A
websocket-szerver csak azokat a böngészőket fogadja, amelyek az oldalt az
`APP_URL` hosztjáról töltötték be; ha a felület más hosztnéven vagy IP-címen is
elérhető, add hozzá a `REVERB_ALLOWED_ORIGINS` listához, különben ott nincs élő
frissítés.

A vezérlők a hívó szerepköréhez igazodnak (`can` a `/api/v1/me` válaszban); az
API ugyanezeket a szabályokat önállóan is érvényesíti. Egyedi státuszdomainen
csak a státuszoldal szolgálódik ki: az `/admin/`, az `/api/`, a `/sanctum/` és
a `/broadcasting/` ott 404-et ad.

### Tagok és meghívók

A tagokat e-mailben kell meghívni. A `POST /api/v1/workspaces/{workspace}/members`
(`email`, `role`; `members:manage`) minden címre `202`-vel válaszol, akár létezik
hozzá fiók, akár nem, és a `{APP_URL}/admin/invitations/{token}` címre mutató
hivatkozást küld; csak a már tag címet utasítja el (422). A hivatkozás 7 napig
érvényes és egyszer használható; ha ugyanazt a címet újra meghívod, a függő
meghívó lecserélődik, és a régi hivatkozás nem működik tovább. Elfogadni
bejelentkezett böngészős munkamenettel (nem API-tokennel) lehet, amelynek
e-mail-címe megegyezik a meghívóéval. Regisztráció nincs, ezért az e-mail azt
kéri a címzettől, hogy ezzel a címmel jelentkezzen be, vagy kérjen fiókot egy
adminisztrátortól (`intrawatch:user:create`, lásd [Gyors indítás](#gyors-indítás)).
A meghívó akkor is érvényét veszti, ha a meghívót küldőt eltávolítják a
munkaterületről, vagy már nem adhatja ki a szerepkört.

Az adminok és a tulajdonosok meghívhatnak, listázhatják (`GET .../invitations`,
a lejártakat megjelölve) és visszavonhatják (`DELETE .../invitations/{id}`) a
függő meghívókat; tulajdonosként meghívni, illetve tulajdonosi meghívót
lecserélni vagy visszavonni csak tulajdonos tud. A meghívók száma
munkaterületenként óránként `INVITATIONS_PER_HOUR` (alapból 30, az újraküldéseket
is beleértve), felhasználónként percenként 10. Az auditnapló a
`member.invited`, a `member.invitation_revoked` és a `member.added` eseményt
rögzíti; az `intrawatch:prune` a meghívókat a lejáratuk után egy hónappal
törli.

Az e-mail sorba kerül (a rendes queue worker dolgozza fel, lásd [Queue-k](#queue-k)),
és az alkalmazás levelezője küldi el, ezért a meghívókhoz működő `MAIL_*`
beállítások kellenek (lásd [Csatornák](#csatornák)); az alapértelmezett `log`
levelező csak a konténer naplójába írja az e-mailt. Felhasználót közvetlenül
munkaterülethez adni csak a parancssorból lehet
(`intrawatch:user:create --owner-of=<slug>`).

A `WORKSPACE_CREATORS` határozza meg, ki hozhat létre munkaterületet az API-n
és a felületen át: `owners` (alapértelmezett: aki már tulajdonosa egy
munkaterületnek), `anyone` (minden bejelentkezett felhasználó) vagy `none`
(csak a parancssor, az `--owner-of` kapcsolóval). Az ismeretlen értékek
`owners`-ként viselkednek. API-token soha nem hoz létre munkaterületet. A
`/api/v1/me` `can_create_workspace` mezője mutatja, hogy a hívó létrehozhat-e.

### Helyreállítás

```bash
# Lost authenticator and recovery codes: disable two-factor authentication.
docker exec -it intrawatch php artisan intrawatch:user:reset-2fa ada@example.com
# Forgotten password: set a new one (prompted for), end the user's sessions and revoke their API tokens.
docker exec -it intrawatch php artisan intrawatch:user:reset-password ada@example.com
```

Mindkettő bekerül az auditnaplóba `system` szereplővel. A jelszó-visszaállítás,
akár ezzel a paranccsal, akár az e-mailes hivatkozással, a felhasználó összes API-tokenjét is
visszavonja, és `auth.password_reset` eseményként rögzítődik. Vedd figyelembe, hogy a
munkaterület auditlistája csak a munkaterület eseményeit mutatja; a példányszintű
szintű események, mint az `auth.*` (bejelentkezések, sikertelen
bejelentkezések, 2FA- és jelszó-visszaállítások), munkaterület nélkül tárolódnak,
és az API-n egyelőre nem érhetők el.

### Mi található a `/data` alatt

| Útvonal | Tartalom |
| --- | --- |
| `app.key` | Az alkalmazáskulcs, az első indításkor generálódik (0600 jogosultsággal) |
| `reverb.env` | A generált websocket-hitelesítő adatok |
| `database.sqlite` | Az adatbázis, ha a `DB_CONNECTION=sqlite` (alapértelmezett) |
| `storage/` | Feltöltések (az importfájlok a `storage/app/private/imports` alatt, legfeljebb 24 óráig megőrizve) és keretrendszer-fájlok |

Ennek a kötetnek a mentése mindent lefed. Az `app.key` akkor is számít, ha az
adatbázis külső: ez titkosítja az adatbázisban tárolt kétfaktoros titkokat és
helyreállító kódokat, így az enélküli adatbázismentésből nem állíthatók vissza
a 2FA-bejelentkezések. A naplók a konténer kimenetére kerülnek (`docker logs`),
nem a kötetre.

A konténer nem root felhasználóként fut (uid 1000); host-könyvtár bind-mountolása
esetén tedd írhatóvá ennek az uid-nak (`chown -R 1000:1000 <dir>`), különben a
konténer indításkor leáll, és üzenetben megmondja az okot. Csak a 3000-es port
van kinyitva; a websocketeket a konténer belül proxyzza.

A konténer `027`-es umaskkal fut: az általa létrehozott fájlok `0640`, a
könyvtárak `0750` jogosultságot kapnak, az `app` csoport (gid 1000) olvashatja
őket, más felhasználók nem; indításkor a meglévő `database.sqlite` is elveszti a
más felhasználók hozzáférését (ha ez nem sikerül, például mert a fájl más uid
tulajdona, figyelmeztetés kerül a naplóba). Az a mentési feladat, amely más
uid-dal olvassa a kötetet, ezért legyen tagja az 1000-es csoportnak. A
`docker exec` paranccsal indított parancsok a saját umaskjukat tartják meg. Az
`/app` alatti alkalmazáskód a root tulajdona; a folyamat csak a
`bootstrap/cache` és a `storage` könyvtárat írhatja (ez utóbbi a `/data` alatt
van). A PHP nem küld `X-Powered-By` fejlécet, és minden válasz
`X-Content-Type-Options: nosniff` fejlécet hordoz.

### Konfiguráció

| Változó | Alapérték | Szerepe |
| --- | --- | --- |
| `APP_URL` | `http://localhost:3000` | Nyilvános URL, a jelszó-visszaállító hivatkozásokban használjuk. A hosztja alapból a sütialapú munkamenetet használó (stateful) domainek között van |
| `APP_KEY` | generált | Felülírja a tartósan tárolt alkalmazáskulcsot |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` vagy `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | Külső adatbázis beállításai |
| `TRUSTED_PROXIES` | nincs | A reverse proxyd vesszővel elválasztott IP-i/CIDR-jei (például `172.18.0.0/16`), hogy a kliens-IP-k helyesek legyenek a sebességkorlátokhoz és az auditnaplóhoz. A `*` értéket kerüld: minden kliens továbbító fejléceit elfogadja |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000,127.0.0.1:3000` plusz az `APP_URL` hosztja | A sütialapú munkamenetet használó webes felület hosztjai (porttal); beállítása felváltja az alapértelmezett listát |
| `SESSION_SECURE_COOKIE` | nincs beállítva | Állítsd `true`-ra, ha a példányt HTTPS-en szolgálod ki (TLS-t lezáró proxy mögött), így a munkamenet-sütik csak TLS-en mennek át |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | generált | Websocket-hitelesítő adatok; több gépes telepítésnél (az `APP_KEY`-jel együtt) minden gépen állítsd be őket |
| `REVERB_ALLOWED_ORIGINS` | üres | Az `APP_URL` hosztján kívüli hosztnevek, amelyek oldalai megnyithatják a websocketet: vesszővel elválasztott hosztok port nélkül, vagy minták, például `*.example.org`; a `*` bármely origint enged (nem ajánlott) |
| `WORKSPACE_CREATORS` | `owners` | Ki hozhat létre munkaterületet: `owners`, `anyone` vagy `none` (lásd [Tagok és meghívók](#tagok-és-meghívók)) |
| `INVITATIONS_PER_HOUR` | `30` | Egy munkaterület által óránként küldhető tagmeghívók |
| `STATUS_CUSTOM_DOMAINS` | üres | Hosztnevek, amelyeket a státuszoldalak egyedi domainként használhatnak; üresen az egyedi domainek ki vannak kapcsolva (lásd [Státuszoldalak](#státuszoldalak)) |
| `STATUS_RATE_LIMIT` | `300` | Nyilvános státuszoldal-kérések percenként, kliens-IP-nként |
| `IMPORT_MAX_UPLOAD_MB` | `256` | Az admin feltöltés által elfogadott legnagyobb Uptime Kuma-fájl; a PHP `upload_max_filesize` és `post_max_size` értéke (a képben 512 MB) korlátozza |
| `IMPORT_MAX_JSON_MB` | `20` | Az importáló által beolvasott legnagyobb Kuma JSON-mentés |
| `IMPORT_MAX_JSON_ITEMS` | `50000` | Objektumok és listák legnagyobb száma egy Kuma JSON-mentésben |

A konfigurációt a konténer induláskor gyorsítótárazza, ezért a változtatások
újraindítás után lépnek életbe.

A `docker/compose.example.yml` kész példát ad az alapértelmezett SQLite
beállításra, valamint kikommentezett PostgreSQL- és MySQL-szolgáltatásokra. A
portot csak a `127.0.0.1` címen teszi elérhetővé, az ugyanazon a gépen futó
reverse proxy számára; minden interfészen csak akkor publikáld, ha ez a
szándékod.

### Migrálás Uptime Kumából

Az IntraWatch egy Uptime Kuma példányból egy munkaterületre importálja a
monitorokat, értesítési csatornákat, címkéket, státuszoldalakat, incidenseket,
karbantartási ablakokat és az uptime-előzményeket. Az Uptime Kuma a saját
szerzőinek projektje; az IntraWatch nem áll kapcsolatban vele.

Támogatott források:

| Forrás | Kuma-verzió | Admin feltöltés | CLI |
| --- | --- | --- | --- |
| SQLite-adatbázis (`kuma.db`) | 2.x és 1.23 | igen | igen |
| „Export Backup” JSON (csak monitorok, értesítések és címkék) | 1.23 | igen | igen |
| Élő MariaDB/MySQL adatbázis, csak olvasásra, `mysql://user:pass@host:port/db` alakban | 2.x | nem | igen |

A Kuma 2.x-hez nincs JSON-mentés és nincs REST API, ezért 2.x esetén az
adatbázist kell használni. SQL-dump nem tölthető fel.

**Előbb készíts konzisztens másolatot.** A Kuma a friss írásokat a `kuma.db`
melletti `-wal` fájlban tartja. Másolás előtt állítsd le a Kumát, vagy futás
közben készíts konzisztens másolatot:

```bash
sqlite3 /path/to/kuma/data/kuma.db ".backup kuma-export.db"
```

**Az admin felületen** (munkaterület-adminok és tulajdonosok): töltsd fel a
fájlt, nézd át az elemzést (egy próbafutás, amely felsorolja, mi jönne létre, mi
maradna ki, és mi érvénytelen, mindegyiknél indoklással), majd indítsd el az
importot. A feltöltés mérete legfeljebb `IMPORT_MAX_UPLOAD_MB` lehet (alapból
256 MB), illetve a PHP `upload_max_filesize` és `post_max_size` értéke, ha az
kisebb (a kép 512 MB-ig engedi); a hibaüzenet a ténylegesen érvényes korlátot
nevezi meg. A JSON-mentésekre ezen felül az `IMPORT_MAX_JSON_MB` (20 MB) és az
`IMPORT_MAX_JSON_ITEMS` (50 000 objektum és lista, és ennek hússzorosa érték;
a monitor- és értesítéslistát is korlátozza) vonatkozik; mindkettőt a fájl
dekódolása előtt, a parancssorban is ellenőrzi a rendszer, és a hibaüzenet
megnevezi az emelendő változót. A virtuális táblákat tartalmazó
SQLite-fájlokat elutasítja. Az elemzés és a futtatás a háttérben, a `long`
queue-n zajlik, egyenként legfeljebb 900 másodpercig; az ezt túllépő futás
„did not finish in time”, az a futás pedig, amelynek worker-e összeomlott vagy
kifogyott a memóriából, „interrupted” hibával áll le. Munkaterületenként
egyszerre egy import lehet nyitva; a feltöltött fájl törlődik, amikor a futás
befejeződik vagy hibával leáll, amikor az importot törlik, illetve 24 óra után.
Az API-n ugyanez a folyamat: `POST /api/v1/workspaces/{workspace}/imports`
(multipart `file`, opcionális `history` és `paused` `1`/`0` értékkel), majd
`POST .../imports/{id}/run`, amint az állapot `analyzed`; a tokeneknek
`workspace:manage` jogosultság kell.

**A parancssorban** (méretkorlát nélkül, rendszerként fut):

```bash
docker cp kuma-export.db intrawatch:/data/kuma-export.db
docker exec -it intrawatch php artisan intrawatch:import:kuma /data/kuma-export.db --workspace=ops --dry-run
docker exec -it intrawatch php artisan intrawatch:import:kuma /data/kuma-export.db --workspace=ops --report=/data/kuma-report.json
docker exec intrawatch rm /data/kuma-export.db
```

Kapcsolók: `--dry-run` (csak elemzés), `--no-history`, `--paused` (minden
monitor szüneteltetve érkezik) és `--report=<path>` (a teljes JSON-jelentés).
Ha a Kuma külső MariaDB-t használ, forrásként add meg a
`mysql://user:pass@host:3306/kuma` címet; elég egy csak olvasási jogú
adatbázis-felhasználó. A Kuma 2.x beágyazott MariaDB-je csak a Kuma konténerén
belüli socketen hallgat, ezért készíts róla dumpot, és töltsd be egy ideiglenes
MariaDB-be, amelyet a CLI elér:

```bash
docker exec uptime-kuma mariadb-dump --skip-lock-tables -S /app/data/run/mariadb.sock -u root kuma > kuma.sql
docker network create kuma-import
docker run -d --name kuma-import-db --network kuma-import -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=kuma mariadb:11
docker exec -i kuma-import-db mariadb -uroot -psecret kuma < kuma.sql   # retry once the server accepts connections
docker network connect kuma-import intrawatch
docker exec -it intrawatch php artisan intrawatch:import:kuma 'mysql://root:secret@kuma-import-db:3306/kuma' --workspace=ops
docker network disconnect kuma-import intrawatch && docker rm -f kuma-import-db && docker network rm kuma-import
```

A DSN-ben lehetőleg csak olvasási jogú adatbázis-felhasználót adj meg: a jelszava
látszik a hoszt folyamatlistájában és a shell előzményeiben.

Az import újrafuttatása biztonságos: a már importált elemek `existing`
jelöléssel szerepelnek a jelentésben, és soha nem módosulnak vagy jönnek létre
újra, így a helyi szerkesztések és törlések érvényesülnek. Ugyanazt a példányt
egyszer JSON-mentésből, egyszer az adatbázisából importálva az elemek nem
párosíthatók, és duplikátumok keletkeznek (a jelentés figyelmeztet erre).
Ha két különböző 1.23-as példány JSON-mentését ugyanabba a munkaterületbe
importálod, a Kuma-azonosítóik ütköznek: a második példány elemei `existing`
jelöléssel szerepelnek a jelentésben, és nem jönnek létre. Ilyenkor importáld
őket külön munkaterületekbe, vagy mindegyik példányt a saját SQLite-adatbázis
fájljából.

**Nem migrálódik:** a felhasználók, API-kulcsok, Docker-hosztok és távoli
böngészők (a jelentés megszámolja őket), a percszintű előzmények és az egyes
heartbeatek, az IntraWatch által nem támogatott monitortípusok és értesítési
szolgáltatók, valamint a kézi karbantartási ablakok. Ezek megjegyzéssel
kimaradnak. Azok a beállítások, amelyek megváltoztatnák a viselkedést (nem
támogatott hitelesítési mód vagy proxyprotokoll, le nem fordítható JSON-lekérdezés,
a Kuma `conditions` mezője), a monitort szüneteltetve, a csatornát inaktívan
importálják, szintén megjegyzéssel. Semmi sem esik ki csendben: minden sor
szerepel a jelentésben.

**A push monitorok megtartják a tokenjüket**, ha a token 1–48 betűből és
számjegyből áll, és más monitor nem használja. Az IntraWatch a Kuma push-URL
formáját is kiszolgálja, `GET|POST|HEAD /api/push/<token>?status=up|down&msg=...`,
így a push kliensekben csak a hosztot kell cserélni. Minden más token helyett
új, 48 karakteres token készül `push_token_regenerated` megjegyzéssel (amely
nem mondja meg az okát), és az adott monitor klienseinek az új push-URL kell.

**Az egyedi domainek** a Kuma státuszoldalain csak akkor maradnak meg, ha a
`STATUS_CUSTOM_DOMAINS` engedi őket; különben az admin felületen vagy az API-n
indított import domain nélkül hozza létre az oldalt, és
`custom_domain_not_allowed` megjegyzést fűz hozzá. A parancssori import minden
domaint megtart.

**Előzmények:**

- Kuma 2.x: az óránkénti statisztikák (az utolsó 30 nap) óra-rollupokká, a napi
  statisztikák (legfeljebb 365 nap) nap-rollupokká válnak. A Kuma ezekben a
  statisztikákban a függő (pending) ellenőrzéseket leállásnak számolja, ezért az
  importált 2.x előzmények is így teszik. A Kuma napjai UTC-napok; ugyanarra a
  naptári dátumra kerülnek a munkaterület időzónájában, megjegyzéssel, ha ez az
  időzóna nem UTC.
- Kuma 1.23: a heartbeatek óra- és nap-rollupokká összegződnek, a pending
  pendingként marad.
- Az előzmények csak az adott futás által létrehozott monitorokhoz importálódnak,
  és csak a megőrzési beállításokon belül (`RETENTION_HOUR_DAYS`,
  `RETENTION_DAY_DAYS`). A kihagyásához add meg a `--no-history` kapcsolót vagy a `history=0` értéket.

### Monitorok

| Típus | Mit ellenőriz |
| --- | --- |
| `http` | HTTP(S) kérés: elfogadott állapotkódok, kulcsszó vagy JSON-lekérdezés, válaszfejlécek, átirányítások, basic vagy bearer hitelesítés, mTLS, opcionális HTTP- vagy SOCKS-proxy, és a végpont TLS-érvényessége |
| `tcp` | TCP-kapcsolat egy hosztra és portra |
| `ping` | ICMP echo (1–5 csomag), jogosultság nélkül, nem privilegizált ICMP-socketeken küldve |
| `dns` | DNS-rekord (A, AAAA, CNAME, MX, NS, TXT, SOA, SRV, CAA, PTR), opcionálisan adott resolverrel és elvárt értékekkel, a változások figyelésével |
| `tls` | Egy TLS-végpont tanúsítványa (opcionális SMTP-, IMAP- vagy POP3-STARTTLS-sel): lejárati küszöbök, lánc, protokollok és titkosítók |
| `domain` | A domain-regisztráció lejárata RDAP-on át, WHOIS tartalékkal (az intervallum legalább egy óra) |
| `push` | Heartbeat: a figyelt feladat hívja a monitor `push_url` címét; ha az intervallum plusz türelmi idő alatt elmarad a hívás, az hibának számít |
| `cron` | Cron-ütemezésű push monitor, indítási és befejezési jelentésekkel és opcionális maximális futásidővel |
| `group` | Összesíti a gyermekmonitorait (`all`, `any` vagy kvórum) |

Az intervallum 20 másodperctől 24 óráig terjed (alapból 60 másodperc, `tls` és
`domain` esetén egy óra). A `GET /api/v1/monitor-types` minden típust felsorol a
konfigurációs mezőivel és alapértékeivel.

A `ping`-hez nem kell `NET_RAW` képesség: jogosultság nélküli ICMP-socketeket
használ, amelyeket a Docker alapból engedélyez (`net.ipv4.ping_group_range`).
Más konténer-futtatókörnyezetekben szükség lehet arra, hogy ez a sysctl az
1000-es uid-t is tartalmazza, például `net.ipv4.ping_group_range=0 2147483647`.

Az ellenőrzések soha nem csatlakoznak a felhőplatformok metaadat-végpontjaihoz:
`169.254.169.254/32`, `169.254.170.2/32`, `169.254.170.23/32`,
`fd00:ec2::254/128`, `fd20:ce::254/128` és `100.100.100.200/32`; a
`169.254.0.0/16` többi része elérhető marad a link-local eszközök számára. A
`CHECK_BLOCKED_NETWORKS` (CIDR-lista, alapból üres) kiegészíti ezt a beépített
listát, sosem váltja fel; add hozzá a belső tartományaidat, ha a munkaterület
tagjai nem tapogathatják le őket. A `CHECK_ALLOW_METADATA=1` engedi, hogy a
monitorok elérjék a metaadat-végpontokat (ha szándékosan figyelnél egyet); az
értesítési célpontok sosem érik el őket.

Ha egy HTTP monitor proxyt használ, a proxyt és a valódi célt is, minden
átirányításnál, összeveti a rendszer ezekkel a listákkal. A literális IP-címet
pontosan, a numerikus hosztot (például `2852039166` vagy `0xa9fea9fe`) annak az
IPv4-címnek megfelelően, ahogy a proxy olvasná, a hosztnevet pedig egy
legfeljebb egy másodperces helyi névfeloldással ellenőrzi. Az a név, amely
helyben nem oldható fel, engedélyezett, mert a proxy olyan neveket is
feloldhat, amelyeket a worker nem; a proxy egy nevet másként is feloldhat
(split-horizon DNS, rebinding), ezért hosztneveknél a valódi határt továbbra
is a proxy saját kimenő szabályai jelentik.

Ha egy tárolt titkokkal rendelkező monitor módosítása megváltoztatja, hová
csatlakozik (`http`: `url` vagy `proxy.url`, a proxy eltávolítását is
beleértve; `tcp`: `host` vagy `port`; `ping`: `host`; `dns`: `name` vagy
`resolver`; `tls`: `host`, `port` vagy `servername`; `domain`: `domain`),
minden tárolt titkot újra el kell küldeni, vagy `null`-lal törölni. Különben a
módosítás 422-vel elutasításra kerül a `secrets` mezőn (a hiányzó kulcsok
felsorolásával) és minden `secrets.<key>` mezőn. A többi változtatás megtartja
a titkokat; az admin felület a cél változásakor kötelezőnek jelöli a
titokmezőket.

A push és cron jelentések tokenenként korlátozottak (percenként 120) az
alkalmazás gyorsítótárában (`CACHE_STORE`, alapból az adatbázis). Ha több
konténer van egy cím mögött, a gyorsítótárat olyan tárolón tartsd, amelyen
osztoznak, például a közös adatbázison vagy Redisen, hogy a korlát mindegyikükre
együtt vonatkozzon.

A `push_url` tartalmazza a push tokent, és ennyi elég ahhoz, hogy egy monitort
működőnek, leállónak vagy futónak jelentsenek. Az API csak Szerkesztőknek és
afölötti szerepköröknek, valamint a `monitors:write` jogosultságú API-
tokeneknek adja vissza; a Megtekintők és az csak olvasási tokenek `null` értéket
és `has_push_token: true` jelzést kapnak. A hitelesítő fejlécek
(`Authorization`, `Proxy-Authorization`, `Cookie`, `X-Api-Key`, `X-Auth-Token`)
nem engedélyezettek egy HTTP monitor egyszerű `headers` mezőjében, amelyet az API
visszaad; ezeket a `secret_headers` mezőbe kell tenni.

A push monitorok az `/api/push/{token}` címen is válaszolnak, ez az Uptime Kuma
URL-formája, ugyanazokkal a `status=up|down`, `msg` és `ping` lekérdezési
paraméterekkel (GET, POST vagy HEAD) és ugyanazzal a sebességkorláttal, mint az
`/api/v1/push/{token}`. Az Uptime Kumából importált push monitorok megtartják a
tokenjüket, így a klienseikben csak a hosztot kell cserélni; az a token,
amelyet már egy másik monitor birtokol, amely 48 karakternél hosszabb, vagy
amely a betűkön és számjegyeken kívül más karaktert is tartalmaz, kicserélődik,
és az importjelentés ezt közli (lásd [Migrálás Uptime
Kumából](#migrálás-uptime-kumából)).

Amíg az adatbázis hibázik, a push és cron monitorok jelentéseit elutasíthatja a
rendszer, ezért a worker nem ítéli meg önállóan a határidejüket. A határidő-
olvasások vagy eredményírások legalább 30 másodpercig tartó hibája kiesésnek
számít: az alatta esedékessé vált határidőkről nem készül jelentés, bármeddig
tart is, és akár az olvasások, akár az írások, akár mindkettő hibáztak, és az
adatbázis visszatérésének pillanatától számolódnak újra (push monitornál egy
teljes intervallum plusz türelmi idő, cron feladatnál a következő ütemezett
futás). A kiesés előtt már rögzített mulasztás megmarad; a következő újrapróbálás
ekkor a helyreállás után egy újrapróbálási intervallummal esedékes. A rövidebb
hibák, például egy deadlock vagy egy elfoglalt adatbázis, nem mozgatnak semmit.
Ugyanez érvényes azokra a határidőkre, amelyek akkor jártak le, amikor maga a
worker nem futott. Az ellenőrzött cél által visszaküldött szöveg (válasz törzse,
DNS-rekord, push üzenet) a NUL bájtok és más vezérlőkarakterek nélkül, az
érvénytelen UTF-8 pedig `?` karakterrel helyettesítve tárolódik.

### Ellenőrző worker-ek és skálázás

Minden konténer `CHECK_WORKERS` ellenőrző worker-folyamatot futtat. A monitorok
azonosító szerint `CHECK_SHARDS_TOTAL` shardra oszlanak, és minden worker egy
shardot visz: egy konténer worker-ei a `CHECK_SHARD_OFFSET` és a
`CHECK_SHARD_OFFSET + CHECK_WORKERS - 1` közötti shardokat futtatják.

| Változó | Alapérték | Szerepe |
| --- | --- | --- |
| `CHECK_WORKERS` | `1` | Ellenőrző worker-folyamatok ebben a konténerben (1–32) |
| `CHECK_SHARD_OFFSET` | `0` | Az első shard, amelyet ez a konténer futtat |
| `CHECK_SHARDS_TOTAL` | `CHECK_WORKERS` | A shardok száma az összes konténeren át |
| `CHECK_CONCURRENCY` | `200` | Egy worker által egyszerre futtatott ellenőrzések |
| `CHECK_BLOCKED_NETWORKS` | üres | Hálózatok, amelyekhez az ellenőrzések nem csatlakozhatnak, a beépített metaadat-listán felül (lásd [Monitorok](#monitorok)) |
| `CHECK_ALLOW_METADATA` | `0` | Az `1` engedi, hogy a monitorok elérjék a beépített felhős metaadat-végpontokat |

Egy worker körülbelül 1 000 HTTP monitort kezel 60 másodperces intervallummal,
két CPU-magon, az ütemezéshez képest két másodpercnél kisebb késéssel
(`php artisan intrawatch:bench`, lásd Fejlesztés). Több monitorhoz adj hozzá
worker-eket.

Ha az ellenőrzéseket több gépre akarod szétosztani, minden konténernek ugyanazt
a külső MySQL- vagy PostgreSQL-adatbázist (az SQLite nem osztható meg gépek
között) és egy közös gyorsítótár-tárolót kell használnia, mindegyiknek egyet
kell értenie a `CHECK_SHARDS_TOTAL` értékében, és együtt pontosan egyszer kell
lefedniük minden shardot. Például két gép két-két worker-rel:

```bash
# host A runs shards 0 and 1
-e CHECK_WORKERS=2 -e CHECK_SHARD_OFFSET=0 -e CHECK_SHARDS_TOTAL=4
# host B runs shards 2 and 3
-e CHECK_WORKERS=2 -e CHECK_SHARD_OFFSET=2 -e CHECK_SHARDS_TOTAL=4
```

Minden gépnek ugyanazt az `APP_KEY`, `REVERB_APP_ID`, `REVERB_APP_KEY` és
`REVERB_APP_SECRET` értéket is meg kell kapnia a környezetén át: az egyik
konténer `/data` kötetében generált értékeket a többiek nem ismerik, így a
munkamenetek, a titkosított titkok és az élő frissítések gépek között nem
működnek. Külső adatbázis és generált értékek esetén a konténer a
`WARNING: multi-host deployments must share APP_KEY and REVERB_* (generated here: ...)`
üzenetet naplózza, és elindul.

Induláskor egy közös adatbázist egyszerre csak egy gép migrál: a migráció
zárat tart a gyorsítótár-tárolóban, amelynek ezért közösnek kell lennie (az
adatbázis vagy Redis). A többi gép vár, legfeljebb nagyjából tíz percig; utána
`FATAL` üzenettel kilép, és az újraindítási szabály újra próbálkozik. A
migráció közben leállított gép legfeljebb egy órára hátrahagyja a zárat; ha
egyik gép sem migrál, töröld a `php artisan cache:clear` paranccsal. Az
SQLite, amelyet csak egy gép használhat, zár nélkül migrál.

A konténer nem indul el, ha a `CHECK_SHARD_OFFSET + CHECK_WORKERS` meghaladja a
`CHECK_SHARDS_TOTAL` értékét. A kapcsolók nélküli `php artisan
intrawatch:worker:status` azt ellenőrzi, hogy a telepítés minden shardja
jelentkezett-e, ami az egész klaszter egy helyről való felügyeletére hasznos.

### Queue-k

A konténerben három queue worker fut: egy a rendes feladatoknak (például az élő
frissítések szórása és a riasztások kiértékelése), egy a `notifications`
queue-nak, amely a riasztásokat küldi az értesítési csatornákra, és egy a `long`
queue-kapcsolatnak, amely az időzónaváltás utáni nap-rollup újraépítéseket és
az Uptime Kuma importokat futtatja (egyenként legfeljebb 900 másodpercig,
960 másodperces `retry_after` mellett). A külön worker-ek miatt egy lassú
értesítési szolgáltató vagy egy hosszú újraépítés nem késlelteti a rendes
feladatokat. A kép nélkül mindhármat futtatni kell:

```bash
php artisan queue:work
php artisan queue:work --queue=notifications --tries=5 --timeout=30
php artisan queue:work long --queue=long --timeout=900
```

### A konténer leállítása

`docker stop` esetén minden program párhuzamosan áll le. Az ellenőrző worker-ek
legfeljebb 4 másodpercet várnak a folyamatban lévő ellenőrzésekre, és még
legfeljebb 2-t a pufferelt eredmények kiírására, és minden programot legkésőbb
8 másodperc után leállít a rendszer, így a konténer a Docker alapértelmezett
10 másodperces türelmi idején belül leáll. Legalább ennyi türelmi időt tarts meg
(`docker stop -t`, vagy `stop_grace_period` a Compose-ban; a példafájl
`stop_grace_period: 10s` értéket állít). Egy már folyamatban lévő adatbázis-
írás vagy egy nem válaszoló adatbázis ezeknél a korlátoknál tovább is tarthat;
azok az eredmények, amelyek a worker leállításakor még pufferben voltak,
elvesznek. Az a nap-rollup újraépítés, amely a konténer leállásakor még fut,
félkészen marad (minden napot atomikusan cserél le, így egy nap sem számolódik
kétszer), és nem folytatódik; a hátralévő napok újraépítéséhez változtasd meg
újra az időzónát.

### SQLite hálózati tárolón

Az SQLite WAL módban fut, amely megosztott memóriát igényel, és hálózati
fájlrendszereken, például NFS-en vagy SMB-n nem biztonságos. Ha a `/data` ilyenen
van, állítsd a `DB_SQLITE_JOURNAL_MODE=DELETE` értéket, vagy használj MySQL-t
vagy PostgreSQL-t.

### Előzmények, rollupok és megőrzés

Minden ellenőrzési eredmény nyersen tárolódik, és percenként egyszer
(`intrawatch:rollup`) perc-, óra- és nap-rollupokká összesítődik. A törlés
óránként, a 30. percben fut (`intrawatch:prune`), darabokban töröl, köztük
rövid szünettel, így sosem zárolja hosszan az eredménytáblákat.

| Változó | Alapérték | Megőrzött adat |
| --- | --- | --- |
| `RETENTION_RAW_HOURS` | `24` | Nyers ellenőrzési eredmények |
| `RETENTION_MINUTE_HOURS` | `48` | Perc-rollupok |
| `RETENTION_HOUR_DAYS` | `90` | Óra-rollupok |
| `RETENTION_DAY_DAYS` | `730` | Nap-rollupok és monitoresemények |
| `RETENTION_ALERT_DAYS` | `90` | Riasztási előzmények és kézbesítések |
| `RETENTION_CHUNK_SIZE` | `5000` | Törlésenként törölt sorok száma a takarítás során |
| `RETENTION_CHUNK_SLEEP_MS` | `50` | Szünet a törlő utasítások között |

A gyorsítótárazott domain-regisztrációs lekérdezések 30 nap ellenőrzés nélkül
elavulnak. A `RETENTION_RAW_HOURS` legalább 1, a `RETENTION_MINUTE_HOURS`
legalább 25 (egy teljes helyi nap, hogy a nap-rollupok pontosak maradjanak); a
kisebb értékeket a rendszer erre a minimumra emeli.

Az adatbázisba késve érkező eredményeket, például azokat, amelyeket egy worker
adatbázis-kiesés alatt pufferelt, is beleszámoljuk: minden rollup-futás újraszámolja
az előző vizsgálat óta beszúrt eredmények perceit, óráit és napjait, a beszúrás
után nagyjából két perccel (hogy a MySQL-en vagy PostgreSQL-en sorrenden kívül
véglegesülő sorok ne maradjanak ki), futásonként legfeljebb 50 000 eredményt; a
nagyobb hátralékot a következő futások dolgozzák le. Azok az eredmények, amelyek
a nyers megőrzési időnél régebbiek, amikor megérkeznek, nem számítanak bele. Ha
a gyorsítótárban a rollup-állapot elveszik, a rollupok a legfrissebb perc-rollup
előtt öt perccel folytatódnak, a késő eredmények követése pedig nagyjából két
perccel később indul újra; ami már követés alatt áll, abból semmi sem marad ki.

A nap-rollupok a munkaterület időzónájának éjfélén kezdődnek. Az időzóna
megváltoztatása a háttérben újraépíti a nap-rollupokat a még megőrzött
adatokból, így az adatoknál régebbi napok a régi időzóna határait őrzik:

- a perc-megőrzésen belüli napok (alapból 48 óra) pontosan épülnek újra;
- a régebbi napok óra-rollupokból összegződnek. Ez egész órás eltolású
  időzónáknál pontos; a +05:30 vagy +05:45 típusú eltolásoknál ezek a napok az
  eltolás óránál kisebb részével elcsúsznak;
- az óra-megőrzésnél (alapból 90 nap) régebbi napok annak az időzónának a
  határait őrzik, amelyben épültek; az az egy régi nap, amely átfedné az első
  újraépített napot, kiesik.

Az újraépítés egyszerre egy napot cserél, így a nap-rollupok sosem fedik át
egymást, akkor sem, ha az újraépítés megszakad. Amíg fut, csak az adott
munkaterület nap-rollupjai szünetelnek; utána felzárkóznak. Az újraépítések a
`long` queue-kapcsolaton futnak (`retry_after` 960 s, a feladat 900 s-os
időkorlátja fölött); a kép futtat hozzá egy worker-t (lásd Queue-k).

A rendelkezésre állás `(up + degraded + pending) / (total - maintenance)`; üres,
ha az időszak minden ellenőrzése karbantartási ablakba esett.

### Értesítések

A monitorok értesítési csatornákon keresztül riasztanak. A csatorna egy
munkaterülethez tartozik; a monitort a `notification_channel_ids` mezővel
(legfeljebb 50) lehet csatornákhoz kötni. Az ilyen mező nélkül létrehozott új
monitor az összes aktív, `is_default` jelölésű csatornához kötődik; egy csatorna
létrehozásakor vagy módosításakor az `apply_to_existing: true` a munkaterület
minden meglévő monitorához hozzákapcsolja.

| Végpont | Jogosultság |
| --- | --- |
| `GET /api/v1/notification-channel-types` | bármely bejelentkezett felhasználó |
| `GET /api/v1/workspaces/{workspace}/notification-channels`, `GET /api/v1/workspaces/{workspace}/notification-channels/{channel}` | `notifications:read` |
| `POST /api/v1/workspaces/{workspace}/notification-channels`, `PATCH`/`DELETE /api/v1/workspaces/{workspace}/notification-channels/{channel}` | `notifications:write` |
| `POST /api/v1/workspaces/{workspace}/notification-channels/test` (mentetlen), `POST /api/v1/workspaces/{workspace}/notification-channels/{channel}/test` | `notifications:write` (felhasználónként percenként 10 és munkaterületenként percenként 30; ezen felül 429 `Retry-After` fejléccel) |
| `GET /api/v1/workspaces/{workspace}/alerts` | `notifications:read` |
| `GET /api/v1/workspaces/{workspace}/monitors/{monitor}/alerts` | `monitors:read` és `notifications:read` |

Az API-tokeneknek `notifications:read` (Megtekintők és afölött) kell a
csatornák és a riasztási előzmények olvasásához, és `notifications:write`
(Szerkesztők és afölött) a csatornák kezeléséhez és teszteléséhez. A csatornák
monitorokhoz kötése a monitor-API része (`monitors:write`).

Az incidensek és a karbantartási ablakok az `incidents:read` (Megtekintők és
afölött) és az `incidents:write` (Szerkesztők és afölött) jogosultságot
használják; a státuszoldalak a `status-pages:read` (Megtekintők és afölött) és a
`status-pages:write` (Szerkesztők és afölött) jogosultságot.

#### Csatornák

A *-gal jelölt kulcsok kötelezők. A titkok csak írhatók: az API csak a nevüket
adja vissza a `secret_keys` mezőben, és az auditnapló is csak ezeket a neveket
rögzíti. Módosításkor az elhagyott titkok megmaradnak, a `null`-ra állított titok
pedig törlődik. A `GET /api/v1/notification-channel-types` minden típust
felsorol a mezőivel és alapértékeivel.

Ha egy tárolt titkokkal rendelkező csatorna módosítása megváltoztatja, hová
küld (`webhook`: `url`; a `telegram`, `ntfy`, `gotify` és `apprise`
`server_url` mezője; `matrix`: `homeserver_url`; `email`: `smtp.host`,
`smtp.port` vagy `smtp.encryption`; `opsgenie`: `region`), minden tárolt titkot
újra el kell küldeni, vagy `null`-lal törölni, különben a módosítás 422-vel
elutasításra kerül a `secrets` mezőn (a hiányzó kulcsok felsorolásával) és
minden `secrets.<key>` mezőn. A többi változtatás megtartja a titkokat; az
admin felület a cél változásakor kötelezőnek jelöli a titokmezőket.

Azok, akik nem kezelhetik a csatornákat (a Megtekintők és a
`notifications:write` nélküli tokenek), a webhook `url` mezőjét
`scheme://host[:port]/***` alakban, az ntfy `topic` és az Apprise `config_key`
mezőjét `***`-ként látják.

| Típus | `config` | `secrets` |
| --- | --- | --- |
| `email` | `to`* (címek listája), `smtp` (`host`, `port`, `encryption`: `starttls`, `tls` or `none`, `username`, `from_address`, `from_name`) | `smtp_password` |
| `webhook` | `url`*, `method` (`POST` or `PUT`), `format` (`json` or `form`), `headers` | `secret_headers`, `signing_secret` (HMAC-SHA256 in `X-IntraWatch-Signature: t=…,v1=…`) |
| `slack` | | `webhook_url`* |
| `discord` | `username`, `thread_id` | `webhook_url`* |
| `teams` | | `webhook_url`* |
| `mattermost` | `channel`, `username` | `webhook_url`* |
| `google_chat` | | `webhook_url`* |
| `telegram` | `chat_id`*, `message_thread_id`, `server_url` (`https://api.telegram.org`), `silent` | `bot_token`* |
| `ntfy` | `server_url`*, `topic`*, `priority` | `access_token` |
| `gotify` | `server_url`*, `priority` | `app_token`* |
| `pushover` | `priority`, `sound`, `device` | `user_key`*, `app_token`* |
| `matrix` | `homeserver_url`*, `room_id`* | `access_token`* |
| `pagerduty` | | `routing_key`* (Events API v2) |
| `opsgenie` | `region`* (`us` or `eu`), `priority` (`P1` to `P5`) | `api_key`* |
| `apprise` | `server_url`*, `config_key` | `urls` (a `config_key` és az `urls` közül az egyik kötelező) |

A webhook riasztásonként egy JSON- (vagy űrlap-) kérést kap az `event` (a
riasztás fajtája), `severity`, `title`, `body`, `monitor` (`id`, `name`, `type`,
`target`, `url`), `status`, `previous_status`, `message`, `at`,
`duration_seconds`, `changes` és `workspace` mezőkkel, valamint az
`X-IntraWatch-Event` és `X-IntraWatch-Delivery` fejlécekkel (az utóbbi
kézbesítésenként állandó azonosító, a duplikáció-szűréshez).

A saját `smtp` blokk nélküli (`null` vagy `{}`) e-mail csatornák az
alkalmazás levelezőjén keresztül, a példány feladócímével küldenek. Ilyen
csatornát csak munkaterület-tulajdonosok és -adminok (token esetén a token
tulajdonosának szerepköre alapján) hozhatnak létre, próbaküldhetnek mentetlenül,
állíthatnak át az alkalmazás levelezőjére, vagy módosíthatják a címzettjeit és
sablonjait; a Szerkesztők a mentett csatornát továbbra is átnevezhetik,
be- és kikapcsolhatják és próbaküldhetik. A levelezőt a szokásos
Laravel-változókkal kell beállítani (az alapértelmezett `log` csak a konténer
naplójába írja az e-maileket):

```bash
-e MAIL_MAILER=smtp -e MAIL_HOST=smtp.example.com -e MAIL_PORT=587 \
-e MAIL_USERNAME=alerts@example.com -e MAIL_PASSWORD=… -e MAIL_SCHEME=smtp \
-e MAIL_FROM_ADDRESS=alerts@example.com -e MAIL_FROM_NAME=IntraWatch
```

A `MAIL_TIMEOUT` (alapból `10`) az alkalmazás levelezőjének SMTP-időkorlátja
másodpercben, socket-műveletenként. Tartsd jóval az értesítésküldő feladat
30 másodperces időkorlátja alatt, különben egy lassú SMTP-szerver miatt a
feladat időtúllépéssel áll le ahelyett, hogy tisztán hibázna és újra próbálkozna.

Az `apprise` csatornához egy Apprise API-szerver kell, amely továbbosztja az
üzenetet az Apprise által támogatott szolgáltatásoknak, például `docker run -d -p 8000:8000
caronc/apprise` és `server_url: http://apprise:8000`.

#### Riasztások

A riasztások fajtái: `down`, `up`, `degraded`, `still_down` (emlékeztető),
`flapping`, `stable`, `expiring` és `renewed` (tanúsítvány- és domain-lejárati
sávok), `changed` (tanúsítvány, DNS vagy más figyelt értékek) és `test`.

Monitoronként az `alerting` objektum a következőket állítja:

| Beállítás | Alapérték | Jelentése |
| --- | --- | --- |
| `resend_interval_minutes` | `0` | Emlékeztető (`still_down`) leállás alatt, 0–10 080; a 0 kikapcsolja az emlékeztetőket |
| `notify_degraded` | `true` | Riasztás, ha a monitor csökkent teljesítményű (degraded) |
| `degraded_alert_after_seconds` | `300` | Meddig kell fennállnia a degraded állapotnak a riasztás előtt, 0–86 400 |
| `notify_changes` | `true` | Riasztás tanúsítvány-, DNS- és lejárati sáv-változásokra |

Az a monitor, amely túl gyakran vált up és down között, flappingol: egyetlen
`flapping` riasztást küld, további állapotriasztást vagy emlékeztetőt nem, majd
egy `stable` riasztást, amikor megnyugszik. Az a monitor, amelynek szülőcsoportja
leállt (és ez a csoport aktív csatornán riaszt), elnyomott: a riasztásai
`suppressed_by_monitor_id` értékkel rögzítődnek, de nem mennek ki.

| Változó | Alapérték | Szerepe |
| --- | --- | --- |
| `ALERT_FLAP_THRESHOLD` | `4` | Az ablakon belüli állapotosztály-váltások száma, amely elindítja a flappinget (legalább 3) |
| `ALERT_FLAP_WINDOW_SECONDS` | `600` | A flapping-érzékelés ablaka |
| `ALERT_FLAP_STABLE_SECONDS` | `600` | Az osztályváltás nélküli idő, amely véget vet a flappingnek |
| `ALERT_DIGEST_THRESHOLD` | `5` | Ennél több riasztást egy csatornára egy kiértékelésben egyetlen összefoglalóként (digest) küld a rendszer (üzenetenként legfeljebb 200 riasztás) |
| `RETENTION_ALERT_DAYS` | `90` | Riasztási előzmények és kézbesítési rekordok |

A PagerDuty, Opsgenie és webhook csatornák soha nem kapnak összefoglalót
(digest): mindig riasztásonként egy eseményt kapnak, mert monitoronként
deduplikálnak. Egy küldés 5 másodperc csatlakozás és összesen 10 másodperc után
időtúllép, a hibákat legfeljebb 5-ször próbálja újra a rendszer (10, 30, 120 és
300 másodperc után; a 429-es válasz legfeljebb 300 másodperces `Retry-After`
értékét tiszteletben tartja). A 408-tól és 429-től eltérő 4xx válasz azonnal
hibának számít. A szolgáltató hibaszövege minden csatornatitok eltávolításával
tárolódik.

A riasztások tranzakciósak: a kiértékelő feladat ugyanabban az adatbázis-
tranzakcióban kerül sorba, amely az ellenőrzési eredményeket rögzíti, a
riasztás, a kézbesítései és azok küldő feladatai pedig szintén egy
tranzakcióban íródnak. Így egy véglegesített eredményt mindig kiértékel a
rendszer, a visszagörgetett sosem riaszt, és az újrapróbált kiértékelés nem küld
kétszer riasztást. Ez az alkalmazás adatbázisán lévő alapértelmezett
adatbázis-queue-nál érvényes; bármely más queue-nál a feladatok a véglegesítés
után kerülnek be, így a közbeni összeomlás elveszítheti őket (egy figyelmeztetés
kerül a naplóba folyamatonként egyszer).

#### Sablonok

A csatorna opcionális `templates` mezője (`title` legfeljebb 200, `body`
legfeljebb 4 000 karakter) felváltja az alapértelmezett szöveget. Logikamentes
`{{ variable }}` behelyettesítést használnak; az ismeretlen változókat a rendszer
elutasítja. Változók: `kind`, `kind_label`, `severity`, `status`,
`previous_status`, `message`, `at`, `at_local`, `duration`, `duration_seconds`,
`changes`, `monitor.id`, `monitor.name`, `monitor.type`, `monitor.target`,
`monitor.url` és `workspace.name`. Az értékek az adott csatorna formátumához
escape-elődnek, így az ellenőrzött cél szövege nem tud felhasználókat
megemlíteni (`@everyone`, `<!channel>`), és jelölőnyelvet sem tud beinjektálni.

#### Riasztási előzmények

A `GET /api/v1/workspaces/{workspace}/alerts` a riasztásokat időrendben a
legújabbal kezdve listázza, a `monitor_id`, `kind` és `since` szűrőkkel (a
`since` zárt határ; ISO 8601 dátum vagy másodpercre pontos, eltolással megadott
időpont, például `2026-03-10T10:00:00Z`; a törtmásodperceket a rendszer
422-vel elutasítja), és minden riasztás `deliveries` mezőjével (`channel`,
`status`: `queued`, `sent` vagy `failed`, `attempts`, `last_error`, `sent_at`).
A `monitor` `null`, ha a monitort törölték, a `channel` pedig `null`, ha a
csatornát törölték. A
`GET /api/v1/workspaces/{workspace}/monitors/{monitor}/alerts` ugyanez a lista
egy monitorra.

#### Értesítési célpontok és SSRF

A `CHECK_BLOCKED_NETWORKS` az értesítési célpontokra is vonatkozik, nem csak az
ellenőrzésekre: az a csatorna-URL, amely letiltott hálózatba oldódik fel,
küldéskor elutasításra kerül (a címet egyszer oldja fel a rendszer, a kapcsolatot
ahhoz rögzíti, az átirányításokat pedig sosem követi). E listán felül az
értesítési célpontok mindig elutasításra kerülnek a felhős metaadat-végpontokon
(a `CHECK_ALLOW_METADATA=1` mellett is), a teljes `169.254.0.0/16` és
`fe80::/10` tartományban, a `64:ff9b::/96` és `2002::/16` fordítási
előtagokban, valamint a `0.0.0.0/8` és a `::/128` tartományban, amelyek a helyi
gépre csatlakoznak. A loopback és a privát hálózatok alapból engedélyezettek,
így a munkaterület tagjai belső szolgáltatásokra irányíthatják a webhookokat.
Ennek megtiltásához add hozzá a belső tartományokat, például
`CHECK_BLOCKED_NETWORKS=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7,0.0.0.0/8,::/128`
(az utolsó két bejegyzés a helyi gép tiltását az ellenőrzésekre is kiterjeszti).

### Karbantartási ablakok

A karbantartás egy időszakra elnémítja az érintett monitorokat: az ellenőrzéseik
tovább futnak, de az állapotuk `maintenance`, riasztás nem megy ki, és az
időszak nem számít állásidőnek. A karbantartás egy munkaterülethez tartozik, és
annak 1–1 000 monitorát célozza; egy csoport célzása a gyermekeit is lefedi.
Ezenkívül a munkaterület legfeljebb 100 státuszoldalán is megjeleníthető
(`status_page_ids`).

| Végpont | Jogosultság |
| --- | --- |
| `GET /api/v1/workspaces/{workspace}/maintenances` (`?state=active\|upcoming\|past\|all`), `GET /api/v1/workspaces/{workspace}/maintenances/{maintenance}` | `incidents:read` |
| `POST /api/v1/workspaces/{workspace}/maintenances`, `PATCH`/`DELETE /api/v1/workspaces/{workspace}/maintenances/{maintenance}`, `POST /api/v1/workspaces/{workspace}/maintenances/{maintenance}/end` | `incidents:write` |

| `strategy` | Mezők | Ablak |
| --- | --- | --- |
| `once` | `starts_at`, opcionális `ends_at` | Egyetlen ablak; `ends_at` nélkül nyitva marad, amíg a `POST .../end` a jelenre nem állítja |
| `interval` | `every_days` (1–3650), `start_time`, `duration_minutes`, opcionális `valid_from`/`valid_until` | Minden N. napon, a `valid_from` (vagy a létrehozás napja) naptól számolva |
| `weekly` | `weekdays` (1 hétfő – 7 vasárnap), `start_time`, `duration_minutes` | A megadott hétköznapokon |
| `monthly` | `month_days` (1–31, vagy -1 az utolsó napra), `start_time`, `duration_minutes` | Minden hónap megadott napjain |
| `cron` | `cron`, `duration_minutes` | A cron-kifejezés minden futásakor |

A `start_time` helyi `HH:MM`, a `duration_minutes` 1 és 10 080 közötti érték, a
`valid_from` / `valid_until` pedig helyi dátum (a `valid_until` zárt határ). A
többi stratégia mezői null-ként tárolódnak. Az a definíció, amely sosem
eredményezhetne ablakot (ismétlődő kezdési idő vagy időtartam nélkül, érvénytelen
cron-kifejezés, üres `weekdays` vagy `month_days`, a `starts_at` után nem
következő `ends_at`), 422-vel elutasításra kerül. Az `active: false` törlés
nélkül kapcsol ki egy karbantartást; csak a `once` karbantartások zárhatók le a
`POST .../end` hívással.

Az időpontokat a karbantartás `timezone` mezője szerint értelmezi a rendszer,
amely `null` esetén a munkaterület időzónájára esik vissza. Nyári időszámításnál
az a helyi kezdési idő, amely nem létezik (az előreugrás hézaga), a hézag
mértékével előre tolódik, az a helyi idő, amely kétszer fordul elő, az elsőt
használja, az ablak pedig mindig az időtartamának megfelelő eltelt percig tart.
Az ugyanazon a monitoron átfedő ablakok az egyesítésükre némítják el.

Az API-ban minden karbantartás tartalmaz `in_progress` és `next_window`
(`starts_at`, `ends_at`) mezőt: a folyamatban lévő ablakot, vagy ha nincs, a
következőt, 400 napra előre nézve; `null`, ha már nem marad egy sem. A `state`
szűrő ugyanezt a számítást használja, így a `state` szerint szűkített lista
előbb kiválasztja az egyező azonosítókat, és csak utána lapozza őket.

Amikor egy ablak elindul vagy véget ér, vagy egy karbantartást létrehoznak,
szerkesztenek, lezárnak vagy törölnek, miközben folyamatban van, az ütemezett
monitorokat (HTTP, TCP, ping, DNS, TLS, domain) azonnal ellenőrzi a rendszer, a
csoportokat pedig újraszámolja, másodperceken belül (az
`intrawatch:maintenance:sync` 15 másodpercenként fut). A push és cron monitorok
az ablak indulásakor lépnek karbantartásba, akkor is, ha már jelentettek
kiesést, és az ablak vége után a következő pingjüknél vagy határidő-
ellenőrzésüknél térnek vissza a valós állapotukhoz. A riasztások az egész ablak
alatt némítva vannak. Az a kiesés, amely az ablak végén még tart, utólag
riaszt: a monitor a vége utáni első ellenőrzésénél karbantartásból leállt
állapotba megy, `Down` riasztás megy ki, ha arra a kiesésre még nem ment, és az
emlékeztetők (`STILL DOWN`) folytatódnak.
Az az ellenőrzési eredmény, amely éppen úton van, amikor egy karbantartást
írnak, a worker 5 másodperces lekérdezési intervallumáig még az előző naptárhoz
rögzítődhet; a következő ellenőrzés kijavítja, és a leállt, karbantartás, leállt
sorozat egy kiesésnek számít, így nem megy ki további riasztás.

### Státuszoldalak

A státuszoldal a monitorok egy válogatott körét mutatja a nyilvánosságnak:
összesített szalagot, folyamatban lévő karbantartást, nyitott incidenseket a
frissítéseikkel, komponensszekciókat (állapot, rendelkezésre állás, napi sávok
és opcionális 24 órás válaszidő-vonal; a kinyitott csoportok felsorolják a
gyermekeiket), közelgő karbantartást és lezárt incidenseket. Az oldalakat a
`GET`/`POST /api/v1/workspaces/{workspace}/status-pages`,
`PATCH`/`DELETE .../status-pages/{statusPage}` és
`PUT .../status-pages/{statusPage}/layout` végpontokkal lehet kezelni
(`status-pages:read` / `status-pages:write`).

| Cím | Mit szolgál ki |
| --- | --- |
| `{APP_URL}/status/{slug}` | Az oldal, a telepítés bármely hosztnevén |
| `https://{custom_domain}/` | Az oldal, ha a kérés `Host` fejléce az oldal egyedi domainje |
| `{APP_URL}/status/{slug}/feed.atom`, `/feed.rss` | Az incidensek és karbantartások Atom- és RSS-feedjei |
| `{APP_URL}/status/{slug}/summary.json` | JSON-összefoglaló, bármely weboldalról olvasható (CORS) |
| `{APP_URL}/status/{slug}/badge.svg`, `/badge/{monitor_id}/{kind}.svg` | Állapot-, rendelkezésre állás- és válaszidő-badge-ek |

Egyedi domainen ugyanezek a végpontok a `/feed.atom`, `/feed.rss`,
`/summary.json`, `/badge.svg` és `/badge/{monitor_id}/{kind}.svg` címen érhetők el.

Csak a publikált oldalak szolgálódnak ki; egy nem publikált oldal pontosan úgy
válaszol, mint egy nem létező (404). Semmi más nem jelenik meg soha: sem
monitorcélpontok, URL-ek, hibaüzenetek, ellenőrzési eredmények, sem olyan
monitorok, amelyek nincsenek az oldalon. Az oldal egyszerű HTML beágyazott CSS-sel
és SVG-vel, JavaScript, külső betűtípusok és stíluslapok nélkül, 60
másodpercenként magától újratöltődik, az oldal nyelvén (`en` vagy `hu`)
jelenik meg, az időpontok a munkaterület időzónájában. A nyilvános oldalak nem
állítanak be sütit, és kliens-IP-nként percenként `STATUS_RATE_LIMIT` kérésre
vannak korlátozva (alapból 300). Az egy NAT mögötti látogatók egy IP-n, így
egy kereten osztoznak.

**Egyedi domain.** Az egyedi domainek ki vannak kapcsolva, amíg a
`STATUS_CUSTOM_DOMAINS` fel nem sorolja, mely hosztneveket foglalhatják el az
oldalak, vesszővel elválasztva: pontos hosztokat (`status.example.com`) vagy
utótagokat (`*.example.com`, amely magát az `example.com`-ot nem fedi le).
Amíg üres, az API-n át egyedi domaint beállítani 403-mal, a listán kívüli
hosztot 422-vel utasítja el a rendszer. Az admin felületen vagy az API-n
indított importok elhagyják a lista által nem engedett domaineket (lásd
[Migrálás Uptime Kumából](#migrálás-uptime-kumából)); a parancssorra a lista
nem vonatkozik. A korábban beállított domainek tovább működnek, és az API-n
továbbra is eltávolíthatók (az admin felület jelenleg elrejti a mezőt, amíg a
lista üres).

Irányítsd a domain DNS-ét a reverse proxyra, ott végezd a TLS
lezárását (az egyedi domainek automatikus tanúsítványai egy későbbi kiadásra
vannak tervezve), és az eredeti `Host` fejléccel továbbítsd a kéréseket a
konténernek. Minden egyedi domain az oldaláé, akár publikált, akár nem: egy
publikált oldal domainje csak a státuszútvonalakat (`/`, valamint a feed,
summary és badge útvonalak) és az `/up` címet, egy nem publikált oldalé csak az
`/up` címet szolgálja ki; minden más rajta, az `/admin/`, az `/api/`, a
`/sanctum/` és a `/broadcasting/` is, 404, akkor is, ha az adatbázis nem
elérhető (a domainek utolsó ismert listája alapján). Bármely
más hosztnév vagy IP-cím a szokásos módon éri el az alkalmazást. A kanonikus és
a feed-hivatkozások mindig az oldal saját címét használják
(`https://{custom_domain}` vagy `APP_URL`), sosem a kérés `Host` fejlécét.
Reverse proxy mögött állítsd a `TRUSTED_PROXIES` értéket a proxy címére, hogy a
kliens címét a rendszer a proxy továbbító fejléceiből vegye; különben minden
látogató a proxy egyetlen keretébe számít. A korlát hozzávetőleges:
atomi növelés nélkül, a fájl-gyorsítótárban számolódik.

**Gyorsítótár.** A renderelt oldalak fájl-gyorsítótárban vannak
(`STATUS_PAGE_CACHE_PATH`, a képben a `/data` alatt). A gyorsítótárazott oldalt
adatbázis-lekérdezés nélkül szolgálja ki a rendszer, amíg 60 másodperces nem lesz
(`status.cache.ttl_seconds`), el nem indul vagy véget nem ér egy karbantartási
ablak az oldalon, az oldalt vagy valamit, amit mutat, nem szerkesztenek, vagy a
munkaterület valamelyik monitora állapotot nem vált; ekkor a következő kérés
újrarendereli, miközben az egyidejű kérések tovább az előző példányt kapják. A
válaszok `Cache-Control: public, max-age=30, stale-while-revalidate=60, stale-if-error=86400`
fejlécet és erős `ETag`-et hordoznak (az `If-None-Match` 304-et ad). Ha az
adatbázis renderelés közben hibázik, az utolsó jó példány szolgálódik ki
`X-Status-Stale: 1` fejléccel, és az adatbázist legfeljebb 30 másodpercenként
próbálja újra a rendszer; a példány 7 napig marad meg
(`status.cache.stale_seconds`). Ha egyáltalán nincs példány, az oldal 503-mal
és `Retry-After: 30` fejléccel válaszol. Az adatbázishoz való csatlakozás
hibája (nem egyetlen lekérdezés hibája) minden más, adatbázist igénylő
nyilvános kérést is eltilt 30 másodpercre a próbálkozástól:
ezek a gyorsítótárazott példányukból vagy azonnal 503-mal válaszolnak. Az
adatbázis-kapcsolatok 3 másodperc után feladják (`DB_CONNECT_TIMEOUT`), így egy
elérhetetlen adatbázis-hoszt nem tarthatja vissza a kéréseket percekig.

Egy incidens, incidensfrissítés, karbantartás, monitor vagy oldalelrendezés
szerkesztése vagy törlése az érintett oldalak utolsó jó példányait is eldobja,
így a törölt tartalom kiesés alatt sem bukkan fel újra; az ilyen oldal 503-mal
válaszol, amíg újra nem renderelhető.

Két változás csak a 60 másodperces TTL-en át jut el a gyorsítótárazott oldalra:
egy olyan karbantartás, amely az oldalon lévő monitor egy ős-csoportját célozza
(nem a monitort vagy a közvetlen csoportját), és a munkaterület időzónájának
megváltoztatása.

Egy oldal egyedi domainjét csak munkaterület-adminok állíthatják be vagy
módosíthatják, csak a `STATUS_CUSTOM_DOMAINS` által engedett hosztra, és az nem
lehet sem az `APP_URL` hosztja, sem az a hoszt, amelyre az API-kérés érkezett.

#### Badge-ek

A badge-ek kis SVG-képek egy README-be vagy weboldalra. Az összesített badge az
oldal címét és állapotát mutatja; egy komponens-badge az oldal egy monitorát
(a monitor azonosítója az API-beli szám), és 404-et ad olyan monitorra, amely
nincs kint az adott publikált oldalon.

| Útvonal | Mit mutat |
| --- | --- |
| `/status/{slug}/badge.svg` | Az oldal címe és az összesített állapot |
| `/status/{slug}/badge/{monitor_id}/status.svg` | A komponens aktuális állapota |
| `/status/{slug}/badge/{monitor_id}/uptime.svg` | Rendelkezésre állás százalékban |
| `/status/{slug}/badge/{monitor_id}/latency.svg` | Az elmúlt 24 óra átlagos válaszideje (`n/a` adat nélkül) |

Lekérdezési paraméterek, mind opcionális; az érvénytelen érték az
alapértelmezettre esik vissza ahelyett, hogy elrontaná a képet:

* `days`: a rendelkezésre állás ablaka, `1`, `7`, `30` (alapérték), `90` vagy `365`.
* `label`: lecseréli a bal oldali szöveget, legfeljebb 40 karakter. A szöveg
  escape-elődik.
* `style`: `flat` (alapérték) vagy `flat-square`.

```markdown
![status](https://watch.example.com/status/acme/badge.svg)
![website uptime](https://watch.example.com/status/acme/badge/42/uptime.svg?days=90&label=website)
```

Színek: működő zöld, csökkent teljesítményű sárga, részleges kiesés narancs,
súlyos kiesés piros, karbantartás kék, ismeretlen szürke; a rendelkezésre állás
99,9%-tól zöld, 99%-tól sárgászöld, 95%-tól sárga, alatta piros. A
rendelkezésre állás és a válaszidő rejtett (`n/a`) arra a komponensre, amelynek
rendelkezésre állását az oldal elrejti, vagy amelynek oldala elrejti a
válaszidőt. A badge-eket `Cache-Control: public, max-age=60` fejléccel szolgálja
ki a rendszer.

#### Feedek és JSON-összefoglaló

A `feed.atom` (Atom 1.0) és a `feed.rss` (RSS 2.0) az oldal legfrissebb 50
incidensét és karbantartási ablakát listázza, mindegyiket az összes
frissítésével sorrendben, és egy `{page address}#incident-{id}` vagy
`#maintenance-{id}` alakú hivatkozással. A feedek azokat az incidenseket fedik,
amelyek az oldal előzménybeállításán belül nyitottak vagy lezártak, valamint azt a
karbantartást, amely folyamatban van, az ablakbeállításán belül közelgő, vagy
az elmúlt 30 napban véget ért.
A karbantartás egyetlen bejegyzés: a folyamatban lévő vagy közelgő ablaka,
különben a legutóbbi, ezen 30 napon belül véget ért ablaka. Egy ismétlődő
karbantartás korábbi ablakai nincsenek listázva.

A `summary.json` más oldalak státuszwidgetjeihez készült, és
`Access-Control-Allow-Origin: *` fejléccel megy ki:

```json
{
  "page": {"title": "Acme", "url": "https://watch.example.com/status/acme", "status": "operational", "updated_at": "2026-11-07T12:30:00Z"},
  "components": [{"id": 42, "name": "Website", "status": "operational"}],
  "incidents": [{"id": 7, "title": "Slow site", "impact": "minor", "status": "monitoring", "url": "https://watch.example.com/status/acme#incident-7"}],
  "maintenances": [{"id": 3, "title": "Database upgrade", "starts_at": "2026-11-08T12:30:00Z", "ends_at": "2026-11-08T13:30:00Z"}]
}
```

Az `incidents` a nyitott incidenseket listázza; a `children` csak a kinyitott
csoportoknál jelenik meg. A feedek, az összefoglaló és a badge-ek a laphoz
hasonlóan gyorsítótárazottak, ugyanazt az érvénytelenítést osztják vele, és
gyorsítótárazott állapotban adatbázis-lekérdezés nélkül szolgálódnak ki.

## Fejlesztés

```bash
composer install
php artisan test
composer analyse
vendor/bin/pint --test
bash tests/docker/smoke.sh          # image smoke test (add --pgsql for PostgreSQL)
```

### A kép építése

A `scripts/build-image.sh` több platformra építi a képet egy helyi OCI-archívumba,
és soha nem tölt fel semmit. Létrehoz (vagy újrahasznosít) egy
`intrawatch-builder` nevű buildx buildert:

```bash
scripts/build-image.sh --version 1.0.0 --platforms linux/amd64,linux/arm64 --out build
```

A `--out` csak a `build/` (vagy alatta lévő) könyvtár, illetve a repón kívüli
könyvtár lehet, hogy az archívum sose kerüljön a Docker build-kontextusba;
minden más útvonal 2-es kilépési kóddal leáll. Az archívum a `build/intrawatch-<version>.oci.tar` fájlba kerül, a szkript pedig
kilistázza az `index.json`-ban szereplő platformokat és a méreteket. A
`--load-platform linux/amd64` kapcsolóval egy platformot a helyi Docker-motorba
is betölthetsz `intrawatch:<version>-amd64` néven. A gazdagéptől eltérő platform
QEMU-emuláció alatt fut, ami a PHP-bővítményeknél akár egy óráig is eltarthat.

A smoke teszt ilyen képen is futtatható. A `SMOKE_PLATFORM` a `--platform`
kapcsolót adja át az építésnek és a futtatásoknak, a `SMOKE_SKIP_BUILD=1` egy
meglévő `SMOKE_IMAGE`-et használ, a `--quick` pedig csak a monitor-, státuszoldal-,
admin- és importszakaszt futtatja, háromszoros időkorlátokkal:

```bash
SMOKE_PLATFORM=linux/amd64 SMOKE_SKIP_BUILD=1 SMOKE_IMAGE=intrawatch:1.0.0-amd64 \
  bash tests/docker/smoke.sh --quick
```

A kiadás lépései a [docs/RELEASE.md](docs/RELEASE.md) fájlban vannak; a
verziók közti változásokat a [CHANGELOG.md](CHANGELOG.md) rögzíti.

### Admin felület

Az admin felület egy Vue single-page alkalmazás az `spa/` könyvtárban (Node.js
22 vagy újabb). A szerver az `/admin/` alatti minden útvonalra elküldi a héját,
a futásidejű konfigurációval egy `<meta name="intrawatch-config">` címkében, az
ujjlenyomatos fájljait pedig az `/admin/assets/` alól. Build nélkül az
`/admin/` 503-at ad.

```bash
cd spa
npm ci
npm run dev       # Vite dev server on http://localhost:5173/admin/
npm run verify    # lint, type check, unit tests and production build
npm run e2e       # end-to-end run in Chromium against a freshly built image
```

Az `npm run e2e` (`spa/e2e/run.sh`) `intrawatch:smoke-spa` néven építi a képet,
friss kötettel a 33300-as porton indítja (`APP_URL=http://localhost:33300`), és
lefuttatja a `spa/e2e/` alatti Playwright-folyamatot: a beállítási varázslót,
egy élőben feltűnő HTTP monitort, egy webhook-próbaküldést, egy karbantartási
ablakot, egy publikált státuszoldalt, a kétfaktoros bejelentkezést és az
`/admin/` egyedi domain melletti 404-ét. A konténer és a kötete mindig törlődik.
A böngészőt egyszer kell telepíteni: `npx playwright install chromium`; az
`E2E_SKIP_BUILD=1` egy meglévő képet használ újra. Nem része az `npm run verify`
parancsnak.

A dev szerver az `/api`, `/sanctum`, `/broadcasting` és az `/app` websocket
útvonalakat a `VITE_BACKEND` címre proxyzza (alapból `http://127.0.0.1:8000`,
vagyis `php artisan serve`). Mutass vele egy futó képre
(`VITE_BACKEND=http://localhost:3000`), hogy az élő frissítések a kép Reverb-
proxyján át is működjenek. Az `npm run build` az `spa/dist` könyvtárba ír, amelyre
az `intrawatch.spa_path` beállítás mutat.

A dev szerveren át történő bejelentkezés sütialapú munkamenetet használ, ezért a
backendnek a Vite címét (origin) sütialapú munkamenetet használó (stateful)
domainként kell kezelnie. Add hozzá a Sanctum listájához, például
`SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000`.

A kép az alkalmazást a `docker/Dockerfile` egy Node.js szakaszában építi
(`npm ci` és `npm run build`), és csak az `spa/dist` könyvtárat másolja a
futásidejű képbe; a Node.js nem része a végső képnek.

Az ütemezés-eltérési benchmark egy eldobható munkaterületet tölt fel HTTP
monitorokkal egy helyi célpont ellen, amely 50–300 ms alatt válaszol, egy
ellenőrző worker-t futtat, és kiírja az ütemezéstől való késés mediánját, 95.
percentilisét és maximumát, a percenkénti ellenőrzések számát és a leghosszabb
eredmény-kiírást. 1-gyel lép ki, ha a maximális késés eléri a két másodpercet, ha
a várt ellenőrzések 95%-ánál kevesebb futott le, vagy ha bármelyik ellenőrzés
nem volt up. Futtasd külön konténerben, friss kötettel és az alapértelmezett
SQLite adatbázissal: nem hajlandó futni élő ellenőrző worker mellett, és olyan
adatbázison sem, amely már tartalmaz munkaterületeket, hacsak nem adod meg a
`--force` kapcsolót. A Ctrl-C vagy a `docker stop` korán befejezi a futást, és
még így is törli a benchmark munkaterületét.

```bash
docker run --rm --cpus=2 intrawatch \
  php artisan intrawatch:bench --monitors=1000 --interval=60 --duration=180
```

A státuszoldal-benchmark egy eldobható munkaterületet tölt fel 300 publikált
oldallal, oldalanként 20 komponenssel és 90 napnyi rollup-pal. Ezután az
alkalmazáson keresztül, folyamaton belül küld kéréseket. A hideg (üres
gyorsítótárral induló) körben oldalanként egy kérést küld, így minden oldal
egyszer renderelődik. A meleg (gyorsítótárból kiszolgált) körben 3 000 kérést
küld körforgásban az oldalra, a feedjeire, az összefoglalóra és a badge-re.
Mindkét körről kiírja a mediánt, a 95. percentilist és a maximumot, a meleg
körről az adatbázis-lekérdezések számát is. 1-gyel lép ki, ha a meleg kör 95.
percentilise eléri a 100 ms-ot, vagy ha bármelyik meleg kérés lekérdezte az
adatbázist. Saját gyorsítótár-könyvtárat használ. Nem hajlandó futni olyan
adatbázison, amely már tartalmaz munkaterületeket, hacsak nem adod meg a
`--force` kapcsolót, és a végén törli az adatait, hacsak nem adod meg a
`--keep` kapcsolót.

```bash
php artisan intrawatch:bench:status --pages=300 --items=20 --requests=3000
```

A skálabenchmark egy eldobható munkaterületet (`bench-scale`) tölt fel 5 000
aktív monitorral (60% HTTP, a többi TCP, ping, DNS és push, 250 egymásba
ágyazott csoportban, 50 címkével, 15 inaktív csatornával, 90 napnyi nap- és 48
órányi óra-rollup-pal), 300 húsz elemes státuszoldallal, valamint néhány
riasztással, incidenssel, karbantartással és auditesemény. A monitorok magát
a mért alkalmazást ellenőrzik (`/robots.txt`, a portját, `127.0.0.1` és
`localhost`), így a futó worker viszi a valódi ellenőrzési terhelést. A
`--settle` másodpercnyi várakozás után valódi HTTP-n méri a `--base-url` címen,
egy általa létrehozott API-tokennel. Tíz admin listát és minden státuszoldalt
mér, előbb hideg (üres gyorsítótárral induló), majd meleg (gyorsítótárból
kiszolgált) körben, és táblázatot meg JSON-összefoglalót ír ki. 1-gyel lép ki,
ha bármelyik admin lista 95. percentilise eléri az 500 ms-ot, ha a státuszoldalak
meleg körének 95. percentilise eléri a 100 ms-ot, vagy ha egy kérés hibázik.
Futtasd egy futó konténerben, `TRUSTED_PROXIES=127.0.0.1` mellett. A
státuszoldal-kérések ugyanis sok kliens-címről érkeznek az `X-Forwarded-For`
fejlécen át, így a nyilvános sebességkorlát kimarad a mérésből. Az ellenőrzések bármiféle
hálózati szabálykapcsoló nélkül elérik a loopbacket, mert alapból csak a
beépített felhős metaadat-címek tiltottak (a `CHECK_BLOCKED_NETWORKS` üres).
A többi benchmarkhoz hasonlóan nem hajlandó futni olyan adatbázison, amely már
tartalmaz munkaterületeket, hacsak nem adod meg a `--force` kapcsolót, és a
végén törli az adatait, hacsak nem adod meg a `--keep` kapcsolót.

> **Figyelmeztetés:** a `TRUSTED_PROXIES=127.0.0.1` kizárólag a benchmark-
> konténerre vonatkozó beállítás. Hatására az alkalmazás megbízik a kliensek
> által küldött `X-Forwarded-For` fejlécben, ami kijátssza a nyilvános
> státuszoldalak IP-nkénti sebességkorlátját. Éles környezetben soha ne állítsd
> be, hacsak egy valódi reverse proxy nem az adott címen állítja be a fejlécet.
> Ha a státuszkérések 429-et adnak, a benchmark rákérdez, hogy nem hiányzik-e
> a beállítás.

```bash
docker exec intrawatch php artisan intrawatch:bench:scale \
  --monitors=5000 --pages=300 --items=20 --requests=2000 --base-url=http://127.0.0.1:3000
```

Az Uptime Kuma import-benchmark a `tests/Fixtures/kuma/kuma2-schema.sql` alapján
generál egy 2.x SQLite-adatbázist (5 000 monitor és 250 csoport, 300 húsz elemes
státuszoldal, 30 napnyi órás és 365 napnyi napi statisztika 500 monitorhoz),
importálja a beállított adatbázis-kapcsolaton, kiírja az egyes lépések idejét
és a memória csúcsértékét, és 300 másodpercnél hibával leáll. A `bench`
csoportban van, amelyet az alapértelmezett tesztfuttatás kihagy:

```bash
vendor/bin/phpunit --testsuite Bench --group bench
```

A mért eredmények és a hardver, amelyen futottak, a
[docs/BENCHMARKS.md](docs/BENCHMARKS.md) fájlban vannak.

A `docker run` számára átadott parancs a szokásos beállítás után fut a
szolgáltatások helyett. Ez a beállítás a konfigurált adatbázist a kép saját
verziójával migrálja; az `INTRAWATCH_SKIP_MIGRATE=1` egy olyan adatbázison futtat
egyszeri parancsot, amelyet másik verzió kezel.

A Guzzle 7.x-hez van rögzítve (`guzzlehttp/guzzle ^7.9`): a Laravel Reverb a
`guzzlehttp/psr7 ^2.6`-ot követeli meg, míg a Guzzle 8-hoz psr7 3.x kell. Oldd
fel a rögzítést, amint egy Reverb-kiadás engedi a psr7 3-at.

Az IntraWatch MIT licenc alatt érhető el. Kapcsolat: info@intragile.eu

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
