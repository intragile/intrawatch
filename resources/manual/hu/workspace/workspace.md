# Munkaterület és tagok

A munkaterület a monitorok, csatornák, státuszoldalak és a hozzájuk tartozó előzmények közös tere. Ez a fejezet bemutatja a szerepköröket, a tagok meghívását és a munkaterület beállításait.

## Mi a munkaterület?

Minden monitor, értesítési csatorna, incidens, karbantartás és státuszoldal pontosan egy munkaterülethez tartozik. A munkaterületek egymástól elkülönülnek: amit az egyikben lát, az a másikban nem jelenik meg.

Ha több munkaterületnek is tagja, a bal oldali menü tetején lévő **Munkaterület** választóval válthat közöttük. Az IntraWatch váltáskor lehetőség szerint ugyanazon a listaoldalon marad.

## Új munkaterület létrehozása

Ha a példány beállításai megengedik, a **Munkaterület** választó mellett megjelenik a [[icon:mdi-plus]] **Munkaterület létrehozása** gomb. Adja meg a **Munkaterület neve** értékét, majd kattintson a **Létrehozás** gombra. Az új munkaterületnek Ön lesz a tulajdonosa.

Ha a gomb nem jelenik meg, a példányon Ön nem hozhat létre munkaterületet. Ilyenkor kérje meg egy meglévő munkaterület tulajdonosát vagy adminisztrátorát, hogy hívja meg.

## Szerepkörök

A munkaterületen belül minden tagnak egy szerepköre van. A szerepkörök egymásra épülnek: a magasabb szerepkör mindent megenged, amit az alacsonyabb.

| Művelet | Megtekintő | Szerkesztő | Adminisztrátor | Tulajdonos |
| --- | --- | --- | --- | --- |
| Monitorok, csatornák, riasztási napló, incidensek, karbantartások és státuszoldalak megtekintése | igen | igen | igen | igen |
| Tagok listájának megtekintése | igen | igen | igen | igen |
| Saját API-tokenek létrehozása | igen | igen | igen | igen |
| Monitorok, csatornák, incidensek, karbantartások és státuszoldalak létrehozása és módosítása | nem | igen | igen | igen |
| Tagok meghívása, szerepkörük módosítása, eltávolításuk | nem | nem | igen | igen |
| Eseménynapló megtekintése | nem | nem | igen | igen |
| Munkaterület nevének és időzónájának módosítása | nem | nem | igen | igen |
| Importálás Uptime Kumából | nem | nem | igen | igen |
| Tulajdonosi szerepkör kiosztása, tulajdonos módosítása vagy eltávolítása | nem | nem | nem | igen |
| Munkaterület törlése | nem | nem | nem | igen |

A saját szerepkörét a menü alján, a neve alatt látja.

:::note

Az API-tokenek jogosultságai sem léphetik túl a létrehozójuk szerepkörét. Részletek: [[manual:workspace/api-tokens|API-tokenek]].

:::

## Tag meghívása

Tagot **Adminisztrátor** vagy **Tulajdonos** szerepkörrel hívhat meg.

:::steps

1. Nyissa meg a [[route:ws.members|Tagok]] oldalt.
2. A **Tag meghívása** részben adja meg az **E-mail-cím** értékét.
3. Válassza ki a **Szerepkör** értékét. Alapértelmezés szerint ez a **Megtekintő**.
4. Kattintson a **Meghívó küldése** gombra.

:::

Az IntraWatch meghívó linket küld a megadott címre. A link alapértelmezés szerint 7 napig érvényes; a pontos lejáratot a **Függő meghívók** táblázat mutatja. A meghívott személy úgy csatlakozik, hogy ezzel az e-mail-címmel bejelentkezik, majd a linket megnyitva a **Meghívó elfogadása** gombra kattint. A meghívó e-mail a meghívó felületének nyelvén készül.

:::warning

A meghívó nem hoz létre fiókot. Ha a meghívott személynek még nincs fiókja az IntraWatch-példányon, kérje meg a példány adminisztrátorát, hogy hozzon létre egyet.

:::

Ha ugyanazt a címet újra meghívja, az új meghívó lecseréli a korábbit, és a régi link érvénytelenné válik. Már tag címre nem küldhető meghívó.

## Függő meghívók

A **Függő meghívók** táblázat a még el nem fogadott meghívókat mutatja. A **Lejárat** oszlopban látja, meddig érvényes a link. A lejárt meghívók mellett a **Lejárt** jelölés áll.

Egy meghívót az [[icon:mdi-email-remove]] **Meghívó visszavonása** ikonnal vonhat vissza. A link ezzel azonnal érvénytelenné válik. Tulajdonosi szerepkörre szóló meghívót csak tulajdonos vonhat vissza.

## Szerepkör módosítása és tag eltávolítása

Adminisztrátorként és tulajdonosként a tagok táblázatában minden sorban szerepkör-választó jelenik meg. Az új szerepkör a kiválasztás után azonnal érvénybe lép.

Tagot az [[icon:mdi-account-minus]] **Tag eltávolítása** ikonnal távolíthat el. A megerősítés után az illető elveszíti a hozzáférését a munkaterülethez.

Néhány szabály védi a tulajdonosokat:

- Tulajdonosi szerepkört csak tulajdonos adhat, akár meghívóval, akár szerepkör-módosítással.
- Meglévő tulajdonost csak tulajdonos módosíthat vagy távolíthat el.
- A munkaterületnek mindig legalább egy tulajdonossal kell rendelkeznie. Az utolsó tulajdonos nem távolítható el, és a szerepköre sem csökkenthető.

## A munkaterület beállításai

A [[route:ws.settings|Munkaterület beállításai]] oldalon a következő mezőket találja:

- **Név**: a munkaterület megjelenő neve.
- **Azonosító (nem módosítható)**: a munkaterület címekben használt azonosítója, például az API-hívásokban.
- **Időzóna**: ebben jelennek meg a dátumok, a karbantartási időablakok és a jelentések.

A módosításokat a **Mentés** gombbal rögzítheti. Ehhez legalább **Adminisztrátor** szerepkör kell. Alacsonyabb szerepkörrel a beállításokat csak megtekintheti.

## A munkaterület törlése

A **Munkaterület törlése** rész csak a tulajdonosoknak jelenik meg.

:::warning

A munkaterület törlésével a monitorok, csatornák, státuszoldalak és az előzmények véglegesen elvesznek. A törlés nem vonható vissza.

:::

A megerősítő ablakban írja be a munkaterület nevét a **Munkaterület neve** mezőbe, majd kattintson a **Törlés** gombra.
