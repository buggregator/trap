<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\Config;

use Buggregator\Trap\Logger;

/**
 * @internal
 */
final class ConfigLoader
{
    private ?\SimpleXMLElement $xml = null;

    /**
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly array $env = [],
        private readonly array $inputArguments = [],
        private readonly array $inputOptions = [],
        ?string $xml = null,
    ) {
        if (\is_string($xml)) {
            // Check SimpleXML extension
            if (!\extension_loaded('simplexml')) {
                $logger->info('SimpleXML extension is not loaded.');
            } else {
                $this->xml = \simplexml_load_string($xml, options: \LIBXML_NOERROR) ?: null;
            }
        }
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
     * @param list<\ReflectionAttribute<ConfigAttribute>> $attributes
     */
    private function injectValue(object $config, \ReflectionProperty $property, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            try {
                $attribute = $attribute->newInstance();

                /** @var mixed $value */
                $value = match (true) {
                    $attribute instanceof XPath => @$this->xml?->xpath($attribute->path)[$attribute->key],
                    $attribute instanceof Env => $this->env[$attribute->name] ?? null,
                    $attribute instanceof InputOption => $this->inputOptions[$attribute->name] ?? null,
                    $attribute instanceof InputArgument => $this->inputArguments[$attribute->name] ?? null,
                    default => null,
                };

                if (\in_array($value, [null, []], true)) {
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
                        'array' => match (true) {
                            \is_array($value) => $value,
                            \is_string($value) => explode(',', $value),
                            default => [$value],
                        },
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
