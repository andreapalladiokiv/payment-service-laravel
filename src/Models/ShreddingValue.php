<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property string $hash
 * @property string $value
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

    public function newUniqueId(): string
    {
        $this->value === null && throw new RuntimeException('value is required to generate id');

        return self::makeId($this->value);
    }

    protected static function makeId(string $value): string
    {
        return hash('sha256', $value);
    }

    public function getUpdatedAtColumn(): null
    {
        return null;
    }

    protected function isValidUniqueId($value): bool
    {
        return self::makeId($this->value) === $value;
    }
}
