<?php

namespace Modules\MatterlyConflicts\Models;

use App\Models\User;
use App\Traits\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the firm's conflict log. */
class Check extends Model
{
    use BelongsToFirm;

    /** pending: flagged automatically, still needs a decision. */
    public const OUTCOMES = ['clear', 'conflict', 'waived', 'pending'];

    protected $table = 'matterly_conflicts_checks';

    protected $fillable = ['firm_id', 'user_id', 'client_id', 'query', 'outcome', 'source', 'note', 'hits', 'hit_count', 'resolved_by', 'resolved_at'];

    protected $casts = ['hits' => 'array', 'resolved_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
