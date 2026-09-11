<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;

final class BillableOrganization extends Model
{
    use Billable;

    protected $table = 'billing_organizations';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public function getForeignKey(): string
    {
        return 'billing_owner_uuid';
    }
}
