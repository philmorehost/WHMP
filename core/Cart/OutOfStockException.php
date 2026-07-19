<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use RuntimeException;

/**
 * Thrown inside CheckoutService's transaction when a limited-stock
 * product's atomic decrement fails — i.e. a concurrent checkout won the
 * race for the last unit between this order's pre-transaction stock read
 * and the actual decrement. Rolls the whole order back rather than
 * charging a client for a unit that no longer exists.
 */
final class OutOfStockException extends RuntimeException
{
}
