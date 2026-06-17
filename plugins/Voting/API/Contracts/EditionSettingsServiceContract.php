<?php

declare(strict_types=1);

namespace Plugins\Voting\API\Contracts;

use Plugins\Voting\API\DTOs\EditionSettingsDTO;
use Plugins\Voting\API\DTOs\UpdateEditionSettingsDTO;

interface EditionSettingsServiceContract
{
    public function get(string $editionId): EditionSettingsDTO;

    public function update(string $editionId, UpdateEditionSettingsDTO $dto): EditionSettingsDTO;
}
