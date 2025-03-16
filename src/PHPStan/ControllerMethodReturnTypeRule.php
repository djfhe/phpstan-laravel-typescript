<?php

declare(strict_types=1);

namespace djfhe\ControllerTransformer\PHPStan;

use djfhe\ControllerTransformer\ControllerFunctionReturns;
use djfhe\ControllerTransformer\InertiaPropContainer;
use djfhe\ControllerTransformer\InertiaPropsContainer;
use djfhe\ControllerTransformer\PHPStan\Typescript\TsTypeParser;
use djfhe\ControllerTransformer\PHPStan\Typescript\TypescriptMap;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ReturnStatement;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements \PHPStan\Rules\Rule<\PhpStan\Node\ReturnStatementsNode>
 */
class ControllerMethodReturnTypeRule implements \PHPStan\Rules\Rule
{
    public function __construct(
        private ReflectionProvider $reflectionProvider
    ) {}

    public function getNodeType(): string
    {
      return \PHPStan\Node\MethodReturnStatementsNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof \PHPStan\Node\MethodReturnStatementsNode) {
            return [];
        }

        $namespace = $scope->getNamespace();

        if (! is_string($namespace) || ! str_starts_with($namespace, 'App\\Http\\Controllers')) {
            return [];
        }

        $returns = $node->getReturnStatements();

        /**
         * @var array<array{0: ?\PhpParser\Node\Expr, 1: Scope}>
         */
        $args = array_map(function (ReturnStatement $return) {
            return [$this->getInertiaReturnStatementArgs($return->getReturnNode()), $return->getScope()];
        }, $returns);

        /**
         * @var array<array{0: \PhpParser\Node\Expr, 1: Scope}>
         */
        $args = array_filter($args, function (array $arg) {
          $expr = $arg[0];

          if ($expr === null) {
              return false;
          }

          if ($expr instanceof \PhpParser\Node\Expr\Array_) {
              return count($expr->items) > 0;
          }

          return true;
        });

        if (count($args) === 0) {
            return [];
        }
        
        $className = $scope->getClassReflection()->getName();
        $methodName = $node->getMethodName();

        $functionReturnTypeContainer = new ControllerFunctionReturns($className, $methodName);
        
        foreach ($args as $arg) {
            $returnValue = $arg[0];
            $returnScope = $arg[1];

            $type = TsTypeParser::parseExpression($returnValue, $returnScope, $this->reflectionProvider);
            
            $functionReturnTypeContainer->add($type);

        }
        if ($functionReturnTypeContainer->count() === 0) {
            return [];
        }

        return [
          RuleErrorBuilder::message('')
            ->identifier(ControllerFunctionReturns::$error_identifier)
            ->metadata($functionReturnTypeContainer->serialize())
            ->line($node->getLine())
            ->build()
        ];
    }

    /**
     * @return array<\PhpParser\Node\Stmt\Return_>
     */
    protected function collectReturnStatements(\PhpParser\Node\Stmt\ClassMethod $method): array
    {
        $returnStatements = [];

        if ($method->stmts === null) {
            return $returnStatements;
        }

        foreach ($method->stmts as $stmt) {
            if (! $stmt instanceof \PhpParser\Node\Stmt\Return_) {
                continue;
            }

            $returnStatements[] = $stmt;
        }

        return $returnStatements;
    }

    protected function getInertiaReturnStatementArgs(\PhpParser\Node\Stmt\Return_ $returnStatement): ?\PhpParser\Node\Expr
    {
        $args = [];

        $inertiaExpr = $returnStatement->expr;

        if (! $inertiaExpr instanceof \PhpParser\Node\Expr\StaticCall) {
            return null;
        }

        if (! $inertiaExpr->class instanceof \PhpParser\Node\Name\FullyQualified) {
            return null;
        }

        if ($inertiaExpr->class->name !== 'Inertia\Inertia') {
            return null;
        }

        if (! $inertiaExpr->name instanceof \PhpParser\Node\Identifier) {
            return null;
        }

        if ($inertiaExpr->name->name !== 'render') {
            return null;
        }

        if (count($inertiaExpr->args) !== 2) {
            return null;
        }

        $inertiaArg = $inertiaExpr->args[1];

        if (! $inertiaArg instanceof \PhpParser\Node\Arg) {
            return null;
        }

        $value = $inertiaArg->value;

        return $value;
    }
}
