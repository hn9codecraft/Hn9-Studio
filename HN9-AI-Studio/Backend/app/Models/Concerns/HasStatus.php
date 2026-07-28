<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Uniform status inspection for models with a string `status` column. Provides
 * read helpers only — status *mutation* and querying live in services and
 * repositories respectively, keeping business logic out of the model.
 *
 * @mixin Model
 */
trait HasStatus
{
    /**
     * The raw status value, or null.
     */
    public function statusValue(): ?string
    {
        $status = $this->getAttribute('status');

        return $status === null ? null : (string) $status;
    }

    /**
     * Whether the record is in the given status.
     */
    public function isStatus(string|BackedEnum $status): bool
    {
        $value = $status instanceof BackedEnum ? (string) $status->value : $status;

        return $this->statusValue() === $value;
    }

    /**
     * Whether the record is in any of the given statuses.
     *
     * @param  list<string|BackedEnum>  $statuses
     */
    public function isAnyStatus(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if ($this->isStatus($status)) {
                return true;
            }
        }

        return false;
    }
}
