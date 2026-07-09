<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
            'employee_rate' => 'decimal:4',
            'employer_rate' => 'decimal:4',
            'ec_amount' => 'decimal:2',
        ];
    }

    /**
     * Find the applicable bracket for a contribution type, salary and year.
     *
     * Salaries above the highest ceiling fall back to the top bracket, and
     * salaries below the lowest floor fall back to the bottom bracket, so the
     * base is simply clamped to the schedule's floor/ceiling by the caller.
     */
    public static function forSalary(string $type, float $salary, int $year): ?self
    {
        $brackets = static::where('contribution_type', $type)
            ->where('effective_year', $year)
            ->orderBy('min_salary')
            ->get();

        if ($brackets->isEmpty()) {
            return null;
        }

        $match = $brackets->first(function ($b) use ($salary) {
            return $salary >= (float) $b->min_salary
                && ($b->max_salary === null || $salary <= (float) $b->max_salary);
        });

        // Above the highest ceiling -> top bracket; below the lowest floor -> bottom bracket.
        return $match ?? ($salary > (float) $brackets->last()->min_salary
            ? $brackets->last()
            : $brackets->first());
    }
}
