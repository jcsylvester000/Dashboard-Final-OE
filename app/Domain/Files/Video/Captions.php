<?php

namespace App\Domain\Files\Video;

use InvalidArgumentException;

/**
 * E-19: read a WebVTT or SubRip (.srt) caption file, check every cue, and return
 * clean WebVTT for the player's <track>. Invalid files are refused with the line
 * that is wrong, so the editor can fix it.
 */
class Captions
{
    private const TIME = '(?:(\d{1,2}):)?(\d{2}):(\d{2})[.,](\d{3})';

    /**
     * @return array{vtt: string, cues: int}
     */
    public static function toVtt(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Captions must be saved as UTF-8 text.');
        }
        $isVtt = str_starts_with(ltrim($text), 'WEBVTT');
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];
        if ($isVtt) {
            array_shift($blocks); // header block
        }

        $cues = [];
        $line = $isVtt ? 3 : 1;
        foreach ($blocks as $block) {
            $rows = explode("\n", trim($block, "\n"));
            $first = trim($rows[0]);
            if ($isVtt && preg_match('/^(NOTE|STYLE|REGION)\b/', $first) === 1) {
                $line += count($rows) + 1;

                continue;
            }
            $timingAt = null;
            foreach (array_slice($rows, 0, 2) as $i => $row) {
                if (str_contains($row, '-->')) {
                    $timingAt = $i;
                    break;
                }
            }
            if ($timingAt === null) {
                throw new InvalidArgumentException("Line {$line}: expected a timing line like 00:00:01.000 --> 00:00:03.500.");
            }
            if (preg_match('/^\s*'.self::TIME.'\s*-->\s*'.self::TIME.'(.*)$/', $rows[$timingAt], $m) !== 1) {
                throw new InvalidArgumentException('Line '.($line + $timingAt).': "'.mb_substr(trim($rows[$timingAt]), 0, 60).'" is not a valid time range.');
            }
            $start = self::ms($m[1], $m[2], $m[3], $m[4]);
            $end = self::ms($m[5], $m[6], $m[7], $m[8]);
            if ($end <= $start) {
                throw new InvalidArgumentException('Line '.($line + $timingAt).': the cue ends before it starts.');
            }
            $body = array_values(array_filter(array_slice($rows, $timingAt + 1), fn ($r) => trim($r) !== ''));
            if ($body === []) {
                throw new InvalidArgumentException('Line '.($line + $timingAt).': the cue has no text.');
            }
            $settings = $isVtt ? rtrim($m[9]) : '';
            $cues[] = self::stamp($start).' --> '.self::stamp($end).$settings."\n".implode("\n", array_map(fn ($r) => rtrim($r), $body));
            $line += count($rows) + 1;
        }

        if ($cues === []) {
            throw new InvalidArgumentException('No captions found in the file.');
        }

        return ['vtt' => "WEBVTT\n\n".implode("\n\n", $cues)."\n", 'cues' => count($cues)];
    }

    private static function ms(string $h, string $m, string $s, string $ms): int
    {
        if ((int) $m > 59 || (int) $s > 59) {
            throw new InvalidArgumentException('Minutes and seconds in a caption time must be below 60.');
        }

        return ((((int) $h * 60) + (int) $m) * 60 + (int) $s) * 1000 + (int) $ms;
    }

    private static function stamp(int $ms): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3_600_000), intdiv($ms, 60_000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }
}
