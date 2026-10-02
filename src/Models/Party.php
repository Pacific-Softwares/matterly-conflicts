<?php

namespace Modules\MatterlyConflicts\Models;

use App\Models\Matter;
use App\Traits\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone connected to a matter who isn't the client: the other side, their lawyer, a witness... */
class Party extends Model
{
    use BelongsToFirm;

    public const ROLES = ['adverse', 'opposing_counsel', 'related', 'witness', 'other'];

    /** Roles that make a match a likely conflict, not just worth a look. */
    public const ADVERSE_ROLES = ['adverse', 'opposing_counsel'];

    protected $table = 'matterly_conflicts_parties';

    protected $fillable = ['firm_id', 'matter_id', 'name', 'aliases', 'role', 'notes', 'created_by'];

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class)->withoutGlobalScope('firm');
    }
}
