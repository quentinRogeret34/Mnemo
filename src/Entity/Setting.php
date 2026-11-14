<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A setting of the whole instance changed from the interface: it wins over the environment variable it replaces.
 */
#[ORM\Entity]
class Setting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(type: Types::TEXT)]
        private string $value,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
