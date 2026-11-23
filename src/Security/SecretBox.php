<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the secrets kept in the database (tokens obtained by OAuth) with a key derived from APP_SECRET.
 */
final class SecretBox
{
    private readonly string $key;
    private readonly bool $hasSecret;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        string $secret,
    ) {
        $this->hasSecret = '' !== $secret;
        $this->key = sodium_crypto_generichash($secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $value): string
    {
        if (!$this->hasSecret) {
            throw new \LogicException('APP_SECRET doit être défini pour chiffrer des secrets.');
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($value, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $bytes = (string) base64_decode($encrypted, true);
        $value = \strlen($bytes) > \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(substr($bytes, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key)
            : false;

        return false === $value ? throw new \RuntimeException('Jeton OAuth illisible (APP_SECRET a changé ?) : reconnecter le compte sur la page du connecteur.') : $value;
    }
}
