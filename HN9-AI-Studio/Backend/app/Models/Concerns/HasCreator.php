<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds a `creator` association for models that carry a `user_id` owner column.
 * Complements the existing domain-specific `user()` relationships with a
 * uniform accessor and ownership check used by policies/services.
 *
 * @mixin Model
 */
trait HasCreator
{
    /**
     * The user that created/owns this record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the given user owns this record.
     */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->getAttribute('user_id') === $user->getKey();
    }
}
