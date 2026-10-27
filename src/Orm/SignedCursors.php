<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Cursors signed with a key of their own and bound to a string the caller
 * names: what a cursor handed to one reader opens for, and no other.
 *
 * A cursor carries the key of the row it starts from, which way it reads,
 * a keyed hash of the query it was made for, a keyed hash of the binding,
 * and an expiry -- all under a MAC. Never a row's values: a page sorted by
 * a column it does not show would hand that column to the client, a value
 * at a time (ADR 0057). The server reads the anchor's values again, under
 * the reader's own scope, when the cursor comes back.
 *
 * The binding is the caller's to choose. The framework's page cursors bind
 * to what its route tokens do -- the session, the user and their auth
 * epoch, the tenant claim, the panel, the build -- so a cursor copied into
 * another session, or kept across a sign-out, a role change or a deploy,
 * opens to nothing.
 */
final readonly class SignedCursors implements Cursors
{
    /** The purpose a cursor signer's key is derived for: Signer keys one key per purpose. */
    public const string PURPOSE = 'orm-cursor';

    /** How long a cursor opens for, in seconds. */
    public const int DEFAULT_TTL = 3600;

    /** Seconds the server opening a cursor may be behind the one that sealed it. */
    public const int CLOCK_SKEW = 60;

    /** The longest cursor read at all: what a sealed one is, with room to spare. */
    public const int MAX_LENGTH = 512;

    private string $binding;

    /**
     * @param Signer $signer a signer for PURPOSE, whose key no other token shares
     * @param string $binding what the cursor is bound to; kept only as a keyed hash
     * @param (\Closure(): int)|null $clock the time now, for a test
     */
    public function __construct(
        private Signer $signer,
        #[\SensitiveParameter]
        string $binding,
        private int $ttl = self::DEFAULT_TTL,
        private ?\Closure $clock = null,
    ) {
        if ($ttl < 1 || $ttl > 86_400) {
            throw new \InvalidArgumentException('A cursor opens for between a second and a day.');
        }

        $this->binding = $this->hash('binding', $binding);
    }

    public function seal(string $query, string $anchor, bool $after): string
    {
        $payload = json_encode([
            'q' => $this->hash('query', $query),
            'a' => $anchor,
            'f' => $after ? 1 : 0,
            'b' => $this->binding,
            'e' => $this->now() + $this->ttl,
        ], JSON_THROW_ON_ERROR);

        return Signer::encode($payload) . '.' . Signer::encode($this->signer->sign('cursor:' . $payload));
    }

    public function open(string $query, string $cursor): ?array
    {
        if (\strlen($cursor) > self::MAX_LENGTH) {
            return null;
        }

        $parts = explode('.', $cursor);
        if (\count($parts) !== 2) {
            return null;
        }

        $payload = Signer::decode($parts[0]);
        $mac = Signer::decode($parts[1]);
        if ($payload === null || $mac === null || !$this->signer->verify('cursor:' . $payload, $mac)) {
            return null;
        }

        try {
            $claims = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($claims)
            || !\is_string($claims['q'] ?? null) || !\is_string($claims['a'] ?? null) || !\is_string($claims['b'] ?? null)
            || !\in_array($claims['f'] ?? null, [0, 1], true) || !\is_int($claims['e'] ?? null)) {
            return null;
        }

        $now = $this->now();
        if (!hash_equals($this->binding, $claims['b'])
            || !hash_equals($this->hash('query', $query), $claims['q'])
            || $claims['e'] <= $now
            || $claims['e'] - $now > $this->ttl + self::CLOCK_SKEW
            || !Uuid::isValid($claims['a'])) {
            return null;
        }

        return [$claims['a'], $claims['f'] === 1];
    }

    /** A keyed hash: neither the binding nor the query can be read back out, or matched without the key. */
    private function hash(string $what, #[\SensitiveParameter] string $value): string
    {
        return bin2hex(substr($this->signer->sign("{$what}:{$value}"), 0, 16));
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
