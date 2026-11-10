# Státuszoldalak

A státuszoldal egy nyilvános weboldal, amelyen ügyfelei és kollégái bejelentkezés nélkül láthatják a kiválasztott monitorok állapotát, a nyitott incidenseket és a tervezett karbantartásokat. Ez a fejezet bemutatja, hogyan hozhat létre, rendezhet be és tehet közzé egy státuszoldalt.

## Közzétett és piszkozat oldalak

Minden státuszoldal vagy **Közzétéve**, vagy **Piszkozat** állapotú. A nem közzétett oldalt a látogatók nem érik el: a nyilvános címe úgy viselkedik, mintha az oldal nem is létezne. Így nyugodtan összeállíthatja és átnézheti az oldalt, mielőtt bárki láthatná.

Amikor elkészült, kapcsolja be a **Közzétéve** kapcsolót, és mentse az oldalt. A közzétett oldal a `/status/` útvonal alatt, az **Azonosító (slug)** mezőben megadott címen érhető el.

A státuszoldalakat mindenki megtekintheti a munkaterületen. Létrehozásukhoz és módosításukhoz legalább **Szerkesztő** szerepkör kell.

## Új státuszoldal létrehozása

:::steps

1. Nyissa meg a [[route:ws.status-pages|Státuszoldalak]] képernyőt, és kattintson az [[icon:mdi-plus]] **Új státuszoldal** gombra.
2. Adja meg a **Cím** és az **Azonosító (slug)** mezőt. Az azonosítóból lesz a nyilvános cím, például `/status/szolgaltatasaink`.
3. Ha szükséges, írjon **Leírás** szöveget, és válassza ki a **Nyelv** mezőben, hogy az oldal angol vagy magyar legyen.
4. Hagyja kikapcsolva a **Közzétéve** kapcsolót, amíg be nem rendezte az oldalt.
5. Kattintson a **Létrehozás** gombra.

:::

Az oldal létrehozása után megjelenik az **Elrendezés** kártya, ahol hozzáadhatja a monitorokat.

## Az elrendezés összeállítása

A nyilvános oldalon a monitorok szekciókba rendezve jelennek meg, például „Weboldalak” vagy „Belső rendszerek”.

![Az Elrendezés kártya két szekcióval: a Weboldalak szekcióban a Webshop, az API és a Számlázás, a Belső rendszerek szekcióban a Levelezőszerver és az Adatbázis monitor, alul a Szekció hozzáadása és az Elrendezés mentése gomb](/admin/manual/hu/status-page-layout.webp)

:::steps

1. Az **Elrendezés** kártyán kattintson az [[icon:mdi-plus]] **Szekció hozzáadása** gombra.
2. Adja meg a **Szekció neve** és, ha szükséges, a **Szekció leírása** mezőt.
3. A **Monitor hozzáadása (keresés név szerint)** mezőben keresse meg és válassza ki a szekcióba kerülő monitorokat.
4. Az [[icon:mdi-arrow-up]] és az [[icon:mdi-arrow-down]] ikonnal, vagy az [[icon:mdi-drag-vertical]] fogantyúval húzva állítsa be a sorrendet.
5. Kattintson az **Elrendezés mentése** gombra.

:::

Minden monitornál két kapcsoló van. A **Rendelkezésre állás** kapcsolóval a rendelkezésre állási százalékot is megjelenítheti a monitor mellett. Csoportmonitornál a **Csoport tagjai** kapcsolóval a csoport tagjait is megmutathatja. Egy monitort az [[icon:mdi-close]] ikonnal vehet ki a szekcióból.

:::warning

Az elrendezést külön kell menteni az **Elrendezés mentése** gombbal, a **Mentés** gomb csak a beállításokat menti. Az elrendezés mentése az egész elrendezést felülírja.

:::

## Mit látnak a látogatók?

A nyilvános oldal tetején egy összesítő sáv mutatja az egész oldal állapotát, például „Minden rendszer működik” vagy „Részleges kiesés”. Alatta a szekciók monitorai láthatók a saját állapotukkal és egy napokra bontott állapotsávval.

A monitorállapotok így jelennek meg az oldalon:

| A monitor állapota az alkalmazásban | Az oldalon |
| --- | --- |
| Működik | Működik |
| Akadozik | Csökkent teljesítmény |
| Leállt | Súlyos kiesés (csoportnál Részleges kiesés, ha van még működő tag) |
| Karbantartás | Karbantartás |
| Függőben, Szünetel | Nincs adat |

Az oldal emellett mutatja a kiválasztott nyitott incidenseket, a lezárt incidenseket és a közelgő karbantartásokat. Egy incidens vagy karbantartás akkor jelenik meg, ha kiválasztotta hozzá ezt a státuszoldalt, vagy ha az oldalon látható valamelyik monitort érinti (karbantartásnál a monitor csoportját is beleértve). Lásd: [[manual:monitoring/incidents|Incidensek]] és [[manual:monitoring/maintenances|Karbantartások]].

## Megjelenítési beállítások

Az **Amit a látogatók látnak** szakaszban szabályozhatja, mennyi előzmény látsszon:

- **Állapotsáv időtartama (nap)**: hány napot mutasson a monitorok állapotsávja (7 és 365 között).
- **Rendelkezésre állás ennyi időre**: a rendelkezésre állási százalék időablaka, 1, 7, 30, 90 vagy 365 nap.
- **Incidenselőzmények (nap)**: milyen régre visszamenőleg látszanak a lezárt incidensek (0–90).
- **Közelgő karbantartás (nap)**: milyen messzire előre látszik a tervezett karbantartás (0–90).
- **Válaszidők mutatása**: bekapcsolva a válaszidőket is megjeleníti.

## Megjelenés

A **Megjelenés** szakaszban az oldalt a saját arculatához igazíthatja:

- **Kiemelőszín**: hexa érték, például #2563eb.
- **Logó URL-je** és **Favicon URL-je**: https cím, hitelesítő adatok nélkül.
- **Lábléc szövege**: az oldal alján megjelenő szöveg.
- **Egyedi CSS**: saját stílusszabályok a további testreszabáshoz.

## Egyedi domain

Ha a telepítés engedélyezi, az **Egyedi domain** mezőben saját címet adhat az oldalnak, például `status.example.com`. Ehhez egy DNS-rekordnak kell erre a szerverre mutatnia. Az egyedi domaint csak **Adminisztrátor** vagy **Tulajdonos** szerepkörrel állíthatja be. Ha a mező nem jelenik meg, a telepítésen nem engedélyezett ez a funkció.

## A státuszoldal-lista

A [[route:ws.status-pages|Státuszoldalak]] listájában minden oldalnál látható a címe és az állapota (**Közzétéve** vagy **Piszkozat**). A sor végén az [[icon:mdi-open-in-new]] ikonnal megnyithatja a nyilvános oldalt, az [[icon:mdi-pencil]] ikonnal szerkesztheti, a [[icon:mdi-trash-can-outline]] ikonnal pedig törölheti. Törléskor az oldal elrendezése is törlődik, és a nyilvános címe megszűnik.

:::note

A nyilvános státuszoldalakon a változás akár 60 másodperc késéssel jelenhet meg.

:::
