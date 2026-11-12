<?php

namespace App\Forge;

interface ForgeClient
{
    /**
     * Prefix of change request ids in the graph: "pr" or "mr".
     */
    public function changeRequestPrefix(): string;

    /**
     * Name of the repository as the forge knows it; fails when the repository or the token is wrong.
     */
    public function describe(): string;

    /**
     * Newest first.
     *
     * @return list<ForgeCommit>
     */
    public function commits(?\DateTimeImmutable $since, int $limit): array;

    /**
     * Most recently updated first.
     *
     * @return list<ForgeTicket>
     */
    public function changeRequests(?\DateTimeImmutable $since, int $limit): array;

    /**
     * Most recently updated first.
     *
     * @return list<ForgeTicket>
     */
    public function issues(?\DateTimeImmutable $since, int $limit): array;

    /**
     * Review comments of a change request, as plain text.
     */
    public function reviewText(int $number): string;
}
