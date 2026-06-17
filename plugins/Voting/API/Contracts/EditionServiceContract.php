<?php

declare(strict_types=1);

namespace Plugins\Voting\API\Contracts;

use Plugins\Voting\API\DTOs\AddContestantDTO;
use Plugins\Voting\API\DTOs\ContestantDTO;
use Plugins\Voting\API\DTOs\CreateEditionDTO;
use Plugins\Voting\API\DTOs\EditionDTO;
use Plugins\Voting\API\DTOs\UpdateEditionDTO;

interface EditionServiceContract
{
    /** @return list<EditionDTO> */
    public function list(): array;

    public function find(string $id): ?EditionDTO;

    public function create(CreateEditionDTO $dto): EditionDTO;

    public function update(string $id, UpdateEditionDTO $dto): EditionDTO;

    public function activate(string $id): EditionDTO;

    public function close(string $id): EditionDTO;

    public function addContestant(string $editionId, AddContestantDTO $dto): ContestantDTO;

    public function removeContestant(string $contestantId): bool;
}
