<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'unversioned_documents')]
class UnversionedDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}
