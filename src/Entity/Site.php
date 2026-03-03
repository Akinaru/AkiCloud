<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Site
{
    public const SOURCE_GIT_PUBLIC = 'git_public';
    public const SOURCE_LOCAL_VOLUME = 'local_volume';

    public const STATUS_BUILDING = 'building';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_STARTING = 'starting';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_RESTARTING = 'restarting';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?int $port = null;

    #[ORM\Column(length: 255)]
    private ?string $subdomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gitRepository = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publishDirectory = null;

    #[ORM\Column(length: 30)]
    private string $deploymentSource = self::SOURCE_GIT_PUBLIC;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $localVolumePath = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $createDatabase = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coolifyUuid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dbName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dbUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dbPassword = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dbHost = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ownerFirstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ownerLastname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ownerEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wpAdminUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wpAdminPassword = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wpAdminEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wpUsmbAdminUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wpUsmbAdminPassword = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $wpConfigured = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isProtected = false;

    #[ORM\ManyToOne]
    private ?EmailTemplate $pendingEmailTemplate = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'accessibleSites')]
    #[ORM\JoinTable(name: 'site_user_access')]
    private Collection $authorizedUsers;

    public function __construct()
    {
        $this->authorizedUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->generateSubdomain();

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function generateSubdomain(): void
    {
        if ($this->name) {
            $slugger = new AsciiSlugger();
            $this->subdomain = strtolower($slugger->slug($this->name));
        }
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(?int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): static
    {
        $this->subdomain = $subdomain;

        return $this;
    }

    public function getGitRepository(): ?string
    {
        return $this->gitRepository;
    }

    public function setGitRepository(?string $gitRepository): static
    {
        $this->gitRepository = $gitRepository;

        return $this;
    }

    public function getPublishDirectory(): ?string
    {
        return $this->publishDirectory;
    }

    public function setPublishDirectory(?string $publishDirectory): static
    {
        $this->publishDirectory = $publishDirectory;

        return $this;
    }

    public function getCoolifyUuid(): ?string
    {
        return $this->coolifyUuid;
    }

    public function setCoolifyUuid(?string $coolifyUuid): static
    {
        $this->coolifyUuid = $coolifyUuid;

        return $this;
    }

    public function getDbName(): ?string
    {
        return $this->dbName;
    }

    public function setDbName(?string $dbName): static
    {
        $this->dbName = $dbName;

        return $this;
    }

    public function getDbUser(): ?string
    {
        return $this->dbUser;
    }

    public function setDbUser(?string $dbUser): static
    {
        $this->dbUser = $dbUser;

        return $this;
    }

    public function getDbPassword(): ?string
    {
        return $this->dbPassword;
    }

    public function setDbPassword(?string $dbPassword): static
    {
        $this->dbPassword = $dbPassword;

        return $this;
    }

    public function getDbHost(): ?string
    {
        return $this->dbHost;
    }

    public function setDbHost(?string $dbHost): static
    {
        $this->dbHost = $dbHost;

        return $this;
    }

    public function getFullUrl(string $baseDomain = 'cloud.fac-info.fr'): string
    {
        if ($this->customDomain) {
            return $this->normalizeHost($this->customDomain);
        }

        if (!$this->subdomain) {
            return '';
        }

        return $this->subdomain . '.' . $baseDomain;
    }

    public function getDeploymentSource(): string
    {
        return $this->deploymentSource;
    }

    public function setDeploymentSource(string $deploymentSource): static
    {
        $this->deploymentSource = $deploymentSource;

        return $this;
    }

    public function getCustomDomain(): ?string
    {
        return $this->customDomain;
    }

    public function setCustomDomain(?string $customDomain): static
    {
        $this->customDomain = $this->normalizeHost($customDomain);

        return $this;
    }

    public function getLocalVolumePath(): ?string
    {
        return $this->localVolumePath;
    }

    public function setLocalVolumePath(?string $localVolumePath): static
    {
        $this->localVolumePath = $localVolumePath;

        return $this;
    }

    public function isCreateDatabase(): bool
    {
        return $this->createDatabase;
    }

    public function setCreateDatabase(bool $createDatabase): static
    {
        $this->createDatabase = $createDatabase;

        return $this;
    }

    private function normalizeHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $value = trim($host);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#/.*$#', '', $value) ?? $value;
        $value = trim($value, '.');

        return $value !== '' ? mb_strtolower($value) : null;
    }

    public function getOwnerFirstname(): ?string
    {
        return $this->ownerFirstname;
    }

    public function setOwnerFirstname(?string $ownerFirstname): static
    {
        $this->ownerFirstname = $ownerFirstname;

        return $this;
    }

    public function getOwnerLastname(): ?string
    {
        return $this->ownerLastname;
    }

    public function setOwnerLastname(?string $ownerLastname): static
    {
        $this->ownerLastname = $ownerLastname;

        return $this;
    }

    public function getOwnerEmail(): ?string
    {
        return $this->ownerEmail;
    }

    public function setOwnerEmail(?string $ownerEmail): static
    {
        $this->ownerEmail = $ownerEmail;

        return $this;
    }

    public function getPendingEmailTemplate(): ?EmailTemplate
    {
        return $this->pendingEmailTemplate;
    }

    public function setPendingEmailTemplate(?EmailTemplate $pendingEmailTemplate): static
    {
        $this->pendingEmailTemplate = $pendingEmailTemplate;

        return $this;
    }

    public function getWpAdminUser(): ?string
    {
        return $this->wpAdminUser;
    }

    public function setWpAdminUser(?string $wpAdminUser): static
    {
        $this->wpAdminUser = $wpAdminUser;

        return $this;
    }

    public function getWpAdminPassword(): ?string
    {
        return $this->wpAdminPassword;
    }

    public function setWpAdminPassword(?string $wpAdminPassword): static
    {
        $this->wpAdminPassword = $wpAdminPassword;

        return $this;
    }

    public function getWpAdminEmail(): ?string
    {
        return $this->wpAdminEmail;
    }

    public function setWpAdminEmail(?string $wpAdminEmail): static
    {
        $this->wpAdminEmail = $wpAdminEmail;

        return $this;
    }

    public function isWpConfigured(): bool
    {
        return $this->wpConfigured;
    }

    public function setWpConfigured(bool $wpConfigured): static
    {
        $this->wpConfigured = $wpConfigured;

        return $this;
    }

    public function isProtected(): bool
    {
        return $this->isProtected;
    }

    public function setIsProtected(bool $isProtected): static
    {
        $this->isProtected = $isProtected;

        return $this;
    }

    public function getWpUsmbAdminUser(): ?string
    {
        return $this->wpUsmbAdminUser;
    }

    public function setWpUsmbAdminUser(?string $wpUsmbAdminUser): static
    {
        $this->wpUsmbAdminUser = $wpUsmbAdminUser;

        return $this;
    }

    public function getWpUsmbAdminPassword(): ?string
    {
        return $this->wpUsmbAdminPassword;
    }

    public function setWpUsmbAdminPassword(?string $wpUsmbAdminPassword): static
    {
        $this->wpUsmbAdminPassword = $wpUsmbAdminPassword;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAuthorizedUsers(): Collection
    {
        return $this->authorizedUsers;
    }

    public function addAuthorizedUser(User $user): static
    {
        if (!$this->authorizedUsers->contains($user)) {
            $this->authorizedUsers->add($user);
        }

        return $this;
    }

    public function removeAuthorizedUser(User $user): static
    {
        $this->authorizedUsers->removeElement($user);

        return $this;
    }

    public function isUserAuthorized(User $user): bool
    {
        return $this->authorizedUsers->contains($user);
    }
}
