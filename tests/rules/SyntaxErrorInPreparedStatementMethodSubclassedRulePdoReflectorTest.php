<?php

namespace staabm\PHPStanDba\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use staabm\PHPStanDba\Rules\SyntaxErrorInPreparedStatementMethodRule;

/**
 * @extends RuleTestCase<SyntaxErrorInPreparedStatementMethodRule>
 */
class SyntaxErrorInPreparedStatementMethodSubclassedRulePdoReflectorTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(SyntaxErrorInPreparedStatementMethodRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__.'/config/subclassed-method-rule.neon',
        ];
    }

    public function testSyntaxErrorInQueryRule(): void
    {
        if ('pdo-mysql' !== getenv('DBA_REFLECTOR')) {
            $this->markTestSkipped('Only works with PdoMysqlQueryReflector');
        }

        require_once __DIR__.'/data/syntax-error-in-method-subclassed.php';

        $this->analyse([__DIR__.'/data/syntax-error-in-method-subclassed.php'], [
            [
                "Query error: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL/MariaDB server version for the right syntax to use near 'with syntax error GROUPY by x LIMIT 0' at line 1 (42000).",
                12,
            ],
            [
                "Query error: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL/MariaDB server version for the right syntax to use near 'freigabe1u1 FROM ada LIMIT 0' at line 1 (42000).",
                18,
            ],
            [
                "Query error: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL/MariaDB server version for the right syntax to use near 'FROM ada LIMIT 0' at line 3 (42000).",
                20,
            ],
        ]);
    }
}
