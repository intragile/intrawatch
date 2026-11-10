# Első lépések

Ez a fejezet végigvezeti a bejelentkezés utáni első lépéseken: létrehoz egy monitort, beállít egy értesítési csatornát, közzétesz egy státuszoldalt, és készít egy API-tokent.

## Tájékozódás a felületen

A bal oldali menü tetején a **Munkaterület** választó látható. Minden, amit az alábbiakban létrehoz, az éppen kiválasztott munkaterülethez tartozik. A legfontosabb menüpontok:

- [[icon:mdi-monitor-dashboard]] **Monitorok**
- [[icon:mdi-bell-outline]] **Értesítési csatornák**
- [[icon:mdi-web-check]] **Státuszoldalak**
- a **Munkaterület kezelése** csoportban többek között [[icon:mdi-account-group]] **Tagok** és [[icon:mdi-key-variant]] **API-tokenek**
- a **Súgó és újdonságok** csoportban a [[icon:mdi-help-circle-outline]] **Súgó**, vagyis ez a kézikönyv, és a [[icon:mdi-history]] **Fejlesztési napló**, az egyes kiadások változásai

A menü alján, a nevére kattintva nyílik a **Fiókmenü**. Itt találja a **Fiók és biztonság** oldalt, a téma- és nyelvváltást, valamint a **Kijelentkezés** lehetőséget.

A menüben csak azok a pontok jelennek meg, amelyekhez a szerepköre hozzáférést ad. Monitort, csatornát és státuszoldalt legalább **Szerkesztő** szerepkörrel hozhat létre. Ha valamelyik gombot nem látja, kérjen magasabb szerepkört a munkaterület egyik adminisztrátorától. A szerepkörökről a [[manual:workspace/workspace|Munkaterület és tagok]] fejezet szól.

## 1. Az első monitor

A monitor rendszeresen ellenőriz egy szolgáltatást, például egy weboldalt.

:::steps

1. Nyissa meg a [[route:ws.monitors|Monitorok]] oldalt.
2. Kattintson az [[icon:mdi-plus]] **Új monitor** gombra.
3. A **Monitor típusa** mezőben hagyja a **HTTP** típust, vagy válasszon másikat. A típus a létrehozás után már nem módosítható.
4. Adjon meg egy **Név** értéket, és írja be az ellenőrizendő címet az **URL** mezőbe.
5. Szükség esetén módosítsa az **Ellenőrzési időköz** értékét.
6. Kattintson a **Létrehozás** gombra.

:::

Az ellenőrzések eredménye a monitor részletező oldalán jelenik meg. A további típusokat és beállításokat a [[manual:monitoring/monitors|Monitorok]] fejezet ismerteti.

## 2. Értesítési csatorna

Az értesítési csatorna határozza meg, hová küld riasztást az IntraWatch, ha egy monitor leáll. Csatorna lehet például e-mail, Slack, Microsoft Teams, Telegram vagy webhook.

:::steps

1. Nyissa meg az [[route:ws.channels|Értesítési csatornák]] oldalt.
2. Kattintson az [[icon:mdi-plus]] **Új csatorna** gombra.
3. Válassza ki a **Csatorna típusa** értékét, adjon meg egy **Név** értéket, és töltse ki a típushoz tartozó mezőket.
4. Kapcsolja be az **Alapértelmezett csatorna** beállítást, ha azt szeretné, hogy minden új monitor erre a csatornára is riasszon.
5. A már meglévő monitorokhoz, így az előbb létrehozotthoz is, kapcsolja be a **Meglévő monitorokra is** beállítást.
6. A **Teszt** részben a **Tesztüzenet küldése** gombbal ellenőrizheti a beállítást.
7. Kattintson a **Létrehozás** gombra.

:::

Egy monitor csatornáit a monitor szerkesztőjében, az **Értesítések és címkék** részben is megadhatja. Részletek: [[manual:monitoring/notifications|Értesítési csatornák]].

## 3. Státuszoldal

A státuszoldal nyilvános oldal, amelyen a látogatók bejelentkezés nélkül láthatják a kiválasztott monitorok állapotát.

:::steps

1. Nyissa meg a [[route:ws.status-pages|Státuszoldalak]] oldalt.
2. Kattintson az [[icon:mdi-plus]] **Új státuszoldal** gombra.
3. Adja meg a **Cím** és az **Azonosító (slug)** értékét.
4. Kattintson a **Létrehozás** gombra.
5. Az **Elrendezés** részben kattintson a **Szekció hozzáadása** gombra, és a **Monitor hozzáadása (keresés név szerint)** mezőben adja hozzá a monitort.
6. Kattintson az **Elrendezés mentése** gombra.
7. Kapcsolja be a **Közzétéve** kapcsolót, és kattintson a **Mentés** gombra. A nem közzétett oldal nyilvánosan nem érhető el.

:::

A [[icon:mdi-open-in-new]] **Nyilvános oldal megnyitása** gombbal megnézheti az eredményt. A változások a nyilvános oldalon akár 60 másodperc késéssel jelennek meg. Részletek: [[manual:monitoring/status-pages|Státuszoldalak]].

## 4. API-token

Ha egy szkriptből vagy más rendszerből szeretné elérni az IntraWatchot, hozzon létre egy API-tokent.

:::steps

1. Nyissa meg az [[route:ws.tokens|API-tokenek]] oldalt.
2. Adja meg a token nevét a **Név** mezőben, és jelölje be a szükséges **Jogosultságok** értékeket, például a **Monitorok olvasása** jogosultságot.
3. Kattintson a **Token létrehozása** gombra.
4. Másolja ki a megjelenő tokent az [[icon:mdi-content-copy]] ikonnal, majd kattintson a **Kimásoltam** gombra.

:::

:::warning

A token csak egyszer jelenik meg, később nem kérhető le újra. Tárolja biztonságos helyen.

:::

A token használatát és a jogosultságokat az [[manual:workspace/api-tokens|API-tokenek]] fejezet írja le.

## Hogyan tovább?

- Védje a fiókját kétlépcsős azonosítással: [[manual:account/account|Saját fiók]].
- Hívja meg a kollégáit: [[manual:workspace/workspace|Munkaterület és tagok]].
- Ha eddig Uptime Kumát használt, hozza át a beállításait: [[manual:workspace/import|Importálás Uptime Kumából]].
- A tervezett leállásokat karbantartásként rögzítheti: [[manual:monitoring/maintenances|Karbantartások]]. A kiesésekről incidensben tájékoztathatja a látogatókat: [[manual:monitoring/incidents|Incidensek]].
