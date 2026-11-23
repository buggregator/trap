<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Server\Event\Facets;

/**
 * The filter values of a Sentry feed event: the machine, environment, level, logger as the channel,
 * release, user and tags.
 *
 * @internal
 */
final class EventFacets
{
    /**
     * @return array<non-empty-string, list<non-empty-string>>
     */
    public static function of(array $payload): array
    {
        return (new Facets())
            ->add(Facets::HOST, $payload['server_name'] ?? null)
            ->add(Facets::ENV, $payload['environment'] ?? null)
            ->add(Facets::LEVEL, $payload['level'] ?? null)
            ->add(Facets::CHANNEL, $payload['logger'] ?? null)
            ->add(Facets::RELEASE, $payload['release'] ?? null)
            ->add(Facets::USER, self::user($payload['user'] ?? null))
            ->addTags($payload['tags'] ?? null)
            ->toArray();
    }

    /**
     * The first of the email, username, ID and IP address.
     */
    private static function user(mixed $user): mixed
    {
        if (!\is_array($user)) {
            return null;
        }

        foreach (['email', 'username', 'id', 'ip_address'] as $key) {
            if (\is_scalar($user[$key] ?? null) && (string) $user[$key] !== '') {
                return $user[$key];
            }
        }

        return null;
    }
}
