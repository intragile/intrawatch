# Változásnapló

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[English](CHANGELOG.md) | Magyar

Az IntraWatch minden lényeges változása itt olvasható. A formátum a
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) ajánlását követi, a
verziószámozás pedig a [Semantic Versioning](https://semver.org/) szabályait.

## [1.2.0] - 2026-11-09

Bármilyen változásra élőben frissülő csoportsávok, színek nélkül is jól
olvasható kiesési napok a státuszoldalon, és saját címkék a Docker-image-en.

### Admin felület

- A csoport ellenőrzési sávja akkor is élőben frissül, ha a csoportot nem egy
  ellenőrzés, hanem egy változás értékeli újra: egy tag szüneteltetése,
  folytatása, áthelyezése, törlése vagy hozzáadása, a csoport szerkesztése,
  illetve egy karbantartási ablak kezdete vagy vége.
- A monitor ellenőrzési sávja alatti skála a ténylegesen megjelenített
  ellenőrzéseket számolja, nem mindig 50-et ír ki.

### Nyilvános státuszoldal

- A súlyos kiesést jelző napok a piros szín mellett sraffozást is kapnak, mint
  az admin felületen a leállt ellenőrzések, így színek nélkül is
  felismerhetők.

### Docker-image

- Az image címkéi az IntraWatch saját commitját, építési idejét és
  projekt-URL-jét adják meg, nem a FrankenPHP alapimage-től örökölt értékeket.

## [1.1.1] - 2026-11-09

Javítások az 1.1.0-s megújításhoz.

### Admin felület

- A natív dátumválasztók követik a világos vagy sötét témát, a naptárikon is
  a témához igazodik.
- A válaszidő-diagram időtengelye a felület nyelvét és az időzónádat követi
  a rögzített amerikai formátum helyett. A dátum az első osztásnál és ott
  jelenik meg, ahol a nap váltik.
- A csoport ellenőrzési sávja mostantól élőben követi a kiértékeléseket. Ha egy
  csoportot még nem értékelt ki a rendszer, ezt ki is írja, üres helyek
  helyett.

### Nyilvános státuszoldal

- A napi sávok követik a sötét témát, és az admin felület színpalettáját
  használják; a karbantartás ibolyaszínű, ahogy az admin felületen is.

## [1.1.0] - 2026-11-08

Megújult admin felület és nyilvános státuszoldal.

### Admin felület

- Oldalsáv a munkaterület-váltóval, a navigációval és a fiókmenüvel; kis
  képernyőn felső sáv.
- Széles képernyőn a monitorok a lista mellett nyílnak meg (lista–részletek
  nézet). Minden sor mutatja a monitor állapotjelét, a válaszidőt vagy az
  állapotot, azt, hogy mióta tart, valamint a legutóbbi 40 ellenőrzés sávját.
  A darabszámot is mutató állapotcímkék szűrik a listát; a ritkábban használt
  szűrők a „További szűrők”, a tömeges műveletek a „Kijelölés” mögött
  találhatók.
- Ha nincs megnyitott monitor, egy áttekintés állapotonként megszámolja a
  monitorokat, és felsorolja a figyelmet igénylőket.
- A monitor részletező oldala az állapottal és annak időtartamával kezdődik,
  ezt követi a legutóbbi 50 ellenőrzés sávja, a rendelkezésre állási adatok és
  a válaszidő-diagram.
- Egységes oldalminták: szakaszokra bontott űrlapok, panelen elhelyezett
  táblázatok, szűrőgombok.
- Barlow betűtípus, új világos és sötét színpaletta, valamint a piros szín
  mellett sraffozott leállt állapotok, így színek nélkül is felismerhetők.
- Az IntraWatch pulzusjele mint logó, favicon és alkalmazásikon.

### Nyilvános státuszoldal

- Saját szerverről kiszolgált Barlow betűtípusok és az admin felülettel azonos
  színpaletta.
- Az összesített állapot állapotjellel ellátott címsor lett a színes sávon
  megjelenő fehér szöveg helyett; az állapotszövegek színei világos és sötét
  témában is megfelelő kontrasztot adnak.
- Ha az oldal nem ad meg saját favicont, alapértelmezésként az IntraWatch jele
  jelenik meg.

### API

- A `GET /workspaces/{workspace}/monitors` elfogad egy opcionális `beats`
  paramétert (1–50), és ilyenkor minden monitor legutóbbi ellenőrzéseit is
  visszaadja `recent_beats` néven.

### Biztonság

- A státuszoldal Content Security Policy-ja engedi az azonos originről érkező
  betűtípusokat és képeket (`font-src 'self'`, `img-src 'self' https: data:`).

## [1.0.0] - 2026-11-08

Az első kiadás: ingyenes, saját szerveren futtatható uptime-monitor és
státuszoldal, egyetlen Docker-image-ként, az összes állapottal egyetlen
kötetben.

### Platform

- Egyetlen image (FrankenPHP, PHP 8.5) `linux/amd64` és `linux/arm64`
  platformra; a webszerver, a queue worker-ek, az ellenőrző worker-ek, az
  ütemező és a websocket-szerver egyetlen supervisor alatt fut. Ha valamelyik
  program nem tartható életben, a konténer kilép, és az újraindítási szabály
  lecseréli.
- Alapértelmezésként SQLite; MySQL 8.4 és PostgreSQL 16 a `DB_*`
  beállításokkal.
- Az alkalmazáskulcs és a websocket-hitelesítő adatok az első indításkor
  jönnek létre, és a `/data` alatt maradnak meg. A konténer jogosultságok
  nélküli felhasználóként fut.
- Helyi, többarchitektúrás build (`scripts/build-image.sh`) és kiadási
  ellenőrzőlista (`docs/RELEASE.md`).

### Fiókok, munkaterületek és API

- Első indítási beállítás, munkamenetes bejelentkezés TOTP-alapú kétlépcsős
  azonosítással, jelszó-visszaállítás és helyreállítási parancsok.
- Munkaterületek tulajdonos, adminisztrátor, szerkesztő és megtekintő
  szerepkörrel.
- Területenkénti jogosultságokkal rendelkező API-tokenek; minden admin
  művelet elérhető a nyilvános `/api/v1` API-n, amelyet egy generált,
  a `/api/v1/openapi.json` címen kiszolgált OpenAPI-dokumentum ír le.
- Hibák `application/problem+json` formátumban, kurzoros lapozás a
  listavégpontokon, valamint eseménynapló.

### Monitorozás

- Monitortípusok: `http` (státuszkódok, kulcsszó, JSON-lekérdezés, fejlécek,
  átirányítások, basic és bearer hitelesítés, mTLS, proxy), `tcp`, `ping`
  (`NET_RAW` nélkül), `dns` (rekordok, elvárt értékek, változásészlelés), `tls`
  (lejárat, lánc, protokollok, titkosítási készletek, STARTTLS), `domain` (RDAP,
  WHOIS-tartalékkal), `push`, `cron` (ütemezés, indulási és befejezési
  jelentések, futási idő) és `group`.
- Ellenőrző worker-ek, folyamatok és konténerek közötti shardolással.
- Nyers eredmények, valamint percre, órára és napra összesített rollupok
  beállítható megőrzési idővel.
- Élő frissítések a beépített websocket-szerveren keresztül.

### Riasztások

- Értesítési csatornák: e-mail, webhook, Slack, Discord, Microsoft Teams,
  Telegram, ntfy, Gotify, Pushover, Matrix, Mattermost, Google Chat,
  PagerDuty, Opsgenie és Apprise.
- Leállási és helyreállási riasztások újrapróbálkozással és
  ingadozáscsillapítással, akadozási és lassú válaszidő miatti riasztások,
  emlékeztetők, tanúsítvány- és DNS-változási riasztások, tesztküldés, valamint
  riasztási előzmények a kézbesítés állapotával.

### Incidensek, karbantartás és státuszoldalak

- Incidensek frissítésekkel és hatásszintekkel.
- Egyszeri és ismétlődő karbantartási ablakok, amelyek előre láthatók.
- Szerveroldalon renderelt nyilvános státuszoldalak oldalankénti
  gyorsítótárral, szakaszokkal és csoportokkal, részleges állapottal, 1 és 365
  nap közötti rendelkezésre állási időablakokkal, napi sávokkal és opcionális
  24 órás válaszidő-görbével, badge-ekkel, RSS- és Atom-feeddel, JSON-összesítővel
  és egyedi domainekkel.

### Admin felület

- Vue egyoldalas alkalmazás az `/admin/` címen, amely kizárólag az `/api/v1`
  API-t használja: első indítási varázsló, bejelentkezés TOTP-vel,
  munkaterületek, monitorok, értesítések, incidensek, karbantartás,
  státuszoldal-szerkesztő és élő irányítópult; angol és magyar nyelven.

### Import és dokumentáció

- Import az Uptime Kuma 2.x és 1.23 verziójából: SQLite-fájlból, Kuma 1.x
  JSON-mentésből vagy élő MariaDB- vagy MySQL-adatbázisból. Átkerülnek a
  monitorok, az értesítési csatornák, a címkék, a státuszoldalak, az
  incidensek, a karbantartási ablakok és a rendelkezésre állási előzmények;
  minden futtatást elemzés (próbafutás) előz meg, a titkok pedig csak
  titkosítva tárolódnak. Az Uptime Kuma a saját szerzőinek projektje; az
  IntraWatch nem áll kapcsolatban vele.
- Benchmarkcsomag és mért eredmények a [docs/BENCHMARKS.md](docs/BENCHMARKS.md)
  fájlban, 5 000 monitorra és 300 státuszoldalra.
- Dokumentáció a [README](README.md)-ben.

### Biztonság

A kiadás előtti biztonsági átvizsgálásból eredő változások. A **teendő**
jelölésű pontok olyan viselkedést módosítanak, amelyre egy meglévő telepítés
támaszkodhat.

- **Teendő:** a tagokat meghívni kell, nem közvetlenül hozzáadni. A
  `POST .../members` `202`-vel válaszol, és e-mailben egyszer használható,
  7 napig érvényes linket küld, amelyet csak az adott címmel bejelentkezett
  felhasználó fogadhat el. A meghívókhoz működő `MAIL_*` beállítások kellenek
  (az alapértelmezett levelező csak naplóz); az `INVITATIONS_PER_HOUR`
  korlátozza a számukat. Tagot közvetlenül csak parancssorból lehet hozzáadni.
- **Teendő:** a `WORKSPACE_CREATORS` (alapértelmezés: `owners`) korlátozza,
  ki hozhat létre munkaterületet; API-tokennel ez soha nem lehetséges.
- **Teendő:** az egyedi státuszdomainek ki vannak kapcsolva, amíg a
  `STATUS_CUSTOM_DOMAINS` fel nem sorolja az engedélyezett hosztokat. A meglévő
  domainek továbbra is működnek; az admin felület csak olvasható módon mutatja
  őket, és az adminisztrátorok eltávolíthatják őket. Minden egyedi domain,
  akár publikált, akár nem, mostantól csak a saját státuszoldalát és az `/up`
  címet szolgálja ki, akkor is, ha az adatbázis nem érhető el.
- **Teendő:** a websocketek alapértelmezésként csak az `APP_URL` hosztját
  fogadják el; a felület minden további elérési hosztját vedd fel a
  `REVERB_ALLOWED_ORIGINS` listába, különben ott leállnak az élő frissítések.
- **Teendő:** a `CHECK_BLOCKED_NETWORKS` alapértelmezése mostantól üres, és a
  felhős metaadat-végpontok egy beépített listáját egészíti ki, amely a
  `169.254.170.2`, `169.254.170.23`, `fd20:ce::254` és `100.100.100.200` címet
  is lefedi. Ezek bármelyikének monitorozásához `CHECK_ALLOW_METADATA=1`
  szükséges. A proxyn át futó HTTP-monitorok mostantól a valódi célt és minden
  átirányítást is ellenőriznek a szabályzat szerint.
- **Teendő:** több gépes telepítésben minden gépen be kell állítani az
  `APP_KEY` és a `REVERB_*` értékeit (különben a konténer figyelmeztet). A
  közös adatbázisok migrációja gyorsítótár-zár alatt, egyszerre egy gépen fut.
- **Teendő:** a konténer – a `docker exec` paranccsal indított PHP-parancsokat
  is beleértve – 027-es umaskkal és root-tulajdonú alkalmazáskóddal fut; a
  `/data` tartalmát más uid alatt olvasó mentőeszközöknek az 1000-es csoport
  tagjainak kell lenniük. A példa compose-fájl a portot csak a `127.0.0.1`
  címen publikálja.
- Egy csatorna vagy monitor célpontjának módosításakor a tárolt titkait újra el
  kell küldeni, vagy el kell távolítani. A webhook-URL-ek, az ntfy-témák és az
  Apprise-konfigurációs kulcsok maszkolva jelennek meg azoknak a
  felhasználóknak, akik nem kezelhetik a csatornákat.
- A példány levelezőjét használó e-mail-csatornákat csak tulajdonosok és
  adminisztrátorok állíthatják be; a tesztküldések munkaterületenként is
  korlátozottak (percenként 30).
- Kuma-import: a JSON-mentések mérete korlátozott (`IMPORT_MAX_JSON_MB`,
  `IMPORT_MAX_JSON_ITEMS`), a feltöltési korlát figyelembe veszi a PHP
  korlátait, a virtuális táblát tartalmazó SQLite-fájlokat elutasítja, az
  összeomlott futtatásokat megszakítottként jelzi, a push tokenek ütközését
  már nem fedi fel (a `push_token_collision` helyett `push_token_regenerated`),
  a 32 karakternél rövidebb push tokeneket lecseréli (az Uptime Kuma saját
  tokenjei 32 karakteresek, és az `/api/push` aliason keresztül továbbra is
  működnek), a felhasználók által indított importok pedig elhagyják azokat az
  egyedi domaineket, amelyeket az engedélyezési lista nem fed le.
- A meghívólinkek a tokent az URL fragmentumában hordozzák
  (`/admin/invitations#token=…`), így az már nem kerül a hozzáférési
  naplókba; a régi, útvonalas formában kiküldött linkek továbbra is működnek. A
  munkaterület és a meghívást küldő felhasználó neve már nem csempészhet Markdownt (linkeket,
  kiemelést) a meghívó e-mailbe, amely mostantól angolul vagy magyarul megy ki
  (`locale`; az admin felület a saját nyelvét küldi).
- Az eseménynapló ugyanazokat a csatornabeállításokat maszkolja, mint a
  csatornavégpontok, így az `audit:read` már nem fedi fel a webhook-URL-eket,
  az ntfy-témákat és az Apprise-konfigurációs kulcsokat. (A monitorok
  eseménynapló-bejegyzései sosem tartalmaztak titkokat; ezt mostantól teszt is
  védi.)
- A monitorok minden tiltott IPv4-hálózat NAT64 (`64:ff9b::/96`) és 6to4
  (`2002::/16`) alakjától is el vannak zárva, a metaadat-végpontokat is
  beleértve.
- A monitor- és csatornatípus-végpontok közzéteszik a `destination_keys`
  mezőt.
- A jelszó-visszaállítás, akár e-mailben, akár parancssorból történik,
  minden munkamenetet kiléptet és minden API-tokent visszavon, és bekerül az
  eseménynaplóba; `X-Content-Type-Options: nosniff` minden válaszon; nincs
  `X-Powered-By` fejléc; a `STATUS_RATE_LIMIT` a nyilvános státuszoldal
  kéréskorlátját teszi beállíthatóvá.
- A `scripts/build-image.sh` elutasítja a build-kontextuson belüli `--out`
  értéket.

### Ismert korlátok

- Közös MySQL- vagy PostgreSQL-adatbázison a migráció közben váratlanul leállított gép
  akár egy órára is visszahagyja a migrációs zárat, és a többi gép addig vár
  rá. Ha egyik gép sem migrál éppen, a `php artisan cache:clear` feloldja (lásd
  a README több gépes telepítésről szóló részét).

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
