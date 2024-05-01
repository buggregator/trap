<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\Config;

use Buggregator\Trap\Logger;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @internal
 */
final class ConfigLoader
{
    private \SimpleXMLElement|null $xml = null;

    /**
     * @param null|callable(): non-empty-string $xmlProvider
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    public function __construct(
        private Logger $logger,
        private ?InputInterface $cliInput,
        ?callable $xmlProvider = null,
    )
    {
        // Check SimpleXML extension
        if (!\extension_loaded('simplexml')) {
            return;
        }

        try {
            $xml = $xmlProvider === null
                ? \file_get_contents(\dirname(__DIR__, 3) . '/trap.xml')
                : $xmlProvider();
        } catch (\Throwable) {
            return;
        }

        $this->xml = \is_string($xml)
            ? (\simplexml_load_string($xml, options: \LIBXML_NOERROR) ?: null)
            : null;
    }

    public function hidrate(object $config): void
    {
        // Read class properties
        $reflection = new \ReflectionObject($config);
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(ConfigAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (\count($attributes) === 0) {
                continue;
            }

            $this->injectValue($config, $property, $attributes);
        }
    }

    /**
     * @param \ReflectionProperty $property
     * @param list<\ReflectionAttribute<ConfigAttribute>> $attributes
     */
    private function injectValue(object $config, \ReflectionProperty $property, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            try {
                $attribute = $attribute->newInstance();

                /** @var mixed $value */
                $value = match (true) {
                    $attribute instanceof XPath => $this->xml?->xpath($attribute->path)[$attribute->key],
                    $attribute instanceof Env => \getenv($attribute->name) === false ? null : \getenv($attribute->name),
                    $attribute instanceof CliOption => $this->cliInput?->getOption($attribute->name),
                    default => null,
                };

                if ($value === null) {
                    continue;
                }

                // Cast value to the property type
                $type = $property->getType();

                /** @var mixed $result */
                $result = match (true) {
                    !$type instanceof \ReflectionNamedType => $value,
                    $type->allowsNull() && $value === '' => null,
                    $type->isBuiltin() => match ($type->getName()) {
                        'int' => (int) $value,
                        'float' => (float) $value,
                        'bool' => \filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        default => $value,
                    },
                    default => $value,
                };

                // todo Validation

                // Set the property value
                $property->setValue($config, $result);
                return;
            } catch (\Throwable $e) {
                $this->logger->exception($e, important: true);
            }
        }
    }
}
