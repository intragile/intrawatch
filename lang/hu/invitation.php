<?php

// A munkaterület-meghívó levele. A :workspace, :inviter, :role és :app értéke HTML- és Markdown-escape-elve
// érkezik (WorkspaceInvitationMail::markdownText), kivéve a `subject`-et, amely sima fejléc-sor.
// A nevek elé nem kerül névelő, mert az a/az a név kiejtésétől függ.

return [
    'subject' => 'Meghívó: :workspace (:app)',
    'heading' => 'Meghívó munkaterületre: :workspace',
    'intro' => ':inviter meghívott, hogy **:role** szerepkörrel csatlakozz ehhez a munkaterülethez: **:workspace** (:app).',
    'how' => 'A csatlakozáshoz jelentkezz be ezzel az e-mail-címmel, és nyisd meg az alábbi hivatkozást. Ha még nincs fiókod, előbb kérj a rendszergazdától fiókot ehhez az e-mail-címhez; nyilvános regisztráció nincs.',
    'accept' => 'Meghívó elfogadása',
    'validity' => 'A hivatkozás :days napig érvényes, és egyszer használható. Ha nem számítottál erre a meghívóra, nyugodtan hagyd figyelmen kívül ezt a levelet.',
    'fallback' => 'Ha a gomb nem működik, másold be ezt a címet a böngésződbe:',
    'roles' => [
        'owner' => 'tulajdonos',
        'admin' => 'adminisztrátor',
        'editor' => 'szerkesztő',
        'viewer' => 'megtekintő',
    ],
];
