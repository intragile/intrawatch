<?php

namespace App\Alerting\Messages;

/**
 * Logic-free `{{ variable }}` substitution. Output is not escaped; each sender escapes for its format.
 */
final class TemplateRenderer
{
    /** @var list<string> */
    public const VARIABLES = [
        'kind', 'kind_label', 'severity', 'status', 'previous_status', 'message', 'at', 'at_local',
        'duration', 'duration_seconds', 'changes',
        'monitor.id', 'monitor.name', 'monitor.type', 'monitor.target', 'monitor.url',
        'workspace.name',
    ];

    private const PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\}\}/';

    /**
     * @param  array<string, string>  $vars  keyed by (dotted) variable name
     */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => $vars[$m[1]] ?? '',
            $template,
        );
    }

    /**
     * @return list<string> the distinct variable names that are not supported
     */
    public static function validate(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_diff($matches[1], self::VARIABLES)));
    }
}
