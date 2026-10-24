<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    /** @var Collection<int, UserRole> */
    #[ORM\ManyToMany(targetEntity: UserRole::class)]
    #[ORM\JoinTable(name: 'users_user_roles')]
    private Collection $userRoles;

    public function __construct()
    {
        $this->userRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Maps the stored role names ("admin", "editor") to Symfony roles.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = [];
        foreach ($this->userRoles as $userRole) {
            $name = $userRole->getName();
            $roles[] = str_starts_with($name, 'ROLE_') ? $name : 'ROLE_'.mb_strtoupper($name);
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
        // The plaintext password is never stored on the entity, nothing to clear.
    }

    /** @return Collection<int, UserRole> */
    public function getUserRoles(): Collection
    {
        return $this->userRoles;
    }

    public function addUserRole(UserRole $role): void
    {
        if (!$this->userRoles->contains($role)) {
            $this->userRoles->add($role);
        }
    }

    public function removeUserRole(UserRole $role): void
    {
        $this->userRoles->removeElement($role);
    }

    // EasyAdmin renders association selects through this.
    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
