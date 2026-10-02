<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * PHPUnit 10 removed MockObject::withConsecutive(). This gives the same check
 * as a callback for willReturnCallback(): the n-th call must receive the n-th
 * set of arguments (each argument a value, compared loosely like before, or a
 * constraint). Like before, calls beyond the given sets are not checked.
 *
 * What a call returns: $returns, when given, as one value per call
 * ($returnsAreList; an exception in the list is thrown) or the same value for
 * every call. Otherwise the default
 * PHPUnit would have returned for the mocked method, from its return type
 * (needs $mock and $method).
 */
trait ConsecutiveCallsTrait
{
    /**
     * @param array<int, array<int, mixed>> $argumentSets
     * @param mixed                         $returns
     */
    private function consecutiveCalls(
        array $argumentSets,
        $returns = null,
        bool $returnsAreList = false,
        ?object $mock = null,
        ?string $method = null
    ): \Closure {
        $call = 0;
        $self = $this;

        return static function (...$arguments) use (&$call, $argumentSets, $returns, $returnsAreList, $mock, $method, $self) {
            $index = $call++;

            foreach (array_values($argumentSets[$index] ?? []) as $position => $expected) {
                Assert::assertThat(
                    $arguments[$position] ?? null,
                    $expected instanceof Constraint ? $expected : new IsEqual($expected),
                    sprintf('Call #%d, argument #%d', $index + 1, $position + 1)
                );
            }

            if ($returnsAreList) {
                if (($returns[$index] ?? null) instanceof \Throwable) {
                    throw $returns[$index];
                }

                return $returns[$index] ?? null;
            }
            if (null !== $returns) {
                return $returns;
            }

            return $self->defaultReturnOf($mock, $method);
        };
    }

    /**
     * What an unconfigured method of a mock returns, from its declared return type.
     *
     * @return mixed
     */
    private function defaultReturnOf(?object $mock, ?string $method)
    {
        if (null === $mock || null === $method) {
            return null;
        }

        $type = (new \ReflectionMethod($mock, $method))->getReturnType();

        if (!$type instanceof \ReflectionNamedType || $type->allowsNull() || 'void' === $type->getName()) {
            return null;
        }

        return match ($type->getName()) {
            'bool'            => false,
            'int'             => 0,
            'float'           => 0.0,
            'string'          => '',
            'array', 'iterable' => [],
            'object'          => new \stdClass(),
            'self', 'static'  => $mock,
            default           => $this->createStub($type->getName()),
        };
    }

    /**
     * The values in order, then null for every further call. PHPUnit 9 did that for
     * willReturn($a, $b) and willReturnOnConsecutiveCalls(); PHPUnit 10 throws once
     * the configured values run out.
     *
     * @param array<int, mixed> $values
     */
    private function returnsThenNull(array $values): \Closure
    {
        $call = 0;

        return static function () use (&$call, $values) {
            return $values[$call++] ?? null;
        };
    }
}
