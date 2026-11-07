<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;

/**
 * Flat, shields-style status badges as SVG. Text widths come from a fixed per-character table, so rendering
 * needs no font and the output is deterministic; every text node and attribute is escaped.
 */
final class Badge
{
    public const STYLES = ['flat', 'flat-square'];

    public const UPTIME_DAYS = [1, 7, 30, 90, 365];

    public const MAX_LABEL = 40;

    public const GREY = '#9f9f9f';

    private const PADDING = 5;

    private const DEFAULT_WIDTH = 7.0;

    private const WIDE_WIDTH = 11.0;

    /** Advance widths of Verdana 11 px, in px. */
    private const WIDTHS = [
        ' ' => 3.8, '!' => 4.5, '"' => 5.1, '#' => 8.3, '$' => 7.0, '%' => 11.0, '&' => 7.7, "'" => 2.9,
        '(' => 4.5, ')' => 4.5, '*' => 7.0, '+' => 8.3, ',' => 3.8, '-' => 4.6, '.' => 3.8, '/' => 4.8,
        '0' => 7.0, '1' => 7.0, '2' => 7.0, '3' => 7.0, '4' => 7.0, '5' => 7.0, '6' => 7.0, '7' => 7.0, '8' => 7.0, '9' => 7.0,
        ':' => 4.8, ';' => 4.8, '<' => 8.3, '=' => 8.3, '>' => 8.3, '?' => 6.2, '@' => 10.9,
        'A' => 7.5, 'B' => 7.6, 'C' => 7.3, 'D' => 8.3, 'E' => 6.8, 'F' => 6.3, 'G' => 8.1, 'H' => 8.2, 'I' => 4.6,
        'J' => 4.9, 'K' => 7.6, 'L' => 6.1, 'M' => 9.2, 'N' => 8.3, 'O' => 8.6, 'P' => 6.5, 'Q' => 8.6, 'R' => 7.6,
        'S' => 7.2, 'T' => 6.7, 'U' => 8.0, 'V' => 7.5, 'W' => 11.1, 'X' => 7.5, 'Y' => 6.9, 'Z' => 7.5,
        '[' => 4.8, '\\' => 4.8, ']' => 4.8, '^' => 8.3, '_' => 7.0, '`' => 7.0,
        'a' => 6.6, 'b' => 7.0, 'c' => 6.0, 'd' => 7.0, 'e' => 6.7, 'f' => 3.9, 'g' => 7.0, 'h' => 7.0, 'i' => 3.0,
        'j' => 3.7, 'k' => 6.6, 'l' => 3.0, 'm' => 10.7, 'n' => 7.0, 'o' => 6.8, 'p' => 7.0, 'q' => 7.0, 'r' => 4.7,
        's' => 6.0, 't' => 4.4, 'u' => 7.0, 'v' => 6.6, 'w' => 9.0, 'x' => 6.6, 'y' => 6.6, 'z' => 6.0,
        '{' => 7.0, '|' => 4.8, '}' => 7.0, '~' => 8.3,
    ];

    public static function render(string $label, string $message, string $color, string $style): string
    {
        $square = $style === 'flat-square';
        $labelWidth = self::width($label) + 2 * self::PADDING;
        $messageWidth = self::width($message) + 2 * self::PADDING;
        $total = $labelWidth + $messageWidth;
        $text = self::escape($label.': '.$message);
        $labelX = self::number($labelWidth / 2);
        $messageX = self::number($labelWidth + $messageWidth / 2);
        $radius = $square ? '0' : '3';
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : self::GREY;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::number($total).'" height="20" role="img" aria-label="'.$text.'">'
            .'<title>'.$text.'</title>';
        if (! $square) {
            $svg .= '<linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>';
        }
        $svg .= '<clipPath id="r"><rect width="'.self::number($total).'" height="20" rx="'.$radius.'" fill="#fff"/></clipPath>'
            .'<g clip-path="url(#r)"><rect width="'.self::number($labelWidth).'" height="20" fill="#555"/>'
            .'<rect x="'.self::number($labelWidth).'" width="'.self::number($messageWidth).'" height="20" fill="'.$color.'"/>';
        if (! $square) {
            $svg .= '<rect width="'.self::number($total).'" height="20" fill="url(#s)"/>';
        }
        $svg .= '</g><g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="11">';
        foreach ([[$labelX, $label], [$messageX, $message]] as [$x, $content]) {
            if (! $square) {
                $svg .= '<text x="'.$x.'" y="15" fill="#010101" fill-opacity=".3">'.self::escape($content).'</text>';
            }
            $svg .= '<text x="'.$x.'" y="14">'.self::escape($content).'</text>';
        }

        return $svg.'</g></svg>';
    }

    public static function statusColor(DisplayStatus $status): string
    {
        return match ($status) {
            DisplayStatus::Operational => '#4c1',
            DisplayStatus::Degraded => '#dfb317',
            DisplayStatus::PartialOutage => '#fe7d37',
            DisplayStatus::MajorOutage => '#e05d44',
            DisplayStatus::Maintenance => '#007ec6',
            DisplayStatus::Unknown => self::GREY,
        };
    }

    public static function statusMessage(DisplayStatus $status): string
    {
        return match ($status) {
            DisplayStatus::Operational => 'operational',
            DisplayStatus::Degraded => 'degraded',
            DisplayStatus::PartialOutage => 'partial outage',
            DisplayStatus::MajorOutage => 'major outage',
            DisplayStatus::Maintenance => 'maintenance',
            DisplayStatus::Unknown => 'unknown',
        };
    }

    public static function uptimeColor(float $percent): string
    {
        return match (true) {
            $percent >= 99.9 => '#4c1',
            $percent >= 99 => '#a4a61d',
            $percent >= 95 => '#dfb317',
            default => '#e05d44',
        };
    }

    public static function uptimeMessage(float $percent): string
    {
        $text = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        return ($text === '' ? '0' : $text).'%';
    }

    private static function width(string $text): float
    {
        $width = 0.0;
        foreach (mb_str_split($text) as $char) {
            $width += self::WIDTHS[$char] ?? (strlen($char) > 1 && mb_strwidth($char) > 1 ? self::WIDE_WIDTH : self::DEFAULT_WIDTH);
        }

        return $width;
    }

    private static function number(float $value): string
    {
        $rounded = round($value, 1);

        return $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;
    }

    private static function escape(string $text): string
    {
        // Characters XML 1.0 cannot carry would make the image unparseable.
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
