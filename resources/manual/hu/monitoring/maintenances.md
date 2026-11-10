# Karbantartások

A karbantartási ablak idejére a kiválasztott monitorok nem riasztanak, a státuszoldal pedig előre jelzi a látogatóknak a tervezett munkát. Ez a fejezet bemutatja, hogyan ütemezhet egyszeri vagy ismétlődő karbantartást, és hogyan zárhatja le idő előtt.

## Mit csinál a karbantartási ablak?

Amíg egy ablak tart, az érintett monitorok **Karbantartás** állapotba kerülnek, függetlenül attól, hogy az ellenőrzésük sikeres vagy sikertelen. Ebben az állapotban a monitor nem küld riasztást, így egy tervezett újraindítás vagy frissítés nem ébreszti fel feleslegesen a kollégáit.

Amikor az ablak véget ér, a monitorok visszatérnek a szokásos működéshez. Ha egy szolgáltatás ekkor is leállt, a monitor riaszt, kivéve, ha erről a leállásról már a karbantartás előtt küldött riasztást.

Ha egy csoportmonitort választ ki, a karbantartás a csoport összes tagjára is vonatkozik.

A karbantartásokat mindenki megtekintheti a munkaterületen. Ütemezésükhöz és módosításukhoz legalább **Szerkesztő** szerepkör kell.

## Új karbantartás ütemezése

:::steps

1. Nyissa meg a [[route:ws.maintenances|Karbantartás]] képernyőt, és kattintson az [[icon:mdi-plus]] **Karbantartás ütemezése** gombra.
2. Adja meg a **Cím** mezőt, és ha szükséges, a **Leírás** mezőt.
3. Válassza ki az **Ütemezés típusa** és az **Időzóna** értékét, majd töltse ki az időpontokat (lásd lent).
4. Az **Érintett figyelők és státuszoldalak** alatt válasszon ki legalább egy monitort az **Érintett figyelők** mezőben (a felületen „figyelő” néven).
5. Ha olyan státuszoldalon is meg szeretné jeleníteni a karbantartást, amelyen egyik érintett monitor sem szerepel, válassza ki azt is.
6. Kattintson a **Létrehozás** gombra.

:::

Az **Aktív** kapcsoló alapból be van kapcsolva. Az inaktív karbantartás sosem indul el, így egy ütemezést törlés nélkül is felfüggeszthet.

## Ütemezési típusok

| Ütemezés típusa | Mikor használja | Kitöltendő mezők |
| --- | --- | --- |
| Egyszeri | Egy konkrét munkához | **Kezdete**, **Vége** |
| N naponta | Néhány naponta ismétlődő munkához | **Kezdési időpont**, **Időtartam (perc)**, **Ismétlés (naponta)** |
| Hetente | A hét adott napjain ismétlődő munkához | **Kezdési időpont**, **Időtartam (perc)**, **Hétköznapok** |
| Havonta | A hónap adott napjain ismétlődő munkához | **Kezdési időpont**, **Időtartam (perc)**, **A hónap napjai** |
| Cron kifejezés | Bonyolultabb ismétlődéshez | **Cron kifejezés**, **Időtartam (perc)** |

Néhány tudnivaló az időpontokról:

- Minden időpont a kiválasztott **Időzóna** szerint értendő.
- Egyszeri karbantartásnál a **Vége** mezőt üresen hagyhatja, ha még nem tudja, meddig tart a munka. Ilyenkor a **Befejezés most** gombbal zárhatja le.
- Havonta ismétlődő ablaknál a 29–31. nap a rövidebb hónapokban kimarad. Ha a hónap utolsó napjára van szüksége, válassza az **Utolsó nap** lehetőséget.
- A **Cron kifejezés** öt mezőből áll: perc, óra, nap, hónap, hétköznap. A `0 2 * * 0` például minden vasárnap hajnali 2-kor indít ablakot.
- Ismétlődő ütemezésnél az **Érvényes ettől** és az **Érvényes eddig** mezővel korlátozhatja, mely napokon éljen az ütemezés. Egyik mező kitöltése sem kötelező.

## Az ablak előnézete

Egy elmentett karbantartás oldalán az **Ablak** szakasz mutatja, hogy a karbantartás **Éppen folyamatban** van-e, és mikor lesz a **Következő ablak**. Ha nincs több ablak, a **Nincs közelgő ablak.** felirat látható. Az előnézetet a szerver számolja a mentett ütemezésből, ezért módosítás után előbb mentsen.

## A karbantartáslista

A [[route:ws.maintenances|Karbantartás]] képernyőn a felső szűrővel válthat a **Mind**, a **Folyamatban**, a **Következő** és a **Lezárult** karbantartások között. A listában minden karbantartásnál látható az ütemezés típusa, az állapota, a következő vagy aktuális ablak és az érintett monitorok.

Az állapotok jelentése:

| Állapot | Jelentés |
| --- | --- |
| Folyamatban | Az ablak éppen tart, a monitorok nem riasztanak. |
| Következő | Van még hátralévő ablak. |
| Lezárult | Nincs több ablak. |
| Inaktív | Az **Aktív** kapcsoló ki van kapcsolva. |

A sor végén az [[icon:mdi-pencil]] ikonnal szerkesztheti, a [[icon:mdi-trash-can-outline]] ikonnal törölheti a karbantartást.

## Karbantartás befejezése idő előtt

Ha a munka hamarabb elkészült, egy folyamatban lévő egyszeri karbantartást lezárhat:

:::steps

1. Kattintson a listában vagy a karbantartás oldalán az [[icon:mdi-stop-circle-outline]] **Befejezés most** gombra.
2. Erősítse meg a **Befejezés most** gombbal.

:::

A karbantartás azonnal véget ér, és a monitorok újra riasztanak.

:::warning

Az ismétlődő karbantartást nem lehet befejezni. Ha egy ismétlődő ablakot le szeretne állítani, kapcsolja ki az **Aktív** kapcsolót. Ha egy éppen futó karbantartást töröl, a monitorok szintén azonnal újra riasztanak.

:::

## Megjelenés a státuszoldalon

A státuszoldal akkor mutatja a karbantartást, ha kiválasztotta hozzá az oldalt, vagy ha valamelyik érintett monitor (vagy annak csoportja) szerepel az oldalon. A folyamatban lévő karbantartás alatt az érintett monitorok „Karbantartás” állapotúak az oldalon, a közelgő ablakok pedig előre megjelennek. Hogy a látogatók milyen messzire előre lássák a tervezett munkát, azt a státuszoldal **Közelgő karbantartás (nap)** beállítása határozza meg. Részletek: [[manual:monitoring/status-pages|Státuszoldalak]].

:::note

A nyilvános státuszoldalakon a változás akár 60 másodperc késéssel jelenhet meg.

:::
