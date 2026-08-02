<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Faker\Factory;
use Faker\Generator;
use Ngramx\Postmaclone\Exception\PostmacloneException;

class FakerMethodResolver
{
    private Generator $faker;

    /** @var array<string, array<string, true>> */
    private array $seenComposed = [];

    public function __construct(
        string $locale = 'en_GB',
        ?int $seed = 42,
    ) {
        $this->faker = Factory::create($locale);
        if ($seed !== null) {
            $this->faker->seed($seed);
        }
    }

    public function faker(): Generator
    {
        return $this->faker;
    }

    /**
     * @return array{0: callable(): mixed, 1: bool} [generator, usesUnique]
     */
    public function resolve(string $method, bool $uniqueFlag = false): array
    {
        $method = trim($method);
        if ($method === '') {
            throw new PostmacloneException('Faker method must not be empty');
        }

        if ($this->isTemplate($method) || $this->isConcatExpression($method)) {
            $this->assertMethodExists($method);

            return [
                fn () => $this->generate($method, $uniqueFlag),
                $uniqueFlag,
            ];
        }

        $unique = $uniqueFlag;
        $name = $method;

        if ($this->hasUniquePrefix($name)) {
            $unique = true;
            $name = lcfirst(substr($name, 6));
        }

        if ($name === 'password') {
            return [static fn () => null, $unique]; // handled specially by anonymizer
        }

        $this->assertFormatterExists($name);

        if ($unique) {
            return [fn () => $this->faker->unique()->{$name}(), true];
        }

        return [fn () => $this->faker->{$name}(), false];
    }

    public function assertMethodExists(string $method): void
    {
        $method = trim($method);
        if ($method === '' || $method === 'password') {
            return;
        }

        if ($this->isTemplate($method)) {
            foreach ($this->templateCalls($method) as $call) {
                $this->assertCall($call);
            }

            return;
        }

        if ($this->isConcatExpression($method)) {
            foreach ($this->concatParts($method) as $part) {
                if ($this->isQuotedLiteral($part)) {
                    continue;
                }
                $this->assertCall($part);
            }

            return;
        }

        $name = $method;
        if ($this->hasUniquePrefix($name)) {
            $name = lcfirst(substr($name, 6));
        }
        if ($name === 'password') {
            return;
        }

        $this->assertFormatterExists($this->formatterName($name));
    }

    public function generate(string $method, bool $uniqueFlag = false): mixed
    {
        $method = trim($method);

        if ($this->isTemplate($method) || $this->isConcatExpression($method)) {
            $producer = fn () => $this->isTemplate($method)
                ? $this->evaluateTemplate($method)
                : $this->evaluateConcat($method);

            if ($uniqueFlag) {
                return $this->ensureUnique($method, $producer);
            }

            return $producer();
        }

        [$callable] = $this->resolve($method, $uniqueFlag);

        return $callable();
    }

    private function isTemplate(string $expression): bool
    {
        return str_contains($expression, '{{');
    }

    /**
     * Concat form: numberBetween(1, 999) + " " + firstName + " Street"
     * Requires spaced + so bare method names stay single-formatters.
     */
    private function isConcatExpression(string $expression): bool
    {
        return str_contains($expression, ' + ');
    }

    private function hasUniquePrefix(string $name): bool
    {
        return str_starts_with($name, 'unique')
            && strlen($name) > 6
            && ctype_upper($name[6] ?? '');
    }

    private function evaluateTemplate(string $template): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            function (array $matches): string {
                $value = $this->invokeCall($matches[1]);

                return $this->stringify($value);
            },
            $template
        );

        if ($result === null) {
            throw new PostmacloneException("Failed to evaluate faker template: {$template}");
        }

        return $result;
    }

    private function evaluateConcat(string $expression): string
    {
        $parts = [];
        foreach ($this->concatParts($expression) as $part) {
            if ($this->isQuotedLiteral($part)) {
                $parts[] = $this->unquote($part);
                continue;
            }
            $parts[] = $this->stringify($this->invokeCall($part));
        }

        return implode('', $parts);
    }

    /**
     * @return list<string>
     */
    private function templateCalls(string $template): array
    {
        preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $template, $matches);

        return array_values(array_filter($matches[1] ?? [], is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function concatParts(string $expression): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $expression[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '+' && $this->isConcatSeparator($expression, $i)) {
                $part = trim($buffer);
                if ($part === '') {
                    throw new PostmacloneException("Empty segment in faker expression: {$expression}");
                }
                $parts[] = $part;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($quote !== null) {
            throw new PostmacloneException("Unclosed string in faker expression: {$expression}");
        }

        $tail = trim($buffer);
        if ($tail === '') {
            throw new PostmacloneException("Trailing + in faker expression: {$expression}");
        }
        $parts[] = $tail;

        return $parts;
    }

    private function isConcatSeparator(string $expression, int $plusIndex): bool
    {
        $before = $plusIndex > 0 ? $expression[$plusIndex - 1] : '';
        $after = $expression[$plusIndex + 1] ?? '';

        return $before === ' ' && $after === ' ';
    }

    private function assertCall(string $call): void
    {
        $name = $this->formatterName($call);
        if ($name === 'password') {
            throw new PostmacloneException(
                "Faker method 'password' cannot be used inside a chained expression; use it alone."
            );
        }
        $this->assertFormatterExists($name);
    }

    private function assertFormatterExists(string $method): void
    {
        try {
            $this->faker->getFormatter($method);
        } catch (\InvalidArgumentException $e) {
            throw new PostmacloneException(
                "Unknown faker method '{$method}'. Use a FakerPHP formatter name (e.g. safeEmail, firstName), "
                . 'a {{template}}, or method + "literal" chaining.',
                0,
                $e
            );
        }
    }

    private function invokeCall(string $call): mixed
    {
        $call = trim($call);
        $unique = false;
        if ($this->hasUniquePrefix($call)) {
            $unique = true;
            $call = lcfirst(substr($call, 6));
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(?:\((.*)\))?\s*$/s', $call, $matches)) {
            throw new PostmacloneException(
                "Invalid faker call '{$call}'. Expected a formatter name, optionally with arguments."
            );
        }

        $name = $matches[1];
        $args = trim($matches[2] ?? '') === '' ? [] : $this->parseArgs($matches[2]);
        $this->assertFormatterExists($name);

        $target = $unique ? $this->faker->unique() : $this->faker;

        return $target->{$name}(...$args);
    }

    private function formatterName(string $call): string
    {
        $call = trim($call);
        if ($this->hasUniquePrefix($call)) {
            $call = lcfirst(substr($call, 6));
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $call, $matches)) {
            throw new PostmacloneException("Invalid faker call '{$call}'");
        }

        return $matches[1];
    }

    /**
     * @return list<mixed>
     */
    private function parseArgs(string $args): array
    {
        $values = [];
        $buffer = '';
        $quote = null;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];

            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $args[$i + 1];
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                    $buffer .= $char;
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ',') {
                $values[] = $this->parseArgToken(trim($buffer));
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($quote !== null) {
            throw new PostmacloneException("Unclosed string in faker arguments: {$args}");
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $values[] = $this->parseArgToken($tail);
        }

        return $values;
    }

    private function parseArgToken(string $token): mixed
    {
        if ($token === '') {
            throw new PostmacloneException('Empty faker argument');
        }

        if ($this->isQuotedLiteral($token)) {
            return $this->unquote($token);
        }

        $lower = strtolower($token);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $token) === 1) {
            return (int) $token;
        }
        if (preg_match('/^-?\d+\.\d+$/', $token) === 1) {
            return (float) $token;
        }

        throw new PostmacloneException(
            "Unsupported faker argument '{$token}'. Use numbers, true/false/null, or quoted strings."
        );
    }

    private function isQuotedLiteral(string $value): bool
    {
        $value = trim($value);

        return (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2)
            || (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2);
    }

    private function unquote(string $value): string
    {
        $value = trim($value);
        $quote = $value[0];
        $inner = substr($value, 1, -1);

        return str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $inner);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new PostmacloneException('Faker expression parts must return scalar values');
    }

    /**
     * @param callable(): mixed $producer
     */
    private function ensureUnique(string $key, callable $producer): mixed
    {
        for ($attempt = 0; $attempt < 10000; $attempt++) {
            $value = $producer();
            $serialized = is_scalar($value) || $value === null
                ? (string) $value
                : serialize($value);
            if (!isset($this->seenComposed[$key][$serialized])) {
                $this->seenComposed[$key][$serialized] = true;

                return $value;
            }
        }

        throw new PostmacloneException("Could not generate a unique value for faker expression '{$key}'");
    }
}
