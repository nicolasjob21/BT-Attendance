<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
        ];
    }

    public function label(): string
    {
        return $this->period_start->format('M j') . ' – ' . $this->period_end->format('M j, Y');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
