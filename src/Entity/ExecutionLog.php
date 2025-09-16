<?php

namespace App\Entity;

use App\Repository\ExecutionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExecutionLogRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ExecutionLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $executedAt = null;

    #[ORM\ManyToOne(targetEntity: Info::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Info $info = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorOutput = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(\DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;

        return $this;
    }

    public function getInfo(): ?Info
    {
        return $this->info;
    }

    public function setInfo(?Info $info): static
    {
        $this->info = $info;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorOutput(): ?string
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(?string $errorOutput): static
    {
        $this->errorOutput = $errorOutput;

        return $this;
    }

    #[ORM\PrePersist]
    public function setExecutedAtValue(): void
    {
        if ($this->executedAt === null) {
            $this->executedAt = new \DateTimeImmutable();
        }
    }
}
