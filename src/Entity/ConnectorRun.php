<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One synchronisation of a connector, kept for the activity screen.
 */
#[ORM\Entity]
#[ORM\Index(name: 'connector_run_started_idx', columns: ['started_at'])]
class ConnectorRun
{
    public const MANUAL = 'manual';
    public const SCHEDULE = 'schedule';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $finishedAt;

    /**
     * @param array<string, mixed> $report
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Connector $connector,
        #[ORM\Column(name: 'triggered_by', length: 20)]
        private string $trigger,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
        #[ORM\Column(type: Types::JSON)]
        private array $report,
    ) {
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnector(): Connector
    {
        return $this->connector;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): \DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return $this->report;
    }
}
