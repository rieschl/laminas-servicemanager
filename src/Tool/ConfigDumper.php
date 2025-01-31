<?php

declare(strict_types=1);

namespace Laminas\ServiceManager\Tool;

use Laminas\ServiceManager\AbstractFactory\ConfigAbstractFactory;
use Laminas\ServiceManager\Exception\InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function class_exists;
use function date;
use function gettype;
use function implode;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;
use function sprintf;
use function str_repeat;
use function var_export;

/**
 * @internal
 */
final class ConfigDumper implements ConfigDumperInterface
{
    public const CONFIG_TEMPLATE                          = <<<EOC
<?php

/**
 * This file generated by %s.
 * Generated %s
 */

return %s;
EOC;
    public const LAMINAS_MVC_SERVICEMANAGER_CONFIGURATION = 'service_manager';
    public const MEZZIO_CONTAINER_CONFIGURATION           = 'dependencies';

    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly string $serviceManagerConfigurationKey = self::LAMINAS_MVC_SERVICEMANAGER_CONFIGURATION,
    ) {
    }

    public function createDependencyConfig(array $config, string $className, bool $ignoreUnresolved = false): array
    {
        $reflectionClass = new ReflectionClass($className);
        $constructor     = $reflectionClass->getConstructor();

        // class has no constructor, treat it as an invokable
        if ($constructor === null) {
            return $this->createInvokable($config, $className);
        }

        // has no required parameters, treat it as an invokable
        if ($constructor->getNumberOfRequiredParameters() === 0) {
            return $this->createInvokable($config, $className);
        }

        $constructorArguments = array_filter(
            $constructor->getParameters(),
            static fn(ReflectionParameter $argument): bool => ! $argument->isOptional()
        );

        $classConfig = [];

        foreach ($constructorArguments as $constructorArgument) {
            $type         = $constructorArgument->getType();
            $argumentType = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            if ($argumentType === null) {
                if ($ignoreUnresolved) {
                    // don't throw an exception, just return the previous config
                    return $config;
                }

                // don't throw an exception if the class is an already defined service
                if ($this->container && $this->container->has($className)) {
                    return $config;
                }

                throw new InvalidArgumentException(sprintf(
                    'Cannot create config for constructor argument "%s", '
                    . 'it has no type hint, or non-class/interface type hint',
                    $constructorArgument->getName()
                ));
            }

            $classConfig[] = $argumentType;
            if (! class_exists($argumentType)) {
                continue;
            }

            $config = $this->createDependencyConfig($config, $argumentType, $ignoreUnresolved);
        }

        $config[ConfigAbstractFactory::class][$className] = $classConfig;

        return $config;
    }

    /**
     * @param array<string,mixed>  $config
     * @param class-string $className
     * @return array<string,mixed>
     */
    private function createInvokable(array $config, string $className): array
    {
        $config[ConfigAbstractFactory::class][$className] = [];
        return $config;
    }

    public function createFactoryMappingsFromConfig(array $config): array
    {
        if (! array_key_exists(ConfigAbstractFactory::class, $config)) {
            return $config;
        }

        if (! is_array($config[ConfigAbstractFactory::class])) {
            throw new InvalidArgumentException(
                'Config key for ' . ConfigAbstractFactory::class . ' should be an array, ' . gettype(
                    $config[ConfigAbstractFactory::class]
                ) . ' given'
            );
        }

        foreach (array_keys($config[ConfigAbstractFactory::class]) as $className) {
            $config = $this->createFactoryMappings($config, $className);
        }
        return $config;
    }

    public function createFactoryMappings(array $config, string $className): array
    {
        if (
            array_key_exists($this->serviceManagerConfigurationKey, $config)
            && array_key_exists('factories', $config['service_manager'])
            && array_key_exists($className, $config['service_manager']['factories'])
        ) {
            return $config;
        }

        $config['service_manager']['factories'][$className] = ConfigAbstractFactory::class;
        return $config;
    }

    public function dumpConfigFile(array $config): string
    {
        $prepared = $this->prepareConfig($config);
        return sprintf(
            self::CONFIG_TEMPLATE,
            self::class,
            date('Y-m-d H:i:s'),
            $prepared
        );
    }

    /**
     * @param int<0,max> $indentLevel
     * @return non-empty-string
     */
    private function prepareConfig(iterable $config, int $indentLevel = 1): string
    {
        $indent  = str_repeat(' ', $indentLevel * 4);
        $entries = [];
        foreach ($config as $key => $value) {
            $key       = $this->createConfigKey($key);
            $entries[] = sprintf(
                '%s%s%s,',
                $indent,
                $key ? sprintf('%s => ', $key) : '',
                $this->createConfigValue($value, $indentLevel)
            );
        }

        $outerIndent = str_repeat(' ', ($indentLevel - 1) * 4);

        return sprintf(
            "[\n%s\n%s]",
            implode("\n", $entries),
            $outerIndent
        );
    }

    private function createConfigKey(string|int|null $key): string|null
    {
        if (is_string($key) && class_exists($key)) {
            return sprintf('\\%s::class', $key);
        }

        if (is_int($key)) {
            return null;
        }

        return sprintf("'%s'", $key);
    }

    /**
     * @param int<0,max> $indentLevel
     */
    private function createConfigValue(mixed $value, int $indentLevel): string
    {
        if (is_iterable($value)) {
            return $this->prepareConfig($value, $indentLevel + 1);
        }

        if (is_string($value) && class_exists($value)) {
            return sprintf('\\%s::class', $value);
        }

        return var_export($value, true);
    }
}
