# Monitorok

A monitor rendszeresen ellenőriz egy weboldalt, szervert, tanúsítványt vagy ütemezett feladatot, és riaszt, ha valami elromlik. Ez a fejezet bemutatja, hogyan hozhat létre monitort, és mit jelentenek az állapotai.

## A monitorlista

A [[route:ws.monitors|Monitorok]] képernyő bal oldalán az összes monitor látható az állapotával, a válaszidővel és a legutóbbi ellenőrzések sávjával. Ha egy monitorra kattint, a részletei a lista mellett nyílnak meg. A lista fölött az állapotok szerinti számlálók szűrőként is működnek, a **Szűrők** között pedig típusra, címkére, aktivitásra és névre is szűrhet.

A monitorok létrehozásához és módosításához legalább **Szerkesztő** szerepkör kell. **Megtekintő** szerepkörrel mindent láthat, de semmit sem módosíthat.

## Mit jelentenek az állapotok?

| Állapot | Jelentés |
| --- | --- |
| Működik | Az utolsó ellenőrzés sikeres volt. |
| Akadozik | Az ellenőrzés sikerült, de túllépte a beállított lassúsági küszöböt, vagy egy tanúsítvány vagy domain lejáratáig kevesebb nap van hátra, mint a **Lassú, ha ennél kevesebb nap van hátra** mezőben megadott érték. |
| Függőben | Az új vagy újraindított monitor még nem ellenőrzött, vagy az ellenőrzés hibát jelzett, de az újrapróbálkozások még nem fogytak el. |
| Leállt | Az ellenőrzés az újrapróbálkozások után is sikertelen. |
| Karbantartás | A monitor egy karbantartási ablakban van, ezért nem riaszt. |
| Szünetel | A monitort kikapcsolták, az alkalmazás nem ellenőrzi, és nem riaszt. |

A **Függőben** állapot szándékos átmenet: egyetlen hibás ellenőrzés még nem jelent leállást. Leállásról szóló riasztás csak a **Leállt** állapotba lépéskor indul.

## Új monitor létrehozása

:::steps

1. A monitorlista fölött kattintson az [[icon:mdi-plus]] **Új monitor** gombra, vagy nyissa meg közvetlenül az [[route:ws.monitor.new|Új monitor]] képernyőt.
2. Válassza ki a **Monitor típusa** mezőben a típust. A típus később nem módosítható.
3. Adja meg a **Név** mezőt, és ha szükséges, a **Leírás** és a **Szülőcsoport** mezőt.
4. Töltse ki a típushoz tartozó beállításokat (lásd lent).
5. Állítsa be az **Időzítés**, a **Riasztás** és az **Értesítések és címkék** szakaszt.
6. Kattintson a **Létrehozás** gombra.

:::

Az **Értesítési csatornák** mezőben előre ki vannak jelölve azok a csatornák, amelyeket alapértelmezettként jelöltek meg. A csatornák létrehozásáról az [[manual:monitoring/notifications|Értesítési csatornák]] fejezet szól. A **Címkék** mezőben a munkaterület meglévő címkéi közül választhat.

## Monitortípusok

| Típus | Mit ellenőriz | Fontosabb mezők |
| --- | --- | --- |
| HTTP | Weboldal vagy API válaszát | **URL**, **Metódus**, **Elfogadott állapotkódok**, **Hitelesítés**, **Válasz ellenőrzése**, **Tanúsítványok**, **Proxy** |
| TCP | Nyitott-e egy port | **Állomás**, **Port** |
| Ping | Válaszol-e egy gép pingre | **Állomás**, **Csomagok száma ellenőrzésenként** |
| DNS | Egy név feloldását | **Feloldandó név**, **Rekordtípus**, **Névszerver**, **Várt értékek**, **Egyezés** |
| TLS-tanúsítvány | Egy kiszolgáló tanúsítványát | **Állomás**, **Port**, **Protokollváltás**, **Figyelmeztetés lejárat előtt (nap)** |
| Domain lejárata | Egy domain regisztrációjának lejáratát | **Domainnév**, **Figyelmeztetés lejárat előtt (nap)** |
| Push | Hogy egy szolgáltatás rendszeresen jelentkezik-e | **Türelmi idő** |
| Cron | Hogy egy ütemezett feladat időben lefut-e | **Cron-ütemezés**, **Az ütemezés időzónája**, **Türelmi idő**, **Legnagyobb futásidő** |
| Csoport | Több monitor együttes állapotát | **A csoport állapotának szabálya** |

A HTTP-monitornál a jelszavakat és tokeneket a **Hitelesítés** vagy a **Titkos fejlécek** mezőben adja meg, ne a sima fejlécek között. A titkos értékeket mentés után többé nem jeleníti meg az alkalmazás.

### Push és Cron monitorok

Ezek a típusok nem maguk kérdeznek le semmit: az Ön feladatának kell jelentkeznie egy egyedi címen. A **Push URL** mentés után a monitor részletező oldalán, a **Push végpont** kártyán jelenik meg, ahol az [[icon:mdi-content-copy]] ikonnal másolhatja. Kezelje az URL-t jelszóként. Ha kiszivárgott, a [[icon:mdi-key-change]] **Token cseréje** gombbal új címet kérhet; a régi azonnal érvénytelenné válik.

### Csoportok

A csoport állapota a tagjaiból adódik. Egy monitort úgy tehet csoportba, hogy a monitor **Szülőcsoport** mezőjében kiválasztja a csoportot. A szabály lehet **Minden tag működik**, **Legalább egy tag működik** vagy **Legalább N tag működik**. Ha egy csoport leállt, és van aktív értesítési csatornája, a tagjai riasztásait az alkalmazás elnyomja, hogy ne kapjon egyszerre sok üzenetet.

## Ellenőrzési időköz, időtúllépés és újrapróbálkozás

Az **Időzítés** szakasz teljes egészében a rendszeresen lekérdezett típusoknál jelenik meg. Push monitornál csak a **Várt jelentkezési időköz** és az **Újrapróbálkozási időköz** látszik: a feladatnak ennyi időnként kell jelentkeznie, és a monitor akkor számít leálltnak, ha ehhez a türelmi időt is hozzáadva sem jelentkezett. Minden további elmaradt jelentkezést az újrapróbálkozási időköz után számol az alkalmazás.

- **Ellenőrzési időköz**: milyen gyakran ellenőrzi az alkalmazás a célt, másodpercben.
- **Időtúllépés**: meddig vár az alkalmazás egy ellenőrzés válaszára. Legfeljebb az ellenőrzési időköz 80%-a lehet.
- **Újrapróbálkozások**: hányszor ismétli meg az alkalmazás a sikertelen ellenőrzést, mielőtt a monitort leálltnak jelöli (0–10). Közben a monitor **Függőben** állapotú.
- **Újrapróbálkozási időköz**: milyen gyorsan ismétli meg az alkalmazás az ellenőrzést hiba után, legalább 20 másodperc.
- **Lassú, ha a válaszidő több mint**: e fölött a monitor **Akadozik** állapotba kerül. Üresen hagyva ki van kapcsolva.

A **Fordított eredmény** kapcsolóval megfordíthatja az eredményt: ilyenkor a sikertelen ellenőrzés számít működőnek. Ez például akkor hasznos, ha azt figyeli, hogy egy szolgáltatás kívülről ne legyen elérhető.

## Riasztási beállítások

- **Emlékeztető időköze (perc)**: amíg a monitor leállt, ennyi percenként ismétli a riasztást. A 0 nem küld emlékeztetőt.
- **Riasztás lassú működésnél**: bekapcsolva az **Akadozik** állapot is riaszt, a **Lassú működés riasztási késleltetése** után.
- **Értesítés a tanúsítvány, a DNS és a lejárat változásairól**: ez a kapcsoló a tanúsítvány- és DNS-változásokról, valamint a közeledő lejáratokról szóló riasztásokat kapcsolja be. Új monitornál alapból be van kapcsolva; TLS-, domain- és DNS-monitornál érdemes így hagyni.

Ha egy monitor rövid időn belül sokszor vált állapotot, az alkalmazás nem küld minden váltásról külön üzenetet. Ehelyett egy **Ingadozik** riasztást küld, majd egy **Stabil** riasztást, amikor a monitor megnyugodott.

## Műveletek a részletező oldalon

A részletező oldalon látható a **Válaszidő** diagram, a **Rendelkezésre állás**, valamint az **Eredmények**, az **Események** és a **Riasztások** lap. Szerkesztőként az alábbi gombokat is eléri:

- [[icon:mdi-refresh]] **Ellenőrzés most**: soron kívüli ellenőrzést kér.
- [[icon:mdi-pause]] **Szüneteltetés** és [[icon:mdi-play]] **Folytatás**: kikapcsolja, illetve visszakapcsolja a monitort.
- [[icon:mdi-pencil]] **Szerkesztés**: megnyitja a beállításokat.

:::tip

Egyszerre több monitort is módosíthat. Kattintson a listában az [[icon:mdi-checkbox-multiple-marked-outline]] **Kijelölés** gombra, jelölje ki a monitorokat, majd válasszon a **Tömeges műveletek** közül: szüneteltetés, folytatás, törlés, címke vagy értesítési csatorna hozzáadása és eltávolítása.

:::
