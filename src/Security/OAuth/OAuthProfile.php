<?php

namespace App\Security\OAuth;

final readonly class OAuthProfile
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $email,
        public string $name,
    ) {
    }
}
