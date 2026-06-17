<?php

declare(strict_types=1);

namespace Plugins\Voting\API\Contracts;

use Plugins\Voting\API\DTOs\SubscribeDTO;
use Plugins\Voting\API\DTOs\SubscriptionDTO;

interface SubscriptionServiceContract
{
    public function get(string $editionId): SubscriptionDTO;

    public function subscribe(SubscribeDTO $dto): SubscriptionDTO;

    public function confirm(string $txRef): SubscriptionDTO;
}
