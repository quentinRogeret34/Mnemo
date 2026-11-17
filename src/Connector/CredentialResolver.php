<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Security\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CredentialResolver
{
    /**
     * Shown in the form instead of a value stored encrypted; submitted back unchanged, it keeps that value.
     */
    public const MASK = '••••••';
    private const SEALED = 'secret:';

    /**
     * @param list<string> $allowedEnv
     */
    public function __construct(
        #[Autowire(env: 'csv:MCP_CONNECTORS_ENV')]
        private readonly array $allowedEnv,
        private readonly SecretBox $secretBox,
    ) {
    }

    /**
     * Headers a forge connector sends. The token of an account connected by OAuth wins over the typed credentials,
     * and only ever goes to github.com: the endpoint of a connector can be edited after the account was connected.
     *
     * @return array<string, string>
     */
    public function forgeHeaders(Connector $connector): array
    {
        if (null !== $connector->getOauthToken() && 'api.github.com' === parse_url($connector->getEndpoint(), \PHP_URL_HOST)) {
            return ['Authorization' => 'Bearer '.$this->secretBox->decrypt($connector->getOauthToken())];
        }

        return $this->resolve($connector->getCredentials());
    }

    /**
     * Encrypts the values typed in clear before they are stored, so that a token can be pasted in the form.
     * A value reading "env:NAME" stays as typed: the secret lives in the environment.
     *
     * @param array<string, string> $values
     * @param array<string, string> $previous the values stored before the form was submitted
     *
     * @return array<string, string>
     */
    public function seal(array $values, array $previous): array
    {
        $sealed = [];
        foreach ($values as $name => $value) {
            $sealed[$name] = match (true) {
                self::MASK === $value => $previous[$name] ?? '',
                str_contains($value, 'env:') => $value,
                default => self::SEALED.$this->secretBox->encrypt($value),
            };
        }

        return array_filter($sealed, static fn (string $value): bool => '' !== $value);
    }

    public static function display(string $value): string
    {
        return str_starts_with($value, self::SEALED) ? self::MASK : $value;
    }

    /**
     * Decrypts the values stored encrypted, and replaces every "env:NAME" in the others, so that "Bearer env:GITHUB_TOKEN" works as well as "env:TOKEN".
     * Only the variables listed in MCP_CONNECTORS_ENV can be read: a connector sends them to an endpoint anyone can edit.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public function resolve(array $values): array
    {
        return array_map(fn (string $value): string => str_starts_with($value, self::SEALED) ? $this->secretBox->decrypt(substr($value, \strlen(self::SEALED))) : preg_replace_callback('/env:([A-Za-z_][A-Za-z0-9_]*)/', function (array $match): string {
            if (!\in_array($match[1], $this->allowedEnv, true)) {
                throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas autorisée. L\'ajouter à MCP_CONNECTORS_ENV dans .env.local.', $match[1]));
            }
            $resolved = $_SERVER[$match[1]] ?? $_ENV[$match[1]] ?? getenv($match[1]);

            return \is_string($resolved) && '' !== $resolved ? $resolved : throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas définie.', $match[1]));
        }, $value), $values);
    }
}
