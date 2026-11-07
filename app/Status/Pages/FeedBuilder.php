<?php

namespace App\Status\Pages;

use App\Models\StatusPage;
use Carbon\CarbonImmutable;

/**
 * Atom 1.0 and RSS 2.0 documents of a page's incidents and maintenances, newest 50 entries. Every link and id
 * derives from the page's own public URL, never from the request.
 */
final class FeedBuilder
{
    public const MAX_ENTRIES = 50;

    public static function atom(StatusPage $page, PageView $view): string
    {
        $url = $page->publicUrl();
        $entries = self::entries($page, $view);
        $updated = $entries === [] ? $view->generatedAt : $entries[0]['updated'];

        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="'.self::x($view->locale).'">'
            .'<title>'.self::x($view->title).'</title>'
            .'<id>'.self::x($url).'</id>'
            .'<updated>'.self::atomDate($updated).'</updated>'
            .'<link rel="alternate" type="text/html" href="'.self::x($url).'"/>'
            .'<link rel="self" type="application/atom+xml" href="'.self::x($url.'/feed.atom').'"/>'
            .'<generator>IntraWatch</generator>';
        foreach ($entries as $entry) {
            $xml .= '<entry>'
                .'<title>'.self::x($entry['title']).'</title>'
                .'<id>'.self::x($entry['link']).'</id>'
                .'<link rel="alternate" type="text/html" href="'.self::x($entry['link']).'"/>'
                .'<published>'.self::atomDate($entry['published']).'</published>'
                .'<updated>'.self::atomDate($entry['updated']).'</updated>'
                .'<category term="'.self::x($entry['category']).'"/>'
                .'<content type="html">'.self::x($entry['html']).'</content>'
                .'</entry>';
        }

        return $xml.'</feed>'."\n";
    }

    public static function rss(StatusPage $page, PageView $view): string
    {
        $url = $page->publicUrl();
        $entries = self::entries($page, $view);
        $updated = $entries === [] ? $view->generatedAt : $entries[0]['updated'];

        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            .'<title>'.self::x($view->title).'</title>'
            .'<link>'.self::x($url).'</link>'
            .'<description>'.self::x($view->title).'</description>'
            .'<language>'.self::x($view->locale).'</language>'
            .'<lastBuildDate>'.$updated->utc()->format(DATE_RSS).'</lastBuildDate>'
            .'<atom:link rel="self" type="application/rss+xml" href="'.self::x($url.'/feed.rss').'"/>'
            .'<generator>IntraWatch</generator>';
        foreach ($entries as $entry) {
            $xml .= '<item>'
                .'<title>'.self::x($entry['title']).'</title>'
                .'<link>'.self::x($entry['link']).'</link>'
                .'<guid isPermaLink="false">'.self::x($entry['link']).'</guid>'
                .'<pubDate>'.$entry['published']->utc()->format(DATE_RSS).'</pubDate>'
                .'<category>'.self::x($entry['category']).'</category>'
                .'<description>'.self::x($entry['html']).'</description>'
                .'</item>';
        }

        return $xml.'</channel></rss>'."\n";
    }

    /**
     * @return list<array{title: string, link: string, category: string, published: CarbonImmutable, updated: CarbonImmutable, html: string}>
     */
    private static function entries(StatusPage $page, PageView $view): array
    {
        $url = $page->publicUrl();
        $entries = [];
        foreach ([...$view->openIncidents, ...$view->pastIncidents] as $incident) {
            $html = '';
            // Updates are stored newest first; a feed reader reads the story in order.
            foreach (array_reverse($incident->updates) as $update) {
                $label = __('status.incident_status.'.$update->status->value, [], $view->locale);
                $html .= '<p><strong>'.self::h($label).'</strong> <em>'.$update->createdAt->utc()->format('Y-m-d H:i').' UTC</em></p>'.$update->messageHtml;
            }
            $updated = $incident->startedAt;
            foreach ([$incident->resolvedAt, ...array_map(static fn (IncidentUpdateView $u): CarbonImmutable => $u->createdAt, $incident->updates)] as $moment) {
                if ($moment !== null && $moment->greaterThan($updated)) {
                    $updated = $moment;
                }
            }
            $entries[] = [
                'title' => $incident->title,
                'link' => $url.'#incident-'.$incident->id,
                'category' => 'incident',
                'published' => $incident->startedAt,
                'updated' => $updated,
                'html' => $html,
            ];
        }
        foreach ([...$view->activeMaintenance, ...$view->upcomingMaintenance] as $maintenance) {
            $html = $maintenance->descriptionHtml ?? '';
            $html .= '<p>'.$maintenance->startsAt->utc()->format('Y-m-d H:i').' UTC'
                .($maintenance->endsAt === null ? '' : ' - '.$maintenance->endsAt->utc()->format('Y-m-d H:i').' UTC').'</p>';
            if ($maintenance->affectedComponents !== []) {
                $html .= '<p>'.self::h(implode(', ', $maintenance->affectedComponents)).'</p>';
            }
            $entries[] = [
                'title' => $maintenance->title,
                'link' => $url.'#maintenance-'.$maintenance->id,
                'category' => 'maintenance',
                'published' => $maintenance->startsAt,
                'updated' => $maintenance->startsAt,
                'html' => $html,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$b['updated']->getTimestamp(), $b['link']] <=> [$a['updated']->getTimestamp(), $a['link']]);

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    private static function atomDate(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Escaped for an XML text node or attribute value, with characters XML cannot carry removed.
     */
    private static function x(string $text): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /**
     * Escaped for an HTML text node inside a feed body, which is escaped once more as XML.
     */
    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
