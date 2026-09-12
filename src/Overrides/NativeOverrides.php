<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Overrides;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use InvalidArgumentException;

/** Append-only grant/revoke ledger. Application code owns actor authentication and authorization. */
final readonly class NativeOverrides
{
    public function __construct(private NativeStateStore $store, private PriceCatalog $catalog) {}

    public function grant(OwnerReference $owner, string $feature, bool|int|null $allowance, string $reason, string $actor, DateTimeImmutable $effectiveAt, DateTimeImmutable $expiresAt): int
    {
        // An override that disagrees with the catalog would resolve into an unusable grant, so reject it at the source.
        if ($this->catalog->booleanFeature($feature) !== is_bool($allowance)) {
            throw new FeatureTypeMismatch('override_type_mismatch');
        }
        if ((is_int($allowance) && $allowance < 0) || $expiresAt <= $effectiveAt
            || trim($reason) === '' || trim($actor) === '') {
            throw new InvalidArgumentException('invalid_override_grant');
        }

        return $this->store->synchronized($owner, function (Connection $db, string $ownerId) use ($feature, $allowance, $reason, $actor, $effectiveAt, $expiresAt): int {
            return $db->table('cashier_entitlement_overrides')->insertGetId([
                'owner_id' => $ownerId, 'feature' => $feature, 'kind' => 'grant', 'target_id' => null,
                'allowance' => json_encode($allowance, JSON_THROW_ON_ERROR), 'reason' => $reason, 'actor' => $actor,
                'effective_at' => $this->time($effectiveAt), 'expires_at' => $this->time($expiresAt),
                'recorded_at' => $this->time(Date::now()->toDateTimeImmutable()),
            ]);
        });
    }

    public function revoke(OwnerReference $owner, int $grantId, string $reason, string $actor, DateTimeImmutable $effectiveAt): int
    {
        if (trim($reason) === '' || trim($actor) === '') {
            throw new InvalidArgumentException('invalid_override_revocation');
        }

        return $this->store->synchronized($owner, function (Connection $db, string $ownerId) use ($grantId, $reason, $actor, $effectiveAt): int {
            $grant = $db->table('cashier_entitlement_overrides')->where('owner_id', $ownerId)->where('id', $grantId)->where('kind', 'grant')->first();
            if ($grant === null) {
                throw new InvalidArgumentException('override_grant_not_found');
            }

            return $db->table('cashier_entitlement_overrides')->insertGetId([
                'owner_id' => $ownerId, 'feature' => $grant->feature, 'kind' => 'revoke', 'target_id' => $grantId,
                'allowance' => null, 'reason' => $reason, 'actor' => $actor,
                'effective_at' => $this->time($effectiveAt), 'expires_at' => null,
                'recorded_at' => $this->time(Date::now()->toDateTimeImmutable()),
            ]);
        });
    }

    /** @return array<string, bool|int|null> */
    public function values(OwnerReference $owner, DateTimeImmutable $at): array
    {
        $time = $this->time($at);
        $rows = $this->store->database($owner)->table('cashier_entitlement_overrides as grants')
            ->where('grants.owner_id', $this->store->ownerId($owner))->where('grants.kind', 'grant')
            ->where('grants.effective_at', '<=', $time)->where('grants.expires_at', '>', $time)
            ->whereNotExists(function (Builder $query) use ($time) {
                $query->selectRaw('1')->from('cashier_entitlement_overrides as revokes')
                    ->whereColumn('revokes.target_id', 'grants.id')->whereColumn('revokes.owner_id', 'grants.owner_id')
                    ->where('revokes.kind', 'revoke')->where('revokes.effective_at', '<=', $time);
            })->orderByDesc('grants.effective_at')->orderByDesc('grants.id')->get();
        $values = [];
        foreach ($rows as $row) {
            if (! array_key_exists($row->feature, $values)) {
                $values[$row->feature] = json_decode((string) $row->allowance, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> Immutable events in recorded order, with bounded cursor pagination. */
    public function history(OwnerReference $owner, int $afterId = 0, int $limit = 100): array
    {
        if ($afterId < 0 || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('invalid_history_page');
        }

        $events = [];
        $rows = $this->store->database($owner)->table('cashier_entitlement_overrides')->where('owner_id', $this->store->ownerId($owner))
            ->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $events[] = [
                ...(array) $row, 'id' => (int) $row->id, 'target_id' => $row->target_id === null ? null : (int) $row->target_id,
                'allowance' => $row->kind === 'grant' ? json_decode((string) $row->allowance, true, flags: JSON_THROW_ON_ERROR) : null,
            ];
        }

        return $events;
    }

    private function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
