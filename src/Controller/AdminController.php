<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounts and groups, reserved to super admins by the access control on /admin.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $this->entityManager->getRepository(User::class)->findBy([], ['status' => 'DESC', 'name' => 'ASC']),
            'groups' => $this->entityManager->getRepository(UserGroup::class)->findBy([], ['name' => 'ASC']),
            'projects' => $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/users/{id}', name: 'admin_user_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateUser(User $user, Request $request): Response
    {
        $this->checkToken('admin-user-'.$user->getId(), $request);
        $payload = $request->getPayload();
        $status = UserStatus::tryFrom($payload->getString('status')) ?? $user->getStatus();
        $superAdmin = $payload->getBoolean('super_admin');

        if ($user === $this->getUser() && (UserStatus::Active !== $status || !$superAdmin)) {
            $this->addFlash('error', 'Un super admin ne se retire pas lui-même ses droits : un autre super admin doit le faire.');

            return $this->redirectToRoute('admin_users');
        }

        $user->setStatus($status);
        $user->setSuperAdmin($superAdmin);
        $chosen = array_map('intval', $payload->all('groups'));
        foreach ($this->entityManager->getRepository(UserGroup::class)->findAll() as $group) {
            $member = $group->getUsers()->contains($user);
            if (\in_array($group->getId(), $chosen, true) && !$member) {
                $group->getUsers()->add($user);
            } elseif (!\in_array($group->getId(), $chosen, true) && $member) {
                $group->getUsers()->removeElement($user);
            }
        }
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Compte de %s mis à jour.', $user->getName()));

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/groups', name: 'admin_group_create', methods: ['POST'])]
    public function createGroup(Request $request): Response
    {
        $this->checkToken('admin-group-create', $request);
        $name = trim($request->getPayload()->getString('name'));
        if ('' === $name || mb_strlen($name) > 100 || null !== $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => $name])) {
            $this->addFlash('error', 'Donner au groupe un nom unique de 100 caractères au plus.');

            return $this->redirectToRoute('admin_users');
        }
        $this->entityManager->persist(new UserGroup($name));
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Groupe « %s » créé.', $name));

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/groups/{id}', name: 'admin_group_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateGroup(UserGroup $group, Request $request): Response
    {
        $this->checkToken('admin-group-'.$group->getId(), $request);
        if ($request->getPayload()->has('delete')) {
            $this->entityManager->remove($group);
            $this->addFlash('success', \sprintf('Groupe « %s » supprimé. Ses mémoires de groupe ne sont plus visibles que des super admins.', $group->getName()));
        } else {
            $slugs = $request->getPayload()->all('projects');
            $group->setProjects([] === $slugs ? [] : $this->entityManager->getRepository(Project::class)->findBy(['slug' => $slugs]));
            $this->addFlash('success', \sprintf('Projets du groupe « %s » mis à jour.', $group->getName()));
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('admin_users');
    }

    private function checkToken(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
