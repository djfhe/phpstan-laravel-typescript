<?php

declare(strict_types=1);

namespace djfhe\ControllerTransformer\PHPStan;

use djfhe\ControllerTransformer\ControllerFunctionReturns;
use djfhe\ControllerTransformer\PHPStan\Typescript\TsNeverType;
use djfhe\ControllerTransformer\PHPStan\Typescript\TsTypeParser;
use djfhe\ControllerTransformer\PHPStan\Typescript\TypescriptTypes\_TsType;
use djfhe\ControllerTransformer\PHPStan\Typescript\TypescriptTypes\Laravel\TsAbstractPaginatedType;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;

class ErrorFormatter implements \PHPStan\Command\ErrorFormatter\ErrorFormatter
{
    public function __construct() {}

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        /**  @var ControllerFunctionReturns[] $returnTypes */
        $returnTypes = [];

        $typesWithIdentifiers = [];

        TsTypeParser::autoload();

        $test = new TsAbstractPaginatedType(new TsNeverType());

        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            if ($error->getIdentifier() !== ControllerFunctionReturns::$error_identifier) {
                continue;
            }

            $metadata = $error->getMetadata();
            $container = ControllerFunctionReturns::deserialize($metadata);

            $types = $container->getRecursiveChildren();

            foreach ($types as $type) {
                $identifier = $type->getIdentifier();

                if ($identifier === null) {
                    continue;
                }

                if (!isset($typesWithIdentifiers[$identifier])) {
                    $typesWithIdentifiers[$identifier] = $type;
                }
            }

            $returnTypes[] = $container;
        }

        $classMapper = function (string $class) {
            return str_replace('\\', '.', $class);
        };

        $identifierMapper = function (string $typeString) use ($classMapper) {
            return preg_replace_callback('/\{%(.*?)%\}/', function ($matches) use ($classMapper) {
                return $classMapper($matches[1]);
            }, $typeString);
        };

        /**
         * @var array<array{ namespace: ?string, typeDefinitions: string[] }>
         */
        $namespaces = [];

        foreach ($typesWithIdentifiers as $identifier => $type) {
            [$namespace, $typeDefinition] = $this->typesWithIdentifierToTypescriptDefinition($identifier, $type, $classMapper, $identifierMapper);

            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = [
                    'namespace' => $namespace,
                    'typeDefinitions' => []
                ];
            }

            $namespaces[$namespace]['typeDefinitions'][] = $typeDefinition;
        }

        foreach ($returnTypes as $return) {
            [$namespace, $typeDefinition] = $this->controllerEndpointToTypescriptDefinition($return, $classMapper, $identifierMapper);

            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = [
                    'namespace' => $namespace,
                    'typeDefinitions' => []
                ];
            }

            $namespaces[$namespace]['typeDefinitions'][] = $typeDefinition;
        }


        $output->writeRaw($this->namespacesToTypescript($namespaces));
        
        return 0;
    }

    /**
     * @param array<array{namespace: ?string, typeDefinitions: string[]}> $namespaces
     */
    protected function namespacesToTypescript(array $namespaces): string
    {
        usort($namespaces, function($a, $b) {
            if ($a['namespace'] === null) {
                return -1;
            }

            if ($b['namespace'] === null) {
                return 1;
            }

            return $a['namespace'] <=> $b['namespace'];
        });

        $typescript = array_map(fn($namespace) => $this->namespaceToTypescriptString($namespace), $namespaces);

        return implode(PHP_EOL . PHP_EOL, $typescript);
    }
    
    /**
     * @param array{namespace: ?string, typeDefinitions: string[]} $namespace
     */
    protected function namespaceToTypescriptString(array $namespace): string
    {
        $printInNamespace = function (?string $namespace, array $typeDefinitions): string {
            $namespaceStart = $namespace === null ? '' : 'declare namespace ' . $namespace . ' {' . PHP_EOL;
            $typeDefinitions = implode(PHP_EOL, $typeDefinitions);
            $namespaceEnd = $namespace === null ? '' : PHP_EOL . '}';

            return $namespaceStart . $typeDefinitions . $namespaceEnd;
        };

        return $printInNamespace($namespace['namespace'], $namespace['typeDefinitions']);
    }

    /**
     * @param \Closure(string): string $classMapper
     * @param \Closure(string): string $identifierMapper
     * 
     * @return [string, string]
     */
    protected function controllerEndpointToTypescriptDefinition(ControllerFunctionReturns $return, \Closure $classMapper, \Closure $identifierMapper): array
    {
        $namespace = $classMapper($return->class);
        $methodName = $return->methodName;

        if (count($return->returns) === 1) {
            $keyword = $return->returns[0]->definitionKeyword();
            $code = $return->returns[0]->toTypeDefinition(false);
            $code = $identifierMapper($code);

            $typeDefinition = $this->createTsDefinition($keyword, $methodName, $code);

            return [$namespace, $typeDefinition];
        }

        $code = implode(' | ', array_map(fn(_TsType $type) => $identifierMapper($type->toTypeString(false)), $return->returns));
        $code = $identifierMapper($code);

        $typeDefinition = $this->createTsDefinition('type', $methodName, $code);

        return [$namespace, $typeDefinition];
    }

    /**
     * @param \Closure(string): string $classMapper
     * @param \Closure(string): string $identifierMapper
     * 
     * @return [?string, string]
     */
    protected function typesWithIdentifierToTypescriptDefinition(string $identifier, _TsType $type, \Closure $classMapper, \Closure $identifierMapper): array
    {
        $identifier = $classMapper($identifier);
        $typeDefinition = $identifierMapper($type->toTypeDefinition(false));

        [$namespace, $name] = $this->getNamespaceAndNameFromIdentifier($identifier);
        
        $typeDefinition = $this->createTsDefinition($type->definitionKeyword(), $name, $typeDefinition);

        return [$namespace, $typeDefinition];
    }

    /**
     * @param 'type' | 'interface' $keyword
     */
    protected function createTsDefinition(string $keyword, string $identifier, string $definition): string
    {
        if ($keyword === 'interface') {
            return 'export interface ' . $identifier . ' ' . $definition;
        }

        return 'export type ' . $identifier . ' = ' . $definition . ';';
    }

    /**
     * @param string $identifier
     * 
     * @return array{?string, string}
     */
    protected function getNamespaceAndNameFromIdentifier(string $identifier): array
    {
        $parts = explode('.', $identifier);

        if (count($parts) === 1) {
            return [null, $parts[0]];
        }

        $name = array_pop($parts);
        $namespace = implode('.', $parts);

        return [$namespace, $name];
    }
}
