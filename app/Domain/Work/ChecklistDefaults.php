<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\AppSettings;
use App\Models\Task;
use App\Models\User;

/**
 * E-25: the agency's definition of done per task type, used when a client has no checklist
 * of its own for that type. Copied onto each new task, so editing it changes new tasks only.
 */
class ChecklistDefaults
{
    public const KEY = 'checklist_defaults';

    /** Built-in starting point until an admin changes it. */
    public const BUILT_IN = [
        'story' => ['Tests written and passing', 'Docs or README updated', 'Accessibility checked (keyboard, contrast, labels)', 'Performance checked (no slow pages or queries)', 'Security review done (inputs, access, secrets)'],
        'bug' => ['Reproduced before fixing', 'A test covers the bug', 'Fix checked on staging'],
    ];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array<string, list<string>> task type => lines
     */
    public function all(): array
    {
        $saved = $this->settings->get(self::KEY);
        if (! is_array($saved)) {
            return self::BUILT_IN;
        }
        $out = [];
        foreach ($saved as $type => $lines) {
            if (array_key_exists((string) $type, Task::TYPES) && is_array($lines)) {
                $out[(string) $type] = array_values(array_map('strval', $lines));
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function for(string $type): array
    {
        return $this->all()[$type] ?? [];
    }

    /**
     * @param  array<string, list<string>>  $templates
     */
    public function save(array $templates, User $by): void
    {
        $this->settings->set(self::KEY, $templates);
        $this->activity->log('work.checklist-defaults-saved', null, ['types' => array_keys($templates)], $by);
    }
}
