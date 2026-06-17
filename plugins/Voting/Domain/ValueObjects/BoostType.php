<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum BoostType: string
{
    case Regular = 'regular';
    case Premium = 'premium';
}
