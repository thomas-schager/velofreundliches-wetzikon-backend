<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Server-issued arithmetic challenge for the public report form's "Sicherheitsfrage". The client
 * must not be trusted to supply its own operands/answer -- that would let a bot just submit a
 * self-consistent pair (e.g. a=1, b=1, answer=2) and always pass -- so this generates the pair and
 * remembers only the expected answer, keyed by the client's X-Challenge-Token, in the app cache
 * (no DB table needed; entries expire on their own via TTL).
 */
class CaptchaChallengeService
{
    private const TTL_SECONDS = 1200; // 20 minutes
    private const CACHE_KEY_PREFIX = 'captcha_challenge_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{a: int, b: int, ttlSeconds: int} ttlSeconds is echoed back so the frontend can
     *     state the real expiry window in its "please solve it again" message without hardcoding
     *     a second copy of this constant that could silently drift out of sync with it.
     */
    public function issue(string $token): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);

        $item = $this->cache->getItem($this->cacheKey($token));
        $item->set($a + $b);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return ['a' => $a, 'b' => $b, 'ttlSeconds' => self::TTL_SECONDS];
    }

    /**
     * Single-use: the stored answer is consumed (deleted) on the first verification attempt
     * regardless of outcome, so the same challenge can't be brute-forced.
     */
    public function verify(?string $token, mixed $submittedAnswer): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $key = $this->cacheKey($token);
        $item = $this->cache->getItem($key);
        $this->cache->deleteItem($key);

        if (!$item->isHit()) {
            return false;
        }

        $expected = $item->get();
        $submitted = filter_var($submittedAnswer, FILTER_VALIDATE_INT);

        return $submitted !== false && $submitted === $expected;
    }

    private function cacheKey(string $token): string
    {
        // Hashed rather than used raw -- PSR-6 restricts cache key characters, and the token is
        // client-supplied so its exact charset isn't guaranteed (see the Date.now()+Math.random()
        // fallback in velomelder-gelbes-band.html for browsers without crypto.randomUUID).
        return self::CACHE_KEY_PREFIX . hash('sha256', $token);
    }
}
