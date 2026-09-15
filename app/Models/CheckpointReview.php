<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only HR follow-up record on one employee's checkpoint response. */
class CheckpointReview extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'explanation_recorded' => 'Explanation recorded',
        'note_added' => 'HR note added',
        'marked_for_review' => 'Marked for review',
        'approved' => 'Exception approved',
        'rejected' => 'Exception rejected',
        'escalated' => 'Escalated to management',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }
}
