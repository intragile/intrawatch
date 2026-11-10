# API-tokenek

Az API-tokennel egy szkript vagy integráció az Ön nevében hívhatja az IntraWatch API-ját, egyetlen munkaterületre és a kiválasztott jogosultságokra korlátozva. Ez a fejezet bemutatja a tokenek létrehozását, használatát és visszavonását.

## Mire jó a token?

A token akkor hasznos, ha egy program bejelentkezés nélkül szeretné elérni az IntraWatchot. Ilyen lehet például egy telepítő szkript, amely monitort hoz létre, vagy egy belső irányítópult, amely a monitorok állapotát olvassa.

Minden token:

- az Ön nevében működik, és egyetlen munkaterülethez tartozik, ahhoz, amelyben létrehozta;
- csak a létrehozáskor kiválasztott jogosultságokat kapja meg;
- a létrehozójánál nagyobb jogot soha nem adhat.

Az [[route:ws.tokens|API-tokenek]] oldal csak a saját tokenjeit mutatja az aktuális munkaterületen. A többi tag tokenjeit nem látja és nem kezelheti.

## Token létrehozása

:::steps

1. Nyissa meg az [[route:ws.tokens|API-tokenek]] oldalt.
2. A **Token létrehozása** részben adjon meg egy beszédes **Név** értéket, például azt, hogy melyik szkript vagy rendszer használja.
3. Ha a tokent csak egy ideig szeretné használni, adja meg a **Lejárat (nem kötelező, helyi idő szerint)** értékét. A lejárat nem lehet múltbeli időpont.
4. A **Jogosultságok** között jelölje be, amire a tokennek szüksége van. Legalább egyet ki kell választania.
5. Kattintson a **Token létrehozása** gombra.
6. **Az új token** ablakban másolja ki a tokent az [[icon:mdi-content-copy]] ikonnal.
7. Kattintson a **Kimásoltam** gombra.

:::

:::warning

A token csak egyszer, a létrehozás után jelenik meg. Később nem kérhető le újra. Ha elveszíti, vonja vissza, és hozzon létre újat.

:::

Kezelje a tokent jelszóként. Ne tegye verziókezelt fájlba, és ne küldje el nyílt csatornán.

## Jogosultságok

A jogosultságok azt határozzák meg, mit tehet a token. Mindegyikhez tartozik egy legalacsonyabb szerepkör. Csak olyan jogosultságot adhat, amelyet a saját szerepköre is megenged. Ha ennél többet jelöl be, a létrehozás hibával leáll.

| Jogosultság a felületen | Technikai név | Legalább |
| --- | --- | --- |
| Munkaterület olvasása | `workspace:read` | Megtekintő |
| Monitorok olvasása | `monitors:read` | Megtekintő |
| Értesítési csatornák olvasása | `notifications:read` | Megtekintő |
| Incidensek és karbantartások olvasása | `incidents:read` | Megtekintő |
| Státuszoldalak olvasása | `status-pages:read` | Megtekintő |
| Monitorok módosítása | `monitors:write` | Szerkesztő |
| Értesítési csatornák módosítása | `notifications:write` | Szerkesztő |
| Incidensek és karbantartások módosítása | `incidents:write` | Szerkesztő |
| Státuszoldalak módosítása | `status-pages:write` | Szerkesztő |
| Munkaterület kezelése | `workspace:manage` | Adminisztrátor |
| Tagok kezelése | `members:manage` | Adminisztrátor |
| Eseménynapló olvasása | `audit:read` | Adminisztrátor |

A tokenek listájában a **Jogosultságok** oszlop a technikai neveket mutatja.

### Csak annyi jogot adjon, amennyi kell

Minden tokennek csak azt a jogosultságot adja meg, amelyre a feladatához valóban szüksége van. Egy állapotot megjelenítő irányítópultnak elég a **Monitorok olvasása**. Egy monitorokat létrehozó szkriptnek kell a **Monitorok módosítása** is, de a tagok kezelése nem. Ha egy token kiszivárog, így a kár is kisebb.

Érdemes integrációnként külön tokent létrehozni. Így egyenként vonhatja vissza őket, és az eseménynaplóban is látszik, melyik végezte az adott műveletet.

:::note

A token jogai a létrehozója mindenkori szerepkörét követik. Ha az Ön szerepkörét később csökkentik, a token azon jogosultságai, amelyeket az új szerepkör már nem enged meg, azonnal nem működnek tovább. Ha Önt eltávolítják a munkaterületből, a tokenjei sem érik el többé.

:::

## A token használata

A tokent minden kérésben az `Authorization` fejlécben küldje, `Bearer` előtaggal. Az API címe a példány címe után a `/api/v1/` útvonal. A munkaterületet az azonosítója jelöli, amelyet a [[route:ws.settings|Munkaterület beállításai]] oldalon talál.

Az alábbi példa egy **Monitorok olvasása** jogosultságú tokennel lekéri a munkaterület monitorait:

```bash
curl -H "Authorization: Bearer $INTRAWATCH_TOKEN" \
     -H "Accept: application/json" \
     https://intrawatch.example.com/api/v1/workspaces/MUNKATERULET-AZONOSITO/monitors
```

Ha a tokenből hiányzik a szükséges jogosultság, vagy a szerepköre már nem engedi meg, az API `403` válasszal utasítja el a kérést.

A token nem használható újabb tokenek létrehozására vagy visszavonására, és meghívót sem fogadhat el. Ezekhez jelentkezzen be a felületre.

## Az API leírása

Az API teljes, géppel olvasható leírása OpenAPI-dokumentumként érhető el a példány `/api/v1/openapi.json` címén. Ehhez nem kell token. A dokumentumot betöltheti egy OpenAPI-nézegetőbe, vagy kliensprogramot generálhat belőle.

## Utolsó használat és lejárat

A tokenek listájában az **Utoljára használva** oszlop mutatja, mikor használták utoljára a tokent. A még nem használt tokeneknél a **Még nem használták** felirat áll. A **Lejárat** oszlopban a lejárat időpontja vagy a **Nem jár le** felirat látható. A lejárt tokenek mellett az [[icon:mdi-clock-alert-outline]] **Lejárt** jelölés áll.

## Token visszavonása

Kattintson a token sorában a [[icon:mdi-key-remove]] **Visszavonás** ikonra, majd erősítse meg a **Visszavonás** gombbal. Minden, ami a tokent használja, azonnal leáll. A visszavonás nem vonható vissza.

A tokenek létrehozása és visszavonása bekerül az [[manual:workspace/audit|Eseménynapló]] bejegyzései közé.
