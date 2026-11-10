# Incidensek

Az incidenssel a látogatókat tájékoztathatja egy hibáról vagy kiesésről: leírhatja, mi történt, és frissítésekkel követheti a hibaelhárítást. Ez a fejezet bemutatja, hogyan hozhat létre, frissíthet és zárhat le egy incidenst, és hogyan jelenik meg a státuszoldalakon.

## Miben más az incidens, mint a riasztás?

A riasztást a monitor küldi automatikusan, a csapatának. Az incidenst Ön hozza létre kézzel, és elsősorban a státuszoldal látogatóinak szól. Az IntraWatch nem nyit magától incidenst egy leállt monitor miatt. Önnek kell eldöntenie, mikor és mit közöl.

Az incidenseket mindenki megtekintheti a munkaterületen. Létrehozásukhoz és módosításukhoz legalább **Szerkesztő** szerepkör kell.

## Az incidens állapotai és hatása

Az incidensnek van **Állapot** és **Hatás** mezője.

| Állapot | Mikor használja |
| --- | --- |
| Vizsgálat alatt | Tud a hibáról, és keresi az okát. |
| Azonosítva | Megtalálta az okot, és dolgozik a javításon. |
| Megfigyelés alatt | A javítást élesítette, és figyeli, hogy tartós-e. |
| Megoldva | A hiba elhárult. Ez az állapot lezárja az incidenst. |

A **Hatás** azt mutatja, mennyire súlyos a hiba: **Nincs hatás**, **Kisebb**, **Jelentős** vagy **Kritikus**. A nyitott incidens hatása a státuszoldalon is látszik:

| Hatás | Az érintett elemek állapota a státuszoldalon |
| --- | --- |
| Nincs hatás | Nem változtat az állapoton. |
| Kisebb | Legalább „Csökkent teljesítmény” |
| Jelentős | Legalább „Részleges kiesés” |
| Kritikus | „Súlyos kiesés” |

A státuszoldal mindig a súlyosabb állapotot mutatja: ha a monitor maga rosszabb állapotban van, mint amit az incidens hatása jelez, a monitor állapota látszik.

## Új incidens létrehozása

:::steps

1. Nyissa meg az [[route:ws.incidents|Incidensek]] képernyőt, és kattintson az [[icon:mdi-plus]] **Új incidens** gombra.
2. Adja meg a **Cím** mezőt, és válassza ki a **Hatás** értékét.
3. Az **Első frissítés** alatt válassza ki az **Állapot** értékét, és írja meg az **Üzenet** szövegét. Ez lesz az idővonal első bejegyzése.
4. Ha az incidens csak a csapatának szól, kapcsolja be a **Csak belső** kapcsolót. A belső incidens egyetlen státuszoldalon sem jelenik meg, akkor sem, ha olyan monitort érint, amely nyilvános státuszoldalon is szerepel.
5. Az **Érintettek** alatt válassza ki az **Érintett monitorok** közül az érintett monitorokat. Ha az incidens nem belső, a **Megjelenik a státuszoldalakon** mezőben válassza ki azokat a további státuszoldalakat, ahol az incidensnek akkor is látszania kell, ha az érintett monitorok nincsenek rajta. Amíg a **Csak belső** kapcsoló be van kapcsolva, ez a mező nem látható.
6. Kattintson az **Incidens létrehozása** gombra.

:::

:::warning

A státuszoldal az összes frissítésével együtt mutatja az incidenst, ha a **Megjelenik a státuszoldalakon** mezőben kiválasztotta az oldalt, vagy ha az **Érintett monitorok** között olyan monitor szerepel, amely az oldalon látható. Ha egy incidenst csak a munkaterületen belül szeretne nyilvántartani, kapcsolja be a **Csak belső** kapcsolót: a belső incidens egyetlen státuszoldalon sem jelenik meg, és a státuszoldalak RSS- és Atom-hírcsatornáiban, JSON-összesítőiben és jelvényein sem látszik; az érintett monitoroknak az oldalakon mutatott állapotát sem változtatja meg. Az incidenslistában és az incidens oldalán a belső incidenst **Belső** jelölés mutatja.

:::

## Frissítés közzététele

A hibaelhárítás közben frissítésekkel tájékoztathatja a látogatókat. Nyissa meg az incidenst a listából, és használja a **Frissítés közzététele** kártyát:

:::steps

1. Válassza ki az új **Állapot** értékét.
2. Írja meg az **Üzenet** szövegét, például hogy mit talált, vagy mikorra várható a javítás.
3. Kattintson a **Frissítés küldése** gombra.

:::

![Egy incidens idővonala két frissítéssel (Vizsgálat alatt, Azonosítva), alatta a Frissítés közzététele kártya az Állapot és az Üzenet mezővel és a Frissítés küldése gombbal](/admin/manual/hu/incident-update.webp)

Az incidens mindig a legújabb frissítés állapotát veszi fel. Az incidenst tehát úgy zárhatja le, hogy egy **Megoldva** állapotú frissítést tesz közzé.

:::note

A nyilvános státuszoldalakon a változás akár 60 másodperc késéssel jelenhet meg.

:::

## Az idővonal javítása

Az **Idővonal** kártyán minden frissítés látható az időpontjával. Egy bejegyzés mellett:

- az [[icon:mdi-pencil]] ikonnal kijavíthatja az üzenet szövegét. Az állapot és az időpont ilyenkor nem változik, a bejegyzés mellett pedig megjelenik a „szerkesztve” jelölés;
- a [[icon:mdi-trash-can-outline]] ikonnal törölheti a bejegyzést. Ilyenkor az incidens állapota a legújabb megmaradó frissítésből számolódik újra.

## A részletek módosítása

A **Részletek** kártyán utólag is módosíthatja a **Cím** és a **Hatás** mezőt, a **Csak belső** kapcsolót, valamint az érintett monitorokat és státuszoldalakat. A változásokat a **Részletek mentése** gombbal mentse. Ha egy státuszoldalakon megjelenő incidensnél bekapcsolja a **Csak belső** kapcsolót, a mentés a **Megjelenik a státuszoldalakon** mezőben kijelölt oldalakról is eltávolítja.

## Az incidenslista

Az [[route:ws.incidents|Incidensek]] képernyőn a felső szűrővel válthat a **Mind**, a **Nyitott** és a **Megoldott** incidensek között. A listában minden incidensnél látható a hatás, az állapot, a kezdet és a megoldás időpontja.

## Incidens törlése

Az incidens oldalán a [[icon:mdi-trash-can-outline]] **Törlés** gombbal törölheti az egész incidenst. Az incidens és az idővonala ilyenkor a státuszoldalakról is eltűnik.

:::tip

Egy lezárt incidenst általában nem érdemes törölni: a státuszoldal a **Korábbi incidensek** között mutatja, ami a látogatók szemében az átláthatóság jele. Hogy ezek milyen régre visszamenőleg látszanak, a [[manual:monitoring/status-pages|Státuszoldalak]] fejezetben olvashat.

:::

Ha egy tervezett munkáról szeretné előre tájékoztatni a látogatókat, incidens helyett karbantartást ütemezzen. Erről a [[manual:monitoring/maintenances|Karbantartások]] fejezet szól.
