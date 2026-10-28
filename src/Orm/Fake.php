<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Made-up values for a factory's definition(): `$this->faker->bothify('R-##')`
 * (tinvar-spec.md, "Factories").
 *
 * The few a typed model needs -- codes, numbers, picks, flags and addresses
 * nobody receives -- rather than a dependency for names and prose a factory
 * can spell itself. Seeded, the same seed makes the same values, so a test
 * that fails can be run again exactly; unseeded, it draws from the system's
 * secure source.
 */
final class Fake
{
    private const string LETTERS = 'abcdefghijklmnopqrstuvwxyz';

    private readonly Randomizer $random;

    public function __construct(?int $seed = null)
    {
        $this->random = new Randomizer($seed === null ? new Secure() : new Mt19937($seed));
    }

    /** Each `#` a digit: `numerify('###-####')`. */
    public function numerify(string $pattern): string
    {
        return $this->replace($pattern, ['#' => '0123456789']);
    }

    /** Each `?` a lowercase letter: `lexify('??-?')`. */
    public function lexify(string $pattern): string
    {
        return $this->replace($pattern, ['?' => self::LETTERS]);
    }

    /** Each `#` a digit, each `?` a letter, each `*` either: `bothify('R-##')`. */
    public function bothify(string $pattern): string
    {
        return $this->replace($pattern, ['#' => '0123456789', '?' => self::LETTERS, '*' => '0123456789' . self::LETTERS]);
    }

    /** A whole number from $min to $max, both included. */
    public function numberBetween(int $min = 0, int $max = PHP_INT_MAX): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException("numberBetween({$min}, {$max}): the least is more than the most.");
        }

        return $this->random->getInt($min, $max);
    }

    /**
     * One of $items.
     *
     * @template T
     * @param list<T> $items one at least
     * @return T
     */
    public function randomElement(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('randomElement() has nothing to pick from.');
        }

        return $items[$this->random->getInt(0, \count($items) - 1)];
    }

    /** True $chanceOfTrue times in a hundred. */
    public function boolean(int $chanceOfTrue = 50): bool
    {
        if ($chanceOfTrue < 0 || $chanceOfTrue > 100) {
            throw new \InvalidArgumentException("boolean({$chanceOfTrue}): a chance is from 0 to 100.");
        }

        return $this->random->getInt(1, 100) <= $chanceOfTrue;
    }

    /**
     * An address under `.test`, which RFC 2606 keeps from ever resolving, so
     * a factory's customer is never mailed for real.
     */
    public function email(): string
    {
        return $this->lexify('????????') . $this->numerify('##') . '@example.test';
    }

    /** @param array<string, string> $alphabets placeholder => the characters it stands for */
    private function replace(string $pattern, array $alphabets): string
    {
        $out = '';
        foreach (mb_str_split($pattern) as $character) {
            $alphabet = $alphabets[$character] ?? null;
            $out .= $alphabet === null ? $character : $alphabet[$this->random->getInt(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }
}
