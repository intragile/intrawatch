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
            subject: 'You have been invited to '.self::plainText($this->workspaceName).' on '.config()->string('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                // New names on purpose: public properties are merged after `with` and would overwrite these.
                'markdownWorkspaceName' => self::markdownText($this->workspaceName),
                'markdownInviterName' => self::markdownText($this->inviterName),
                'markdownRole' => self::markdownText($this->role),
                'appName' => config()->string('app.name'),
            ],
        );
    }

    /**
     * One line of plain text. A line break in a header, or in the middle of a Markdown line, would break the
     * line and paragraph structure (it would end a heading line, for example), so every run of whitespace and
     * control characters becomes a single space.
     */
    private static function plainText(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value));
    }

    /**
     * Text that a Markdown template can echo raw ({!! !!}) and that still renders as the same literal text: it
     * is HTML-escaped, and every ASCII character Markdown gives a meaning to becomes a numeric entity. CommonMark
     * treats an entity as text, never as markup, so a name cannot make a link, emphasis or a heading. Entities
     * rather than backslash escapes, because the plain-text part decodes entities and would otherwise show the
     * backslashes. The entities that htmlspecialchars() itself just wrote (`&#039;` for an apostrophe, `&amp;`)
     * are kept whole: their `#` must not be escaped again, or the reader would see the literal `&#039;`. Every
     * `&` in the HTML-escaped text starts such an entity, because a literal `&` from the name became `&amp;`.
     */
    private static function markdownText(string $value): string
    {
        $html = htmlspecialchars(self::plainText($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace_callback(
            '/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);|[\\\\`*_{}\[\]()#+\-.!|~:]/i',
            fn (array $match): string => strlen($match[0]) > 1 ? $match[0] : '&#'.ord($match[0]).';',
            $html,
        );
    }
}
