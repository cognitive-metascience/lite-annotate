<?php

namespace PHPUnit\Framework;

if (!class_exists('PHPUnit\\Framework\\TestCase')) {
    abstract class TestCase {
        protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
            throw new \LogicException('PHPUnit is not installed. Assertions are no-ops. Install phpunit/phpunit to run tests.');
        }

        protected function assertTrue(bool $condition, string $message = ''): void {
            throw new \LogicException('PHPUnit is not installed. Assertions are no-ops. Install phpunit/phpunit to run tests.');
        }

        protected function assertFalse(bool $condition, string $message = ''): void {
            throw new \LogicException('PHPUnit is not installed. Assertions are no-ops. Install phpunit/phpunit to run tests.');
        }

        protected function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void {
            throw new \LogicException('PHPUnit is not installed. Assertions are no-ops. Install phpunit/phpunit to run tests.');
        }

        protected function expectException(string $exception): void {
            throw new \LogicException('PHPUnit is not installed. Assertions are no-ops. Install phpunit/phpunit to run tests.');
        }
    }
}
