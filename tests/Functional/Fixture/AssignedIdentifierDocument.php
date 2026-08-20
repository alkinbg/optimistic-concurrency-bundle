<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assigned_identifier_documents')]
class AssignedIdentifierDocument
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
