<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared task status (Backlog, To Do, In Progress, In Review, Blocked, Done).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $category open | active | blocked | done
 * @property string $color
 * @property int $position
 */
class TaskStatus extends Model
{
    public const CATEGORY_DONE = 'done';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'category', 'color', 'position'];

    /**
     * All statuses in board order, loaded once per request (only six rows).
     * Not put in the shared cache: Redis is configured to refuse unserializing objects.
     *
     * @return Collection<int, TaskStatus>
     */
    public static function ordered(): Collection
    {
        return once(fn () => TaskStatus::query()->orderBy('position')->get());
    }

    public static function idFor(string $slug): int
    {
        return (int) static::ordered()->firstWhere('slug', $slug)?->id;
    }

    public function isDone(): bool
    {
        return $this->category === self::CATEGORY_DONE;
    }
}
