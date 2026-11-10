# Eseménynapló

Az eseménynapló rögzíti, ki, mikor és mit módosított a munkaterületen, akár a felületen, akár API-tokennel. A bejegyzések utólag nem módosíthatók és nem törölhetők.

## Ki láthatja?

Az [[route:ws.audit|Eseménynapló]] oldalt **Adminisztrátor** és **Tulajdonos** szerepkörrel lehet megnyitni. A menüben a **Munkaterület kezelése** csoportban találja, az [[icon:mdi-file-document-outline]] ikonnal. API-ból az **Eseménynapló olvasása** (`audit:read`) jogosultságú token olvashatja.

A napló csak az aktuális munkaterület eseményeit mutatja, a legújabbal kezdve. Ha a lista hosszú, az oldal alján a **További elemek betöltése** gombbal tölthet be régebbi bejegyzéseket.

## A napló oszlopai

| Oszlop | Tartalom |
| --- | --- |
| Időpont | Mikor történt az esemény. |
| Művelet | Az esemény technikai neve, például `monitor.updated`. |
| Végrehajtó | Ki végezte a műveletet (lásd lent). |
| Érintett elem | A módosított elem típusa és azonosítója, például `monitor #12`. |
| Változások | A módosított mezők régi és új értéke, `régi → új` formában. |
| IP-cím | Az a cím, ahonnan a kérés érkezett. |

A **Végrehajtó** oszlopban háromféle bejegyzés szerepelhet:

- **5. felhasználó** (a szám a felhasználó azonosítója): egy tag a felületen végezte a műveletet.
- **3. token, tulajdonosa: 5. felhasználó**: a műveletet egy API-token végezte. Az első szám a token, a második a tokent létrehozó felhasználó azonosítója.
- **Rendszer**: a műveletet maga az IntraWatch végezte, nem egy személy.

:::note

Jelszavak, tokenek, titkos kulcsok és hasonló érzékeny adatok értéke soha nem kerül a naplóba. Az ilyen mezők a **Változások** oszlopban sem jelennek meg.

:::

## Rögzített események

A napló többek között a következő műveleteket rögzíti. A **Művelet** oszlop a technikai nevet mutatja.

| Terület | Műveletek |
| --- | --- |
| Munkaterület | `workspace.created`, `workspace.updated` |
| Tagok | `member.invited`, `member.invitation_revoked`, `member.added`, `member.role_changed`, `member.removed` |
| API-tokenek | `token.created`, `token.revoked` |
| Monitorok | `monitor.created`, `monitor.updated`, `monitor.deleted`, `monitor.check_requested`, `monitor.push_token_rotated` |
| Címkék | `tag.created`, `tag.updated`, `tag.deleted` |
| Értesítési csatornák | `notification_channel.created`, `notification_channel.updated`, `notification_channel.deleted` |
| Incidensek | `incident.created`, `incident.updated`, `incident.deleted`, `incident.update_added`, `incident.update_edited`, `incident.update_deleted` |
| Karbantartások | `maintenance.created`, `maintenance.updated`, `maintenance.ended`, `maintenance.deleted` |
| Státuszoldalak | `status_page.created`, `status_page.updated`, `status_page.layout_replaced`, `status_page.deleted` |
| Importálás | `import.completed` |

A `member.added` akkor kerül a naplóba, amikor a meghívott elfogadja a meghívót. A `monitor.check_requested` egy azonnali ellenőrzés kérését jelzi.

## Szűrés

A **Szűrők** részben szűkítheti a listát. A beállított szűrők csak a **Szűrők alkalmazása** gombra kattintás után lépnek érvénybe.

- **Művelet**: a művelet pontos technikai neve, például `member.role_changed`. Részleges egyezésre nem szűr.
- **Végrehajtó típusa**: **Felhasználó**, **API-token** vagy **Rendszer**.
- **Felhasználó azonosítója**: a művelet mögött álló személy azonosítója. Felhasználói műveletnél ez maga a felhasználó, tokennél a token tulajdonosa. Így egy tag összes műveletét egyszerre láthatja, akár a felületen, akár a tokenjeivel végezte.
- **Végrehajtó azonosítója**: a végrehajtó saját azonosítója, vagyis felhasználónál a felhasználóé, tokennél a tokené.

A **Törlés** gomb kiüríti a mezőket, és újra a teljes listát mutatja.

Ha egyetlen bejegyzés sem felel meg a szűrőknek, a **Nincs a szűrőknek megfelelő esemény.** felirat jelenik meg.

## Tipikus kérdések

### Ki változtatta meg ezt a monitort?

Írja a **Művelet** mezőbe a `monitor.updated` értéket, és alkalmazza a szűrőt. Az **Érintett elem** oszlopban keresse meg a monitort, a **Változások** oszlopban pedig azt, hogy mi változott.

### Mit csinált egy adott API-token?

Válassza a **Végrehajtó típusa** mezőben az **API-token** értéket, és írja a **Végrehajtó azonosítója** mezőbe a token azonosítóját. A token azonosítóját a `token.created` bejegyzés **Érintett elem** oszlopában találja. A tokenekről az [[manual:workspace/api-tokens|API-tokenek]] fejezet szól.

### Ki kapott új szerepkört?

Szűrjön a `member.role_changed` műveletre. A **Változások** oszlop a régi és az új szerepkört mutatja. A szerepkörökről a [[manual:workspace/workspace|Munkaterület és tagok]] fejezet szól.
