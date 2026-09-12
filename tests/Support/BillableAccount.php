<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;

/** Masterix's published schema declares `morphs('subscriber')`, so its owners need integer keys. */
final class BillableAccount extends Model
{
    use Billable;

    protected $table = 'billing_accounts';

    protected $guarded = [];

    public function getForeignKey(): string
    {
        return 'billing_owner_uuid';
    }
}
