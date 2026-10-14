<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

use PDOStatement;

/** What SimulatedDatabase::statement() returns: it must be a PDOStatement, and nothing in a migration reads it. */
final class SimulatedStatement extends PDOStatement
{
}
