<?php

namespace Tests\Feature\Workspaces;

use App\Mail\WorkspaceInvitationMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The invitation mail puts user-controlled names (workspace, inviter) into a Markdown template and the subject.
 * They must stay plain, readable text: no links, emphasis or headings from Markdown, no line breaks in the subject.
 */
class InvitationMailTest extends TestCase
{
    private const string URL = 'https://intrawatch.test/invitations/accept?token=abc';

    private function mail(string $workspaceName, string $inviterName = 'Ada Admin', string $role = 'member'): WorkspaceInvitationMail
    {
        return new WorkspaceInvitationMail($workspaceName, $inviterName, $role, self::URL, 7);
    }

    public function test_names_cannot_inject_markdown_links(): void
    {
        $html = $this->mail('Acme] [Verify](https://evil.example) [x', 'Eve [Click](https://evil.example)')->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringContainsString('Verify', $html);
        $this->assertStringContainsString('Eve', $html);

        // The only links are the layout's header link to the app and the invitation itself.
        preg_match_all('/href="([^"]*)"/', $html, $matches);
        $hrefs = array_map(fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches[1]);
        $this->assertContains(self::URL, $hrefs);
        $this->assertSame([], array_values(array_diff($hrefs, [self::URL, config()->string('app.url')])));
    }

    public function test_names_cannot_inject_markdown_emphasis(): void
    {
        $html = $this->mail('*bold* _em_ __x__')->render();

        // The CSS inliner adds style attributes, so match the tags with any attributes.
        $this->assertDoesNotMatchRegularExpression('/<em[\s>]/', $html);
        $this->assertDoesNotMatchRegularExpression('/<strong[^>]*>\s*(bold|x)\s*<\/strong>/', $html);
        $this->assertStringContainsString('*bold*', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('_em_', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_subject_is_a_single_line(): void
    {
        $subject = $this->mail("Acme\r\nBcc: x@evil.example")->envelope()->subject;

        $this->assertIsString($subject);
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Acme Bcc: x@evil.example', $subject);
    }

    public function test_line_breaks_in_names_cannot_start_a_heading(): void
    {
        $html = $this->mail("Acme\n# Pwned")->render();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Pwned\s*<\/h1>/', $html);
        $this->assertStringContainsString('Pwned', $html);
    }

    public function test_html_in_names_is_escaped_once(): void
    {
        $mail = $this->mail('A & <b>B</b>');

        $this->assertStringNotContainsString('<b>B</b>', $mail->render());

        $mail->assertSeeInText('A & <b>B</b>');
        $mail->assertDontSeeInText('&amp;');
        $mail->assertDontSeeInText('&#');
    }

    public function test_apostrophes_in_names_render_as_apostrophes(): void
    {
        $mail = $this->mail("O'Brien & Co", "Ann D'Angelo");
        $html = $mail->render();

        // htmlspecialchars() writes `&#039;`; its `#` must not be escaped a second time (`&&#35;039;`).
        $this->assertStringNotContainsString('&amp;#', $html);
        $this->assertStringNotContainsString('&#35;039;', $html);
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString("You have been invited to O'Brien & Co", $decoded);
        $this->assertStringContainsString("Ann D'Angelo", $decoded);

        $mail->assertSeeInText("O'Brien & Co");
        $mail->assertSeeInText("Ann D'Angelo");
        $mail->assertDontSeeInText('&#');
    }

    public function test_entity_like_text_in_names_stays_literal(): void
    {
        $mail = $this->mail('Team &#35;1 &amp; more');

        $mail->assertSeeInText('Team &#35;1 &amp; more');
        $this->assertStringContainsString('Team &#35;1 &amp; more', html_entity_decode($mail->render(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_renders_in_english_by_default(): void
    {
        $mail = $this->mail('Acme', role: 'editor');

        $mail->assertSeeInText('You have been invited to Acme');
        $mail->assertSeeInText('editor');
        $mail->assertSeeInText('Accept the invitation');
        $mail->assertSeeInText('All rights reserved.');
    }

    public function test_renders_in_hungarian_with_the_hungarian_locale(): void
    {
        $mail = $this->mail('Acme', role: 'editor')->locale('hu');

        $mail->assertSeeInText('Meghívó munkaterületre: Acme');
        $mail->assertSeeInText('szerkesztő');
        $mail->assertSeeInText('Meghívó elfogadása');
        $mail->assertSeeInText('Minden jog fenntartva.');
        $mail->assertDontSeeInText('You have been invited');
    }

    public function test_subject_follows_the_locale(): void
    {
        App::setLocale('hu');
        $subject = $this->mail('Acme')->envelope()->subject;

        $this->assertSame('Meghívó: Acme ('.config()->string('app.name').')', $subject);
    }
}
