<?php

// The workspace invitation email. :workspace, :inviter, :role and :app arrive HTML- and Markdown-escaped
// (WorkspaceInvitationMail::markdownText), except in `subject`, which is a plain header line.

return [
    'subject' => 'You have been invited to :workspace on :app',
    'heading' => 'You have been invited to :workspace',
    'intro' => ':inviter invited you to the **:workspace** workspace on :app with the **:role** role.',
    'how' => 'To join, sign in with this email address and open the link below. If you do not have an account yet, ask your administrator for an account with this email address first; sign-up is not open.',
    'accept' => 'Accept the invitation',
    'validity' => 'The link is valid for :days days and can be used once. If you did not expect this invitation, you can ignore this email.',
    'fallback' => 'If the button does not work, copy this address into your browser:',
    'roles' => [
        'owner' => 'owner',
        'admin' => 'admin',
        'editor' => 'editor',
        'viewer' => 'viewer',
    ],
];
