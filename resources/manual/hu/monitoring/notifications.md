# Értesítési csatornák

Az értesítési csatorna határozza meg, hová jutnak el a monitorok riasztásai: e-mailbe, chatbe, push-értesítésként vagy egy webhookra. Ez a fejezet bemutatja, hogyan hozhat létre csatornát, hogyan próbálhatja ki, és hol követheti a kiküldött riasztásokat.

## Hogyan jut el egy riasztás Önhöz?

Egy riasztás csak azokra a csatornákra megy ki, amelyekre a monitor fel van iratkozva. Egy monitor több csatornára is feliratkozhat, és egy csatornát több monitor is használhat. A feliratkozást a monitor szerkesztőjében, az **Értesítési csatornák** mezőben, vagy a csatorna oldalán, a **Monitorok** kártyán állíthatja be.

A csatornák létrehozásához és módosításához legalább **Szerkesztő** szerepkör kell.

## Új csatorna létrehozása

:::steps

1. Nyissa meg az [[route:ws.channels|Értesítési csatornák]] képernyőt, és kattintson az [[icon:mdi-plus]] **Új csatorna** gombra.
2. Válassza ki a **Csatorna típusa** mezőben a típust. A típus később nem módosítható.
3. Adja meg a **Név** mezőt.
4. Töltse ki a típushoz tartozó beállításokat.
5. A **Teszt** szakaszban kattintson a [[icon:mdi-send]] **Tesztüzenet küldése** gombra, és ellenőrizze, hogy megérkezett-e az üzenet.
6. Kattintson a **Létrehozás** gombra.

:::

![Az új csatorna űrlapja Slack típussal: a Név mezőben „#alerts”, a Slack beállításai alatt a Webhook URL mező, majd a Kézbesítés és az Üzenet szövege szakasz](/admin/manual/hu/channel-new.webp)

## Csatornatípusok

| Típus | Kötelező adatok |
| --- | --- |
| E-mail | **Címzettek** (egy vagy több e-mail-cím) |
| Webhook | **URL**, **HTTP-metódus**, **Törzs formátuma** |
| Slack, Discord, Microsoft Teams, Mattermost, Google Chat | **Webhook URL** |
| Telegram | **Bot token**, **Chat azonosító** |
| ntfy | **Kiszolgáló URL**, **Téma** |
| Gotify | **Kiszolgáló URL**, **Alkalmazás token** |
| Pushover | **Felhasználói kulcs**, **Alkalmazás token** |
| Matrix | **Homeserver URL**, **Szoba azonosítója**, **Hozzáférési token** |
| PagerDuty | **Útválasztó kulcs** |
| Opsgenie | **API-kulcs**, **Régió** |
| Apprise | **Kiszolgáló URL**, valamint **Konfigurációs kulcs** vagy **Értesítési URL-ek** |

Az e-mail-csatorna alapból az alkalmazás levelezőjén keresztül küld. Ha a **Saját SMTP-kiszolgálót használok** kapcsolót bekapcsolja, megadhatja a saját kiszolgálóját és a feladó címét.

A webhook-csatornánál a hitelesítő adatokat a **Titkos fejlécek** közé írja. Az **Aláíró kulcs** megadásával a fogadó oldal ellenőrizheti, hogy a kérés valóban az IntraWatchtól jött: az alkalmazás ilyenkor egy `X-IntraWatch-Signature` fejlécet is küld.

:::note

A tokeneket, jelszavakat és webhook-címeket mentés után többé nem jeleníti meg az alkalmazás. Szerkesztéskor a **Csere** gombbal adhat meg új értéket, vagy megtarthatja a tárolt értéket.

:::

## Kézbesítési beállítások

- **Aktív**: az inaktív csatorna nem kap riasztást. Így ideiglenesen elnémíthat egy csatornát anélkül, hogy törölné.
- **Alapértelmezett csatorna**: az új monitorok automatikusan feliratkoznak erre a csatornára.
- **Meglévő monitorokra is**: csak akkor jelenik meg, ha az **Alapértelmezett csatorna** be van kapcsolva. Mentéskor a már meglévő monitorokat is feliratkoztatja.

## Az üzenet szövege

Az **Üzenet szövege** szakaszban saját **Cím sablon** és **Törzs sablon** adható meg. Üresen hagyva a beépített szöveg szerepel. A sablonban többek között a riasztás típusa, a monitor neve és címe, az üzenet és az időtartam használható változóként; a pontos lista a mező alatt látható.

## Csatorna kipróbálása

A **Tesztüzenet küldése** gomb azonnal egy tesztüzenetet küld a csatornára, és kiírja, hogy **A teszt megérkezett** vagy **A teszt sikertelen**. Új csatornánál a még el nem mentett beállításokat próbálja ki. Meglévő csatornánál a mentett beállításokat használja, ezért módosítás után előbb mentsen.

## Monitorok feliratkoztatása egy csatornára

Egy meglévő csatorna oldalán a **Monitorok** kártya mutatja a feliratkozott monitorok számát. A **Módosítandó monitorok** mezőben válassza ki a monitorokat (egyszerre legfeljebb 500-at), majd kattintson a **Feliratkoztatás** vagy a **Leiratkoztatás** gombra.

## A csatornalista

A listában minden csatornánál látható, hogy **Aktív** vagy **Inaktív**, alapértelmezett-e, hány monitor használja, és mikor volt a **Legutóbbi kézbesítés**. Ha az utolsó kézbesítés sikertelen volt, az **Utolsó hiba** a hibaüzenettel együtt jelenik meg. A sor végén az [[icon:mdi-pencil]] ikonnal szerkesztheti, a [[icon:mdi-trash-can-outline]] ikonnal törölheti a csatornát. Törlés után a korábbi riasztási napló megmarad.

## Riasztási napló

A [[route:ws.alerts|Riasztási napló]] képernyőn az összes kiküldött riasztást visszakeresheti **Típus**, **Monitor** és kezdő időpont (**Ettől**) szerint. Minden sornál látható, melyik csatornára ment ki, és sikeres volt-e a kézbesítés (**Sorban áll**, **Elküldve** vagy **Sikertelen**).

A riasztások típusai:

| Típus | Mikor keletkezik |
| --- | --- |
| Leállt | A monitor leállt. |
| Újra elérhető | A leállt monitor újra működik. |
| Akadozik | A monitor **Akadozik** állapotba került, és be van kapcsolva a **Riasztás akadozáskor**. |
| Még mindig leállt | Emlékeztető, ha a monitornál be van állítva az **Emlékeztető időköze (perc)**. |
| Ingadozik | A monitor rövid idő alatt sokszor váltott állapotot; amíg ingadozik, nem kap minden váltásról külön üzenetet. |
| Stabil | Az ingadozás véget ért. |
| Hamarosan lejár | Közeledik egy tanúsítvány vagy domain lejárata. |
| Megújult | A tanúsítvány vagy domain lejárata kitolódott. |
| Megváltozott | Megváltozott a tanúsítvány vagy a DNS-válasz. |

A **Hamarosan lejár**, a **Megújult** és a **Megváltozott** riasztás csak akkor keletkezik, ha a monitornál be van kapcsolva az **Értesítés a tanúsítvány, a DNS és a lejárat változásairól** kapcsoló. Ha egy riasztásnál az **Egy szülő monitor elnyomta.** felirat áll, a monitor csoportja már leállt, ezért a tag riasztása nem ment ki külön. Részletek: [[manual:monitoring/monitors|Monitorok]].
