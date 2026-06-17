<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum TransactionType: string
{
    case Subscription = 'subscription';
    case Boosting     = 'boosting';
}
