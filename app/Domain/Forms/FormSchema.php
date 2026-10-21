<?php

namespace App\Domain\Forms;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E-53: the shape of a form and the rules a submission must meet. The same visibility
 * and validation logic runs in the builder preview (resources/js/lib/forms.ts), so what
 * the preview accepts the server accepts. Hidden fields are never required and their
 * answers are dropped.
 *
 * Schema: {sections: [{id, title, show_if, fields: [{key, label, type, required, help,
 * options, show_if, max_files}]}]}; show_if = {field, op: eq|neq|filled|empty|includes, value}.
 */
class FormSchema
{
    public const TYPES = [
        'text' => 'Short text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date',
        'select' => 'Pick one', 'multiselect' => 'Pick several', 'file' => 'File upload', 'url' => 'Link',
        'yesno' => 'Yes / no', 'rating' => 'Rating 1-5',
    ];

    public const OPS = ['eq', 'neq', 'filled', 'empty', 'includes'];

    public const MAX_FIELDS = 60;

    /**
     * Clean a schema from the builder; throws when it is not usable.
     *
     * @param  array<string, mixed>  $raw
     * @return array{sections: list<array<string, mixed>>}
     */
    public static function normalize(array $raw): array
    {
        $sections = [];
        $keys = [];
        $count = 0;
        foreach (array_slice((array) ($raw['sections'] ?? []), 0, 20) as $si => $section) {
            if (! is_array($section)) {
                continue;
            }
            $fields = [];
            foreach ((array) ($section['fields'] ?? []) as $fi => $field) {
                if (! is_array($field) || ++$count > self::MAX_FIELDS) {
                    continue;
                }
                $type = (string) ($field['type'] ?? 'text');
                if (! array_key_exists($type, self::TYPES)) {
                    throw ValidationException::withMessages(["draft.sections.{$si}.fields.{$fi}.type" => __('Unknown field type.')]);
                }
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') {
                    throw ValidationException::withMessages(["draft.sections.{$si}.fields.{$fi}.label" => __('Every field needs a label.')]);
                }
                $key = Str::slug((string) ($field['key'] ?? '') ?: $label, '_') ?: 'field';
                $key = mb_substr($key, 0, 40);
                $base = $key;
                for ($n = 2; in_array($key, $keys, true); $n++) {
                    $key = $base.'_'.$n;
                }
                $options = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) ($field['options'] ?? [])), fn ($o) => $o !== '')));
                if (in_array($type, ['select', 'multiselect'], true) && $options === []) {
                    throw ValidationException::withMessages(["draft.sections.{$si}.fields.{$fi}.options" => __('":l" needs at least one option.', ['l' => $label])]);
                }
                $fields[] = [
                    'key' => $key,
                    'label' => mb_substr($label, 0, 120),
                    'type' => $type,
                    'required' => (bool) ($field['required'] ?? false),
                    'help' => isset($field['help']) && trim((string) $field['help']) !== '' ? mb_substr(trim((string) $field['help']), 0, 300) : null,
                    'options' => in_array($type, ['select', 'multiselect'], true) ? array_slice($options, 0, 50) : [],
                    'show_if' => self::condition($field['show_if'] ?? null, $keys),
                    'max_files' => $type === 'file' ? max(1, min(5, (int) ($field['max_files'] ?? 1))) : null,
                ];
                $keys[] = $key;
            }
            $sections[] = [
                'id' => 's'.($si + 1),
                'title' => isset($section['title']) && trim((string) $section['title']) !== '' ? mb_substr(trim((string) $section['title']), 0, 120) : null,
                // A section's condition can only look at fields above it.
                'show_if' => self::condition($section['show_if'] ?? null, array_slice($keys, 0, count($keys) - count($fields))),
                'fields' => $fields,
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * Every field of a schema in order (sections flattened).
     *
     * @param  array<string, mixed>|null  $schema
     * @return list<array<string, mixed>>
     */
    public static function fields(?array $schema): array
    {
        $out = [];
        foreach ((array) ($schema['sections'] ?? []) as $section) {
            foreach ((array) (is_array($section) ? ($section['fields'] ?? []) : []) as $field) {
                if (is_array($field)) {
                    $out[] = $field;
                }
            }
        }

        return $out;
    }

    /**
     * Visible fields for these answers, in order.
     *
     * @param  array{sections: list<array<string, mixed>>}|array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    public static function visibleFields(array $schema, array $answers): array
    {
        $visible = [];
        $seen = [];
        foreach ((array) ($schema['sections'] ?? []) as $section) {
            if (! is_array($section) || ! self::passes($section['show_if'] ?? null, $answers, $seen)) {
                continue;
            }
            foreach ((array) ($section['fields'] ?? []) as $field) {
                if (is_array($field) && self::passes($field['show_if'] ?? null, $answers, $seen)) {
                    $visible[] = $field;
                    $seen[(string) $field['key']] = true;
                }
            }
        }

        return $visible;
    }

    /**
     * Validate answers (and file counts) against the schema; returns only visible answers.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @param  array<string, int>  $fileCounts  field key => number of files sent
     * @return array<string, mixed>
     */
    public static function validate(array $schema, array $answers, array $fileCounts = []): array
    {
        $rules = [];
        $names = [];
        $clean = [];
        foreach (self::visibleFields($schema, $answers) as $f) {
            $key = (string) $f['key'];
            $names["answers.{$key}"] = (string) $f['label'];
            $req = (bool) $f['required'] ? 'required' : 'nullable';
            $options = (array) ($f['options'] ?? []);
            if ($f['type'] === 'file') {
                $n = (int) ($fileCounts[$key] ?? 0);
                if ($f['required'] && $n === 0) {
                    throw ValidationException::withMessages(["files.{$key}" => __(':l is required.', ['l' => $f['label']])]);
                }
                if ($n > (int) ($f['max_files'] ?? 1)) {
                    throw ValidationException::withMessages(["files.{$key}" => __('Up to :n file(s) for :l.', ['n' => $f['max_files'], 'l' => $f['label']])]);
                }

                continue;
            }
            $rules["answers.{$key}"] = match ($f['type']) {
                'textarea' => [$req, 'string', 'max:5000'],
                'number' => [$req, 'numeric', 'between:-1000000000,1000000000'],
                'date' => [$req, 'date_format:Y-m-d'],
                'select' => [$req, Rule::in($options)],
                'multiselect' => [$req, 'array', 'max:50'],
                'url' => [$req, 'url:http,https', 'max:2048'],
                'yesno' => [$req, Rule::in(['yes', 'no'])],
                'rating' => [$req, 'integer', 'between:1,5'],
                default => [$req, 'string', 'max:500'],
            };
            if ($f['type'] === 'multiselect') {
                $rules["answers.{$key}.*"] = [Rule::in($options)];
            }
            if (array_key_exists($key, $answers) && $answers[$key] !== null && $answers[$key] !== '' && $answers[$key] !== []) {
                $clean[$key] = match ($f['type']) {
                    'number' => is_numeric($answers[$key]) ? $answers[$key] + 0 : $answers[$key],
                    'rating' => is_numeric($answers[$key]) ? (int) $answers[$key] : $answers[$key],
                    'multiselect' => array_values((array) $answers[$key]),
                    default => is_string($answers[$key]) ? trim($answers[$key]) : $answers[$key],
                };
            }
        }
        Validator::make(['answers' => $answers], $rules, [], $names)->validate();

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, true>  $seen  fields shown so far (a hidden field counts as empty)
     */
    public static function passes(mixed $cond, array $answers, array $seen): bool
    {
        if (! is_array($cond) || ! isset($cond['field'], $cond['op'])) {
            return true;
        }
        $field = (string) $cond['field'];
        $value = isset($seen[$field]) ? ($answers[$field] ?? null) : null;
        $filled = $value !== null && $value !== '' && $value !== [];
        $target = (string) ($cond['value'] ?? '');

        return match ((string) $cond['op']) {
            'filled' => $filled,
            'empty' => ! $filled,
            'neq' => ! $filled || (is_array($value) ? ! in_array($target, $value, true) : (string) $value !== $target),
            'includes' => is_array($value) && in_array($target, array_map('strval', $value), true),
            default => $filled && (is_array($value) ? in_array($target, array_map('strval', $value), true) : (string) $value === $target),
        };
    }

    /**
     * @param  list<string>  $earlier  keys of fields above this one
     * @return array{field: string, op: string, value: string|null}|null
     */
    private static function condition(mixed $raw, array $earlier): ?array
    {
        if (! is_array($raw) || empty($raw['field'])) {
            return null;
        }
        $field = (string) $raw['field'];
        $op = (string) ($raw['op'] ?? 'eq');
        if (! in_array($field, $earlier, true) || ! in_array($op, self::OPS, true)) {
            return null; // a condition can only depend on a field above it
        }

        return ['field' => $field, 'op' => $op, 'value' => isset($raw['value']) ? mb_substr((string) $raw['value'], 0, 80) : null];
    }
}
