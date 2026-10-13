<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday (Philippines by default). Regular and special non-working days
 * reduce capacity; special working days do not.
 *
 * @property int $id
 * @property CarbonInterface $date
 * @property string $name
 * @property string $kind regular | special | special_working
 */
#[Fillable(['date', 'name', 'kind'])]
class Holiday extends Model
{
    public const KINDS = [
        'regular' => 'Regular holiday',
        'special' => 'Special non-working day',
        'special_working' => 'Special working day',
    ];

    /** Kinds that are days off. */
    public const DAYS_OFF = ['regular', 'special'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
