# Saját fiók

A **Fiók és biztonság** oldalon megtekintheti a profilját, kezelheti a kétlépcsős azonosítást, módosíthatja a jelszavát, és megnézheti, hol van bejelentkezve. A fiókmenüből válthat témát és nyelvet, és itt jelentkezhet ki.

## A fiókmenü

A bal oldali menü alján, a nevére kattintva nyílik a fiókmenü. Itt a következőket találja:

- a nevét és az e-mail-címét;
- [[icon:mdi-shield-account-outline]] **Fiók és biztonság**: a [[route:account|Fiók és biztonság]] oldal;
- téma váltása (lásd lent);
- **Nyelv**: a felület nyelve;
- [[icon:mdi-logout]] **Kijelentkezés**.

A neve alatt az aktuális munkaterületen betöltött szerepköre is látható.

## Profil

A **Profil** részben a **Név** és az **E-mail-cím** látható. Ezeket a felületen nem lehet módosítani. Ha változtatni szeretne rajtuk, forduljon az IntraWatch-példány adminisztrátorához.

## Kétlépcsős azonosítás

Kétlépcsős azonosítás esetén a bejelentkezéshez a jelszó mellett egy hitelesítő alkalmazás hatjegyű, időalapú kódja is kell. Ehhez bármely TOTP-alapú hitelesítő alkalmazás megfelel, amelyet a telefonjára telepít. A **Kétlépcsős azonosítás** cím mellett látja az állapotot: [[icon:mdi-shield-check]] **Bekapcsolva** vagy [[icon:mdi-shield-off-outline]] **Kikapcsolva**.

### Bekapcsolás

:::steps

1. Nyissa meg a [[route:account|Fiók és biztonság]] oldalt.
2. Kattintson a **Bekapcsolás** gombra.
3. Ha az IntraWatch kéri, a **Jelszó megerősítése** ablakban adja meg a jelszavát, majd kattintson a **Megerősítés** gombra.
4. Olvassa be a megjelenő QR-kódot a hitelesítő alkalmazással. Ha nem tudja beolvasni, írja be kézzel a QR-kód alatt látható kulcsot.
5. Írja be az alkalmazásban megjelenő hatjegyű kódot a **Hitelesítő kód** mezőbe.
6. Kattintson a **Megerősítés és bekapcsolás** gombra.

:::

Ha a beállítást a **Mégse** gombbal megszakítja, a félkész beállítás törlődik, és a kétlépcsős azonosítás kikapcsolva marad.

### Helyreállítási kódok

A bekapcsolás után megjelennek a **Helyreállítási kódok**. Mindegyikkel egyszer bejelentkezhet a hitelesítő alkalmazás nélkül, például ha elveszíti a telefonját.

:::warning

A helyreállítási kódokat csak most látja. Mentse el őket biztonságos helyre, mielőtt továbblép.

:::

Az [[icon:mdi-content-copy]] **Másolás** gombbal a vágólapra másolhatja, a [[icon:mdi-download]] **Letöltés** gombbal szövegfájlba mentheti a kódokat. Ha végzett, kattintson az **Elmentettem őket** gombra.

Ha elfogytak vagy elvesztek a kódjai, kattintson az **Új helyreállítási kódok** gombra, és erősítse meg a döntését. A régi kódok ekkor érvénytelenné válnak, az újakat pedig egyszer jeleníti meg az oldal.

### Kikapcsolás

Kattintson a **Kikapcsolás** gombra, és erősítse meg a döntését. Ezután a bejelentkezéshez ismét elég lesz a jelszó. A kétlépcsős azonosítást bármikor újra bekapcsolhatja.

### Bejelentkezés kétlépcsős azonosítással

A jelszó megadása után a **Kétlépcsős azonosítás** oldal következik. Írja be a hitelesítő alkalmazásban látható kódot a **Hitelesítő kód** mezőbe, majd kattintson az **Ellenőrzés** gombra.

Ha nincs kéznél a telefonja, kattintson az **Inkább helyreállítási kódot használok** linkre, és adja meg valamelyik helyreállítási kódját. Minden helyreállítási kód csak egyszer használható.

:::tip

Ha a hitelesítő alkalmazás és a helyreállítási kódok is elvesztek, nem tud bejelentkezni. Ilyenkor a példány adminisztrátora tudja visszaállítani a kétlépcsős azonosítást.

:::

## Jelszó módosítása

A jelszavát a **Jelszó módosítása** részben módosíthatja:

:::steps

1. Nyissa meg a [[route:account|Fiók és biztonság]] oldalt.
2. Adja meg a **Jelenlegi jelszó** értékét.
3. Adja meg az **Új jelszó** és a **Jelszó még egyszer** értékét. Az új jelszónak legalább 10 karakterből kell állnia, és el kell térnie a jelenlegitől.
4. Kattintson a **Jelszó módosítása** gombra.

:::

A módosítás után ezen az eszközön bejelentkezve marad.

:::warning

A jelszó módosítása minden más eszközön kijelentkezteti, és az összes munkaterületen visszavonja minden API-tokenjét. Az ezeket használó szkriptek és integrációk addig nem működnek, amíg új tokent nem hoz létre nekik. Részletek: [[manual:workspace/api-tokens|API-tokenek]].

:::

## Bejelentkezések

A **Bejelentkezések** rész azokat az eszközöket és böngészőket sorolja fel, amelyeken be van jelentkezve. Mindegyiknél látja a böngészőt és az operációs rendszert, az IP-címet, és hogy mikor volt utoljára aktív. Az **Ez az eszköz** jelölés azt a böngészőt mutatja, amelyet most használ.

Ha olyan eszközt lát, amelyet nem ismer fel, vagy egy közös gépen elfelejtett kijelentkezni:

:::steps

1. Kattintson a **Kijelentkezés minden más eszközről** gombra.
2. Adja meg a jelszavát a **Jelszó** mezőben.
3. Kattintson a **Kijelentkezés** gombra.

:::

Ezen az eszközön bejelentkezve marad, minden más eszközön kijelentkezteti a rendszer. Az API-tokenjei továbbra is működnek; ha szükséges, vonja vissza őket az API-tokenek oldalon. Ha úgy gondolja, hogy más is ismeri a jelszavát, inkább módosítsa a jelszavát: ez a többi eszközön is kijelentkezteti.

## Jelszó megerősítése érzékeny műveletekhez

Egyes érzékeny műveletek előtt az IntraWatch újra kéri a jelszavát. Ilyen például a kétlépcsős azonosítás bekapcsolása vagy kikapcsolása. Ekkor a **Jelszó megerősítése** ablak jelenik meg. Adja meg a jelszavát a **Jelszó** mezőben, és kattintson a **Megerősítés** gombra. A művelet ezután folytatódik.

## Elfelejtett jelszó

Ha elfelejtette a jelszavát, újat a bejelentkezési oldalon állíthat be:

:::steps

1. A bejelentkezési oldalon kattintson az **Elfelejtette a jelszavát?** linkre.
2. Adja meg a fiókjához tartozó **E-mail-cím** értékét, és kattintson a **Link küldése** gombra.
3. Nyissa meg az e-mailben kapott linket.
4. Adja meg az **Új jelszó** és a **Jelszó még egyszer** értékét, majd kattintson a **Jelszó mentése** gombra.
5. Jelentkezzen be az új jelszóval.

:::

## Téma és nyelv

A fiókmenüben a [[icon:mdi-weather-night]] **Váltás sötét témára**, illetve a [[icon:mdi-weather-sunny]] **Váltás világos témára** ponttal válthat a sötét és a világos megjelenés között.

A **Nyelv** alatt az **English** és a **Magyar** közül választhat. Az aktív nyelv mellett [[icon:mdi-check]] pipa látható. Ha még nem választott nyelvet, a felület a böngésző nyelvét követi: magyar böngészőnyelvnél magyarul, egyébként angolul jelenik meg.

:::note

A téma és a nyelv ebben a böngészőben tárolódik, nem a fiókjában. Másik böngészőben vagy eszközön újra be kell állítania őket.

:::

A meghívó e-mailek a meghívó felületének nyelvén készülnek. Részletek: [[manual:workspace/workspace|Munkaterület és tagok]].

## Munkamenet és kijelentkezés

Bejelentkezéskor a **Maradjak bejelentkezve** beállítással kérheti, hogy a böngésző hosszabb ideig megjegyezze a bejelentkezést.

Ha munka közben lejár a munkamenet, a **Lejárt a munkamenet** ablak jelenik meg. Jelentkezzen be újra az ablakban. A nem mentett módosításai az oldalon maradnak, és a bejelentkezés után elküldi őket a felület.

A munka végén a fiókmenü [[icon:mdi-logout]] **Kijelentkezés** pontjával jelentkezhet ki.
