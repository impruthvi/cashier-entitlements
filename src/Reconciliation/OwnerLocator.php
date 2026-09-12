<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Laravel\Cashier\Cashier;

final readonly class OwnerLocator
{
    public function __construct(private Connection $connection, private string $context, private bool $liveMode) {}

    public function reference(Model $model): OwnerReference
    {
        $key = $model->getKey();
        $connection = $model->getConnection()->getName();
        if (! $model->exists || (! is_string($key) && ! is_int($key)) || ! is_string($connection)) {
            throw new ReadFailure('invalid_owner');
        }
        $reference = new OwnerReference($model->getMorphClass(), $key, $connection, $this->context, $this->liveMode);
        $this->validate($reference);

        return $reference;
    }

    public function find(OwnerReference $owner, bool $lock = false): Model
    {
        $class = $this->validate($owner);
        $query = (new $class)->setConnection($owner->connection)->newQuery()->whereKey($owner->key);
        $model = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($model === null || (string) $model->getKey() !== $owner->key) {
            throw new ReadFailure('unknown_owner');
        }

        return $model;
    }

    public function customer(Model $owner): ?string
    {
        $customer = $owner->getAttribute('stripe_id');
        if ($customer === null) {
            return null;
        }
        if (! is_string($customer) || trim($customer) === ''
            || $owner->newQueryWithoutScopes()->where('stripe_id', $customer)->count() !== 1) {
            throw new ReadFailure('ambiguous_customer');
        }

        return $customer;
    }

    /** @return class-string<Model> */
    public function validate(OwnerReference $owner): string
    {
        if (! $owner->isValid() || $owner->connection !== $this->connection->getName()
            || $owner->providerContext !== $this->context || $owner->liveMode !== $this->liveMode) {
            throw new ReadFailure('owner_context_mismatch');
        }

        return $this->modelFor($owner->type);
    }

    /**
     * Resolve a registered morph alias without needing a specific owner.
     *
     * @return class-string<Model>
     */
    public function modelFor(string $alias): string
    {
        $class = Relation::getMorphedModel($alias);
        if (! class_exists(Cashier::class) || $class === null || $class !== Cashier::$customerModel || ! is_subclass_of($class, Model::class)) {
            throw new ReadFailure('unregistered_owner_type');
        }

        return $class;
    }
}
