<?php

declare(strict_types=1);

namespace staabm\PHPStanDba\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use staabm\PHPStanDba\QueryReflection\QueryReflection;

/**
 * @implements Rule<CallLike>
 */
final class QueryPlanAnalyzerRule implements Rule
{
    /**
     * @var list<string>
     */
    private $classMethods;

    /**
     * @param list<string> $classMethods
     */
    public function __construct(array $classMethods)
    {
        $this->classMethods = $classMethods;
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $callLike, Scope $scope): array
    {
        if ($callLike instanceof MethodCall) {
            if (!$callLike->name instanceof Node\Identifier) {
                return [];
            }

            $methodReflection = $scope->getMethodReflection($scope->getType($callLike->var), $callLike->name->toString());
        } elseif ($callLike instanceof New_) {
            if (!$callLike->class instanceof FullyQualified) {
                return [];
            }
            $methodReflection = $scope->getMethodReflection(new ObjectType($callLike->class->toCodeString()), '__construct');
        } else {
            return [];
        }

        if (null === $methodReflection) {
            return [];
        }

        $unsupportedMethod = true;
        $queryArgPosition = null;
        foreach ($this->classMethods as $classMethod) {
            sscanf($classMethod, '%[^::]::%[^#]#%s', $className, $methodName, $queryArgPosition);

            if ($methodName === $methodReflection->getName() &&
                ($methodReflection->getDeclaringClass()->getName() === $className || $methodReflection->getDeclaringClass()->isSubclassOf($className))) {
                $unsupportedMethod = false;
                break;
            }
        }

        if ($unsupportedMethod) {
            return [];
        }

        $args = $callLike->getArgs();

        if (!\array_key_exists($queryArgPosition, $args)) {
            return [];
        }

        if ($scope->getType($args[$queryArgPosition]->value) instanceof MixedType) {
            return [];
        }

        return $this->analyze($callLike, $scope);
    }

    /**
     * @param MethodCall|New_ $callLike
     *
     * @return RuleError[]
     */
    private function analyze(CallLike $callLike, Scope $scope): array
    {
        $args = $callLike->getArgs();

        if (\count($args) < 1) {
            return [];
        }

        if (false === QueryReflection::getRuntimeConfiguration()->getNumberOfAllowedUnindexedReads()) {
            return [];
        }

        $queryExpr = $args[0]->value;

        if ($scope->getType($queryExpr) instanceof MixedType) {
            return [];
        }

        $parameterTypes = null;
        if (\count($args) > 1) {
            $parameterTypes = $scope->getType($args[1]->value);
        }

        $ruleErrors = [];
        $queryReflection = new QueryReflection();
        $proposal = "\n\nConsider optimizing the query.\nIn some cases this is not a problem and this error should be ignored.";
        foreach ($queryReflection->analyzeQueryPlan($scope, $queryExpr, $parameterTypes) as $queryPlanResult) {
            $notUsingIndex = $queryPlanResult->getTablesNotUsingIndex();
            if (\count($notUsingIndex) > 0) {
                foreach ($notUsingIndex as $table) {
                    $ruleErrors[] = RuleErrorBuilder::message(
                        sprintf(
                            "Query is not using an index on table '%s'.".$proposal,
                            $table
                        ))
                        ->line($callLike->getLine())
                        ->tip('see Mysql Docs https://dev.mysql.com/doc/refman/8.0/en/select-optimization.html')
                        ->build();
                }
            } else {
                foreach ($queryPlanResult->getTablesDoingTableScan() as $table) {
                    $ruleErrors[] = RuleErrorBuilder::message(
                        sprintf(
                            "Query is using a full-table-scan on table '%s'.".$proposal,
                            $table
                        ))
                        ->line($callLike->getLine())
                        ->tip('see Mysql Docs https://dev.mysql.com/doc/refman/8.0/en/table-scan-avoidance.html')
                        ->build();
                }

                foreach ($queryPlanResult->getTablesDoingUnindexedReads() as $table) {
                    $ruleErrors[] = RuleErrorBuilder::message(
                        sprintf(
                        "Query is triggering too many unindexed-reads on table '%s'.".$proposal,
                            $table
                        ))
                        ->line($callLike->getLine())
                        ->tip('see Mysql Docs https://dev.mysql.com/doc/refman/8.0/en/select-optimization.html')
                        ->build();
                }
            }
        }

        return $ruleErrors;
    }
}
