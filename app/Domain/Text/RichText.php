<?php

namespace App\Domain\Text;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * E-20: Markdown for task descriptions and comments (headings, lists, links, inline code,
 * fenced code blocks, tables). Raw HTML is escaped and unsafe links (javascript:, data:)
 * are dropped, so the output is safe to show with v-html.
 *
 * Mentions (@[Name](user:12), @[Team](dept:3)) and pasted files ([[file:34]], E-10) are
 * swapped for placeholders before Markdown runs and rendered afterwards.
 */
class RichText
{
    public const FILE_TOKEN = '/\[\[file:(\d{1,10})\]\]/';

    private const MENTION_TOKEN = '/@\[([^\]\r\n]{1,80})\]\((user|dept):(\d{1,10})\)/u';

    private ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * File ids referenced by [[file:N]] tokens.
     *
     * @return list<int>
     */
    public static function fileIds(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        preg_match_all(self::FILE_TOKEN, $text, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * @param  array<int, array{name: string, url: string, thumb_url: string|null, image: bool}>  $files  id => file (only files the viewer may open)
     */
    public function render(?string $text, array $files = []): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $slots = [];
        $slot = function (string $html) use (&$slots): string {
            $key = 'OESLOT'.count($slots).'X';
            $slots[$key] = $html;

            return $key;
        };

        $text = (string) preg_replace_callback(self::MENTION_TOKEN, fn (array $m) => $slot(sprintf(
            '<span class="rounded px-1 font-medium %s">@%s</span>',
            $m[2] === 'dept' ? 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
            e($m[1]),
        )), $text);

        $text = (string) preg_replace_callback(self::FILE_TOKEN, function (array $m) use ($files, $slot) {
            $file = $files[(int) $m[1]] ?? null;
            if ($file === null) {
                return $slot('<span class="text-muted-foreground">[file removed]</span>');
            }
            $inner = $file['image']
                ? sprintf('<img src="%s" alt="%s" class="oe-file-img" loading="lazy">', e($file['thumb_url'] ?? $file['url']), e($file['name']))
                : '&#128206; '.e($file['name']);

            return $slot(sprintf('<a href="%s" target="_blank" rel="noopener" class="oe-file">%s</a>', e($file['url']), $inner));
        }, $text);

        $this->converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);
        $html = (string) $this->converter->convert($text);

        return strtr($html, $slots);
    }
}
