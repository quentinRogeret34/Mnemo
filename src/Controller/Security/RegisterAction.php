<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\OAuth\OAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Email + password account registration. Accounts whose email is listed in APP_ADMIN_EMAILS are active super admins;
 * all others wait for a super admin to approve them on /admin/users.
 */
final class RegisterAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly OAuthClient $oauthClient,
        private readonly Security $security,
        #[Autowire(env: 'APP_ADMIN_EMAILS')]
        private readonly string $adminEmails,
    ) {
    }

    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $error = null;
        $values = ['name' => '', 'email' => ''];

        if ($request->isMethod('POST')) {
            $values['name'] = trim($request->request->getString('name'));
            $values['email'] = strtolower(trim($request->request->getString('email')));
            $password = $request->request->getString('password');
            $confirm = $request->request->getString('password_confirm');

            if (!$this->isCsrfTokenValid('register', $request->request->getString('_csrf_token'))) {
                $error = 'register.error.csrf';
            } elseif ('' === $values['name'] || mb_strlen($values['name']) > 150) {
                $error = 'register.error.name';
            } elseif ('' === $values['email'] || mb_strlen($values['email']) > 180 || \count($this->validator->validate($values['email'], [new Assert\Email()])) > 0) {
                $error = 'register.error.email';
            } elseif (mb_strlen($password) < 10) {
                $error = 'register.error.password_length';
            } elseif ($password !== $confirm) {
                $error = 'register.error.password_mismatch';
            } elseif (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $values['email']])) {
                $error = 'register.error.email_taken';
            } else {
                $user = new User($values['email'], $values['name'], 'password', $values['email']);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                if (\in_array($values['email'], array_map('trim', explode(',', strtolower($this->adminEmails))), true)) {
                    $user->setStatus(UserStatus::Active);
                    $user->setSuperAdmin(true);
                }
                $user->markLoggedIn($values['name']);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->security->login($user, 'form_login', 'main');

                return $this->redirectToRoute($user->isActive() ? 'dashboard' : 'pending');
            }
        }

        return $this->render('security/register.html.twig', [
            'providers' => $this->oauthClient->enabled(),
            'values' => $values,
            'error' => $error,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
