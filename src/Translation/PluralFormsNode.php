<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Translation;

/**
 * @internal AST node for {@see PluralForms}. A typed value object rather
 *           than a bare array — the grammar's node kinds carry a different
 *           number of operands (0 for `n`/literal, 1 for unary `!`, 2 for
 *           binary operators, 3 for ternary), and a plain array shape can't
 *           express "this offset exists only for these operators" in a way
 *           PHPStan can verify without either an unresolvable recursive
 *           type alias or scattered `offsetAccess.notFound` suppressions.
 */
final class PluralFormsNode
{
    private const KIND_VAR = 'var';
    private const KIND_LITERAL = 'literal';
    private const KIND_NOT = 'not';
    private const KIND_TERNARY = 'ternary';
    private const KIND_BINARY = 'binary';

    private function __construct(
        private readonly string $kind,
        private readonly int $literal = 0,
        private readonly ?string $operator = null,
        private readonly ?self $a = null,
        private readonly ?self $b = null,
        private readonly ?self $c = null,
    ) {
    }

    public static function variable(): self
    {
        return new self(self::KIND_VAR);
    }

    public static function literal(int $value): self
    {
        return new self(self::KIND_LITERAL, literal: $value);
    }

    public static function not(self $operand): self
    {
        return new self(self::KIND_NOT, a: $operand);
    }

    public static function binary(string $operator, self $left, self $right): self
    {
        return new self(self::KIND_BINARY, operator: $operator, a: $left, b: $right);
    }

    public static function ternary(self $condition, self $then, self $else): self
    {
        return new self(self::KIND_TERNARY, a: $condition, b: $then, c: $else);
    }

    public function evaluate(int $n): int|bool
    {
        return match ($this->kind) {
            self::KIND_VAR => $n,
            self::KIND_LITERAL => $this->literal,
            self::KIND_NOT => !self::truthy($this->operand('a')->evaluate($n)),
            self::KIND_TERNARY => self::truthy($this->operand('a')->evaluate($n))
                ? $this->operand('b')->evaluate($n)
                : $this->operand('c')->evaluate($n),
            self::KIND_BINARY => $this->evaluateBinary($n),
            default => throw new \RuntimeException(sprintf('unknown AST node kind "%s"', $this->kind)),
        };
    }

    /**
     * Every node kind is only ever constructed (see the named factories
     * above) with the child slots its grammar rule actually uses filled —
     * this asserts that invariant back into a non-nullable type for
     * `evaluate()`'s recursive calls, since the `a`/`b`/`c` properties are
     * typed `?self` to accommodate every OTHER kind that leaves them null.
     */
    private function operand(string $slot): self
    {
        $value = match ($slot) {
            'a' => $this->a,
            'b' => $this->b,
            'c' => $this->c,
            default => throw new \RuntimeException('invalid slot'),
        };
        if ($value === null) {
            throw new \RuntimeException(sprintf('AST node kind "%s" is missing operand "%s"', $this->kind, $slot));
        }
        return $value;
    }

    private function evaluateBinary(int $n): int|bool
    {
        $left = $this->operand('a')->evaluate($n);
        $right = $this->operand('b')->evaluate($n);
        return match ($this->operator) {
            '||' => self::truthy($left) || self::truthy($right),
            '&&' => self::truthy($left) && self::truthy($right),
            '==' => $left === $right,
            '!=' => $left !== $right,
            '<=' => $left <= $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '>' => $left > $right,
            '+' => (int) $left + (int) $right,
            '-' => (int) $left - (int) $right,
            '*' => (int) $left * (int) $right,
            '/' => intdiv((int) $left, max(1, (int) $right)),
            '%' => (int) $left % max(1, (int) $right),
            default => throw new \RuntimeException(sprintf('unknown binary operator "%s"', (string) $this->operator)),
        };
    }

    private static function truthy(int|bool|null $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return ((int) $value) !== 0;
    }
}
