<?php

declare(strict_types=1);

namespace Maya\Profile\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base model for FDW-backed "applications" views.
 *
 * These views are read-only (no updated_at column exists on the FDW view)
 * and should never be mass-assigned to, hence the fully-guarded configuration.
 * Concrete subclasses may override $table to point at a different view name.
 */
abstract class ReadOnlyFdwApplication extends Model
{
    /** FDW views do not carry an updated_at column. */
    public const UPDATED_AT = null;

    /** Fully guarded — FDW views are read-only; mass-assignment must be blocked. */
    protected $guarded = ['*'];

    /** Default table; concrete subclasses may override. */
    protected $table = 'applications';

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
