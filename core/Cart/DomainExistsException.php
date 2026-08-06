<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use RuntimeException;

/**
 * Thrown inside CheckoutService's transaction when an order tries to insert
 * a domain whose name is already in the local `domains` table (the column
 * is UNIQUE). Rolled back like OutOfStockException rather than letting the
 * raw PDO 1062 surface as a 500 — mirrors the friendly "already in the
 * system" guard DomainController::create() applies to manual records.
 */
final class DomainExistsException extends RuntimeException
{
}
