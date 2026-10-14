<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer\Sentry;

use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class Header
{
    public static function renderMessageHeader(OutputInterface $output, array $message): void
    {
        // Collect metadata
        $meta = [];
        /** @var mixed $timeValue */
        $timeValue = $message['sent_at'] ?? $message['timestamp'] ?? 'now';
        try {
            $time = new \DateTimeImmutable(\is_numeric($timeValue) ? "@$timeValue" : (string) $timeValue);
        } catch (\Throwable) {
            $time = new \DateTimeImmutable();
        }
        $meta['Time'] = $time;
        isset($message['event_id']) and $meta['Event ID'] = (string) $message['event_id'];
        isset($message['transaction']) and $meta['Transaction'] = (string) $message['transaction'];
        isset($message['server_name']) and $meta['Server'] = (string) $message['server_name'];

        // Metadata from context
        if (isset($message['contexts']) && \is_array($message['contexts'])) {
            $context = $message['contexts'];
            isset($context['runtime']) and $meta['Runtime'] = \implode(' ', \array_map(\strval(...), (array) $context['runtime']));
            isset($context['os']) and $meta['OS'] = \implode(' ', \array_map(\strval(...), (array) $context['os']));
        }
        isset($message['sdk']) and $meta['SDK'] = \implode(' ', \array_map(
            static fn(mixed $value): string => \is_scalar($value) ? (string) $value : Json::encode($value),
            (array) $message['sdk'],
        ));

        Common::renderMetadata($output, $meta);

        // Render short content values as tags
        $tags = self::pullTagsFromMessage($message, [
            'level' => 'level',
            'platform' => 'platform',
            'environment' => 'env',
            'logger' => 'logger',
        ]);
        if ($tags !== []) {
            $output->writeln('');
            Common::renderTags($output, $tags);
        }

        // Render tags
        /** @var array<array-key, string> $tags Sentry SDKs send tag values as strings */
        $tags = isset($message['tags']) && \is_array($message['tags']) ? $message['tags'] : [];
        if ($tags !== []) {
            Common::renderHeader2($output, 'Tags');
            Common::renderTags($output, $tags);
        }
    }

    /**
     * Collect tags from message fields
     *
     * @param array<array-key, mixed> $message
     * @param array<string, string> $tags Key => Alias
     *
     * @return array<string, string>
     */
    private static function pullTagsFromMessage(array $message, array $tags): array
    {
        $result = [];
        foreach ($tags as $key => $alias) {
            if (isset($message[$key]) && \is_string($message[$key])) {
                $result[$alias] ??= \implode(' ', (array) ($message[$key]));
            }
        }

        return $result;
    }
}
