<?php

declare(strict_types=1);

namespace Polidog\Relayer\Db;

use RuntimeException;

/**
 * The single error type the DB layer raises.
 *
 * {@see PdoDatabase} catches every `PDOException` and rethrows it wrapped
 * in this class so callers (and the framework's error handling) only ever
 * have to catch one type. The original driver exception is preserved as
 * the `previous` so the SQLSTATE / driver message is still reachable via
 * {@see getPrevious()}.
 */
final class DatabaseException extends RuntimeException {}
