<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

enum DecisionStatus: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Invalid = 'invalid';
}
