<?php

declare(strict_types=1);

namespace Plugins\Voting\API\Contracts;

use Plugins\Voting\API\DTOs\BoostDTO;
use Plugins\Voting\API\DTOs\InitiateBoostDTO;

interface BoostingServiceContract
{
    public function initiate(InitiateBoostDTO $dto): BoostDTO;

    public function confirm(string $txRef): BoostDTO;
}
