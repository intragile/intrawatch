# Importálás Uptime Kumából

Ha eddig Uptime Kumát használt, a monitorokat, értesítési csatornákat, címkéket, állapotoldalakat, incidenseket és karbantartási ablakokat egy fájl feltöltésével átviheti egy IntraWatch-munkaterületre. Az importálás két lépésben történik: előbb elemzés, utána a tényleges import.

## Ki importálhat?

Importálni **Adminisztrátor** vagy **Tulajdonos** szerepkörrel lehet. A menüben ilyenkor a **Munkaterület kezelése** csoportban megjelenik az [[icon:mdi-database-import-outline]] **Importálás Uptime Kumából** menüpont.

Az importált elemek abba a munkaterületbe kerülnek, amelyben az importot elindítja. Ha több Uptime Kuma-példányt hoz át, érdemes mindegyiket külön munkaterületre importálni.

## Támogatott fájlok

| Forrás | Uptime Kuma-verzió | Mit tartalmaz |
| --- | --- | --- |
| SQLite-adatbázis (`.db`, `.sqlite`, `.sqlite3`), például a `kuma.db` másolata | 2.x és 1.23 | minden támogatott elemtípust |
| JSON-mentés (az Uptime Kuma 1.23 mentési funkciójával készült fájl) | 1.23 | csak monitorokat, értesítési csatornákat és címkéket |

A feltölthető fájl legnagyobb méretét a feltöltő űrlap kiírja. SQL-dump nem tölthető fel. Az Uptime Kuma 2.x nem készít JSON-mentést, ezért abból az adatbázisfájlt importálja.

:::tip

Az adatbázisról konzisztens másolatot készítsen. Ehhez állítsa le az Uptime Kumát a másolás előtt, vagy futás közben adja ki a `sqlite3 kuma.db ".backup kuma-export.db"` parancsot, és a keletkező fájlt töltse fel.

:::

## Feltöltés és elemzés

:::steps

1. Nyissa meg az [[route:ws.import|Importálás Uptime Kumából]] oldalt.
2. A **Fájl feltöltése** részben válassza ki a fájlt a **Kuma-adatbázis vagy mentés** mezőben.
3. Döntse el, kéri-e az **Üzemidő-előzmények átvétele** beállítást. Alapértelmezés szerint be van kapcsolva.
4. Ha az importált monitorokat egyelőre nem szeretné elindítani, kapcsolja be a **Monitorok importálása szüneteltetve** beállítást.
5. Kattintson a **Feltöltés és elemzés** gombra.

:::

Az elemzés a háttérben fut, és az oldal magától frissül. Az elemzés alatt a munkaterületen még semmi sem jön létre.

Egy munkaterületen egyszerre csak egy nyitott import lehet. Ha egy korábbi importot még nem fejezett be, előbb fejezze be vagy törölje, csak utána tölthet fel új fájlt.

## Az elemzési jelentés

Az elemzés végén megjelenik az **Elemzési jelentés**:

- a **Darabszámok elemtípus és eredmény szerint** táblázat elemtípusonként összesíti, mi történne az importnál;
- a **Nem átvett adatok** rész felsorolja, ami nem kerül át, és amit újra be kell állítania;
- az **Előzmények** rész mutatja, hány monitor üzemidő-előzménye kerül át;
- a **Megjegyzések** rész további tudnivalókat tartalmaz;
- az **Elemek** táblázat soronként mutatja az eredményt és a **Részletek** oszlopban az okát.

Az **Elemek** táblázat alapból a **Figyelmet igényel** szűrővel nyílik meg, vagyis azokat a sorokat mutatja, amelyek nem jönnek létre. Az **Elemtípus** és az **Eredmény** választóval szűkítheti a listát.

A lehetséges eredmények:

| Eredmény | Jelentés |
| --- | --- |
| Létrejönne | Az import létrehozná (csak az elemzésben). |
| Létrejött | Az import létrehozta. |
| Már megvan | Egy korábbi import már átvette; nem változik semmi. |
| Kihagyva | Az IntraWatchban nincs megfelelője. |
| Érvénytelen | Nem felelt meg az ellenőrzésnek; a részletek a hibát mutatják. |
| Sikertelen | Váratlan hiba történt. |

## Az import futtatása

Ha a jelentés rendben van, kattintson az **Import futtatása** gombra, és a megerősítő ablakban ismét az **Import futtatása** gombra. Az ablak összesíti, mi jön létre. A már meglévő elemekhez az import nem nyúl.

Az import is a háttérben fut. Ha végzett, megjelenik **Az import befejeződött.** üzenet és az **Importjelentés**. Az üzenetben lévő link, **A monitorok listájának megnyitása**, a [[route:ws.monitors|Monitorok]] oldalra visz.

Az importot nyugodtan lefuttathatja újra ugyanabból a forrásból. A korábban átvett elemek **Már megvan** eredményt kapnak, és nem változnak. Így a munkaterületen időközben végzett módosításai és törlései megmaradnak.

:::warning

Ha ugyanazt az Uptime Kuma-példányt egyszer JSON-mentésből, egyszer adatbázisfájlból importálja, az IntraWatch nem tudja összepárosítani az elemeket, és duplikátumok keletkeznek.

:::

## Amit az import nem visz át

A felhasználókat, az API-kulcsokat, a Docker-gazdagépeket és a távoli böngészőket az import nem veszi át, ezeket a jelentés **Nem átvett adatok** része számolja. Kihagyja továbbá azokat a monitortípusokat és értesítési szolgáltatókat, amelyeket az IntraWatch nem támogat, valamint a kézi, ütemezés nélküli karbantartásokat. Ezeket a jelentés megjegyzéssel jelzi.

Az importálás után ellenőrizze az értesítési csatornákat, és küldjön tesztüzenetet. Részletek: [[manual:monitoring/notifications|Értesítések]].

## Korábbi importok

A **Korábbi importok** táblázat felsorolja a munkaterület importjait az **Állapot**, a **Dátum** és a **Fájl** oszlopokkal.

- Az [[icon:mdi-file-document-outline]] **Jelentés megnyitása** ikonnal újra megtekintheti a jelentést.
- A [[icon:mdi-delete-outline]] **Import törlése** ikonnal törölheti az importot és a jelentését. A már létrehozott elemek megmaradnak. Sorban álló vagy folyamatban lévő importot nem lehet törölni.

A feltöltött fájlt az IntraWatch az import befejezése vagy sikertelensége után, illetve legkésőbb 24 óra elteltével törli. Ha egy elemzett importot 24 órán belül nem futtat le, a fájlt újra fel kell töltenie.
