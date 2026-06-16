<?php

namespace PHPUnit\Framework;

if (!class_exists('PHPUnit\\Framework\\TestCase')) {
    abstract class TestCase {
        protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void {}

        protected function assertTrue(bool $condition, string $message = ''): void {}

        protected function assertFalse(bool $condition, string $message = ''): void {}

        protected function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void {}

        protected function expectException(string $exception): void {}
    }
}
