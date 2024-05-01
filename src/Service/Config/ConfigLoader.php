<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\Config;

/**
 * @internal
 */
final class ConfigLoader
{
    private \SimpleXMLElement|null $xml = null;

    /**
     * @param null|callable(): non-empty-string $xmlProvider
     */
    public function __construct(
        ?callable $xmlProvider = null,
    )
    {
        // Check SimpleXML extension
        if (!\extension_loaded('simplexml')) {
            return;
        }

        try {
            $xml = $xmlProvider === null
                ? \file_get_contents(\dirname(__DIR__, 2) . '/trap.xml')
                : $xmlProvider();
        } catch (\Throwable) {
            return;
        }

        $this->xml = \is_string($xml) ? \simplexml_load_string($xml) : null;
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
     * @param array<\ReflectionAttribute> $attributes
     */
    private function injectValue(object $config, \ReflectionProperty $property, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $attribute = $attribute->newInstance();

            $value = match (true) {
                $attribute instanceof XPath => $this->xml?->xpath($attribute->path),
                default => null,
            };

            if ($value === null) {
                continue;
            }

            // Cast value to the property type
            $type = $property->getType();
            $result = match (true) {
                $type === null => $value[0],
                $type->allowsNull() && $value[0] === '' => null,
                $type->isBuiltin() => match ($type->getName()) {
                    'int' => (int) $value[0],
                    'float' => (float) $value[0],
                    'bool' => \filter_var($value[0], FILTER_VALIDATE_BOOLEAN),
                    default => $value[0],
                },
                default => $value[0],
            };

            // Set the property value
            $property->setValue($config, $result);
        }
    }
}
