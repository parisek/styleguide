<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Translation;

/**
 * @internal Implementation detail of {@see TranslationCatalog}.
 *
 * Evaluates a gettext `Plural-Forms` C-expression (e.g.
 * `nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;`) against a count,
 * returning the plural-variant index to use. Read from the catalogue's own
 * header per the design doc — never assumed — because this project's own
 * catalogues carry a wrong header for three of five locales.
 *
 * No `eval()`: the expression is parsed into a small AST (see the private
 * {@see Node} value object below) by a recursive-descent parser over the
 * (whitelisted) grammar gettext actually uses — ternary, ||, &&,
 * comparisons, +/-, *, /, %, unary !, parens, `n`, and integer literals.
 * Anything outside that grammar throws rather than silently evaluating to
 * 0, since a throw here is caught by the caller and degraded to the
 * germanic default — see {@see self::DEFAULT_EXPRESSION}.
 */
final class PluralForms
{
    /** Used when a catalogue supplies no (or an unparsable) Plural-Forms header. */
    private const DEFAULT_EXPRESSION = 'n != 1';

    private string $expr;
    private int $pos = 0;
    private int $len;

    private function __construct(string $expr)
    {
        $this->expr = $expr;
        $this->len = strlen($expr);
    }

    /**
     * Parse a full `Plural-Forms:` header value (`nplurals=N; plural=EXPR;`
     * or `nplurals=N; plural=EXPR` without the trailing `;`) and return a
     * closure `fn(int $n): int` selecting the plural-variant index.
     */
    public static function compile(?string $header): \Closure
    {
        $expression = self::DEFAULT_EXPRESSION;
        if ($header !== null && preg_match('/plural\s*=\s*([^;]+)/i', $header, $m) === 1) {
            $candidate = trim($m[1]);
            if ($candidate !== '') {
                $expression = $candidate;
            }
        }

        try {
            $parser = new self($expression);
            $ast = $parser->parseTernary();
            $parser->skipWhitespace();
            if ($parser->pos !== $parser->len) {
                throw new \RuntimeException('trailing input after expression');
            }
        } catch (\Throwable) {
            // Malformed/unsupported expression — fall back to the germanic
            // rule rather than let a bad catalogue header break every render.
            $parser = new self(self::DEFAULT_EXPRESSION);
            $ast = $parser->parseTernary();
        }

        return static fn(int $n): int => (int) $ast->evaluate($n);
    }

    // --- Recursive-descent parser -----------------------------------------
    // Grammar (highest to lowest precedence handled first):
    //   ternary   := logicOr ('?' ternary ':' ternary)?
    //   logicOr   := logicAnd ('||' logicAnd)*
    //   logicAnd  := equality ('&&' equality)*
    //   equality  := relational (('=='|'!=') relational)*
    //   relational:= additive (('<='|'>='|'<'|'>') additive)*
    //   additive  := multiplicative (('+'|'-') multiplicative)*
    //   multiplicative := unary (('*'|'/'|'%') unary)*
    //   unary     := '!' unary | primary
    //   primary   := 'n' | INTEGER | '(' ternary ')'

    private function parseTernary(): PluralFormsNode
    {
        $condition = $this->parseLogicOr();
        $this->skipWhitespace();
        if ($this->peek() === '?') {
            $this->pos++;
            $then = $this->parseTernary();
            $this->skipWhitespace();
            $this->expect(':');
            $else = $this->parseTernary();
            return PluralFormsNode::ternary($condition, $then, $else);
        }
        return $condition;
    }

    private function parseLogicOr(): PluralFormsNode
    {
        $left = $this->parseLogicAnd();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('||')) {
                $left = PluralFormsNode::binary('||', $left, $this->parseLogicAnd());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseLogicAnd(): PluralFormsNode
    {
        $left = $this->parseEquality();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('&&')) {
                $left = PluralFormsNode::binary('&&', $left, $this->parseEquality());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseEquality(): PluralFormsNode
    {
        $left = $this->parseRelational();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('==')) {
                $left = PluralFormsNode::binary('==', $left, $this->parseRelational());
                continue;
            }
            if ($this->matchOperator('!=')) {
                $left = PluralFormsNode::binary('!=', $left, $this->parseRelational());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseRelational(): PluralFormsNode
    {
        $left = $this->parseAdditive();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('<=')) {
                $left = PluralFormsNode::binary('<=', $left, $this->parseAdditive());
                continue;
            }
            if ($this->matchOperator('>=')) {
                $left = PluralFormsNode::binary('>=', $left, $this->parseAdditive());
                continue;
            }
            if ($this->matchOperator('<')) {
                $left = PluralFormsNode::binary('<', $left, $this->parseAdditive());
                continue;
            }
            if ($this->matchOperator('>')) {
                $left = PluralFormsNode::binary('>', $left, $this->parseAdditive());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseAdditive(): PluralFormsNode
    {
        $left = $this->parseMultiplicative();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('+')) {
                $left = PluralFormsNode::binary('+', $left, $this->parseMultiplicative());
                continue;
            }
            if ($this->matchOperator('-')) {
                $left = PluralFormsNode::binary('-', $left, $this->parseMultiplicative());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseMultiplicative(): PluralFormsNode
    {
        $left = $this->parseUnary();
        while (true) {
            $this->skipWhitespace();
            if ($this->matchOperator('*')) {
                $left = PluralFormsNode::binary('*', $left, $this->parseUnary());
                continue;
            }
            if ($this->matchOperator('/')) {
                $left = PluralFormsNode::binary('/', $left, $this->parseUnary());
                continue;
            }
            if ($this->matchOperator('%')) {
                $left = PluralFormsNode::binary('%', $left, $this->parseUnary());
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseUnary(): PluralFormsNode
    {
        $this->skipWhitespace();
        if ($this->matchOperator('!')) {
            return PluralFormsNode::not($this->parseUnary());
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): PluralFormsNode
    {
        $this->skipWhitespace();
        $ch = $this->peek();
        if ($ch === '(') {
            $this->pos++;
            $inner = $this->parseTernary();
            $this->skipWhitespace();
            $this->expect(')');
            return $inner;
        }
        if ($ch === 'n' && !$this->isIdentChar($this->peek(1))) {
            $this->pos++;
            return PluralFormsNode::variable();
        }
        if ($ch !== null && ctype_digit($ch)) {
            $start = $this->pos;
            while ($this->peek() !== null && ctype_digit((string) $this->peek())) {
                $this->pos++;
            }
            return PluralFormsNode::literal((int) substr($this->expr, $start, $this->pos - $start));
        }
        throw new \RuntimeException(sprintf('unexpected character at offset %d', $this->pos));
    }

    private function isIdentChar(?string $ch): bool
    {
        return $ch !== null && (ctype_alnum($ch) || $ch === '_');
    }

    private function peek(int $ahead = 0): ?string
    {
        $index = $this->pos + $ahead;
        return $index < $this->len ? $this->expr[$index] : null;
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ctype_space($this->expr[$this->pos])) {
            $this->pos++;
        }
    }

    private function matchOperator(string $op): bool
    {
        $length = strlen($op);
        if (substr($this->expr, $this->pos, $length) === $op) {
            $this->pos += $length;
            return true;
        }
        return false;
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new \RuntimeException(sprintf('expected "%s" at offset %d', $char, $this->pos));
        }
        $this->pos++;
    }
}
