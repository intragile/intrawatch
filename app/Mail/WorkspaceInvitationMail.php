<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation into a workspace. It goes to every invited address alike and never says whether an account
 * exists for it. It is queued (encrypted, since the link carries the token) after the invitation commits, so
 * the answer to the inviter takes the same time for every address.
 */
class WorkspaceInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly string $url,
        public readonly int $expireDays,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You have been invited to {$this->workspaceName} on ".config()->string('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                'appName' => config()->string('app.name'),
            ],
        );
    }
}
