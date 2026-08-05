<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Override;
use RuntimeException;

/**
 * @property string $hash
 * @property string|null $value an instance that has not been assigned one yet has none,
 *   which is what newUniqueId() guards against
 * @property CarbonImmutable|null $created_at
 */
final class ShreddingValue extends Model
{
    use HasUniqueStringIds;

    protected $table = 'shredding_values';

    protected $primaryKey = 'hash';

    protected $fillable = ['value', 'created_at'];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    #[Override]
    public function newUniqueId(): string
    {
        // Bound rather than guarded in place: `$expr && throw` reads as an assertion but
        // narrows nothing, so the call below still received a possibly-null property.
        $value = $this->value
            ?? throw new RuntimeException('A shredding value needs its plaintext before an id can be derived from it.');

        return self::makeId($value);
    }

    protected static function makeId(string $value): string
    {
        return hash('sha256', $value);
    }

    #[Override]
    public function getUpdatedAtColumn(): null
    {
        return null;
    }

    #[Override]
    protected function isValidUniqueId($value): bool
    {
        // No plaintext, no derivable id, so nothing can match: answering false lets the
        // caller generate one instead of deriving an id from nothing.
        if ($this->value === null) {
            return false;
        }

        return self::makeId($this->value) === $value;
    }
}
