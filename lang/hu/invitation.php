<?php

// A munkaterület-meghívó levele. A :workspace, :inviter, :role és :app értéke HTML- és Markdown-escape-elve
// érkezik (WorkspaceInvitationMail::markdownText), kivéve a `subject`-et, amely sima fejléc-sor.
// A nevek elé nem kerül névelő, mert az a/az a név kiejtésétől függ.

return [
    'subject' => 'Meghívó: :workspace (:app)',
    'heading' => 'Meghívó munkaterületre: :workspace',
    'intro' => ':inviter meghívta Önt, hogy **:role** szerepkörrel csatlakozzon ehhez a munkaterülethez: **:workspace** (:app).',
    'how' => 'A csatlakozáshoz jelentkezzen be ezzel az e-mail-címmel, és nyissa meg az alábbi hivatkozást. Ha még nincs fiókja, előbb kérjen a rendszergazdától fiókot ehhez az e-mail-címhez; nyilvános regisztráció nincs.',
    'accept' => 'Meghívó elfogadása',
    'validity' => 'A hivatkozás :days napig érvényes, és egyszer használható. Ha nem számított erre a meghívóra, nyugodtan hagyja figyelmen kívül ezt a levelet.',
    'fallback' => 'Ha a gomb nem működik, másolja be ezt a címet a böngészőjébe:',
    'roles' => [
        'owner' => 'tulajdonos',
        'admin' => 'adminisztrátor',
        'editor' => 'szerkesztő',
        'viewer' => 'megtekintő',
    ],
];
