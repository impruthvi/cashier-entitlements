<?php

declare(strict_types=1);

arch('billing decisions depend on neither framework integrations nor ambient IO or time')
    ->expect('Impruthvi\CashierEntitlements\Billing')
    ->not->toUse([
        'Illuminate', 'Laravel', 'Stripe', 'LucaLongo', 'GuzzleHttp', 'PDO',
        'now', 'time', 'date', 'config', 'app', 'resolve',
        'curl_exec', 'file_get_contents', 'file_put_contents', 'fopen',
    ]);
