<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * GRID-P5-02 (#2398) — `(field #>> path)::numeric` as DQL: numeric
 * reading of a nested JSONB envelope value (number/metric/price sorts
 * must compare numerically, not lexicographically). See ADR-0028.
 */
final class JsonbPathNumericFunction extends FunctionNode
{
    private ?Node $field = null;
    private ?Node $path = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $field = $parser->ArithmeticPrimary();
        \assert($field instanceof Node);
        $this->field = $field;
        $parser->match(TokenType::T_COMMA);
        $path = $parser->ArithmeticPrimary();
        \assert($path instanceof Node);
        $this->path = $path;
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        \assert($this->field instanceof Node && $this->path instanceof Node);

        return \sprintf(
            '((%s #>> CAST(%s AS text[]))::numeric)',
            $this->field->dispatch($sqlWalker),
            $this->path->dispatch($sqlWalker),
        );
    }
}
