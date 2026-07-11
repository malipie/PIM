<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * GRID-P5-02 (#2398) — `field #>> path` as DQL: extracts a nested JSONB
 * value as text (`'{code,value}'` walks the ADR-0019 envelope). Used by
 * {@see \App\Catalog\Infrastructure\ApiPlatform\Filter\AttributeOrderFilter}
 * to ORDER BY attribute readings per ADR-0028.
 *
 * ISO-8601 date/datetime strings sort lexicographically identically to
 * their chronological order, so the text variant also serves date sorts.
 */
final class JsonbPathTextFunction extends FunctionNode
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
            '(%s #>> CAST(%s AS text[]))',
            $this->field->dispatch($sqlWalker),
            $this->path->dispatch($sqlWalker),
        );
    }
}
