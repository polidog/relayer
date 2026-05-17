<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use Polidog\Relayer\Db\DatabaseException;
use RuntimeException;

/**
 * The single error type the HTTP client layer raises.
 *
 * {@see CurlHttpClient} catches every transport-level cURL failure and
 * rethrows it wrapped in this class so callers only ever have to catch one
 * type — the same contract {@see DatabaseException}
 * provides for the DB layer. {@see HttpResponse::json()} also throws this
 * when a body is asked for as JSON but is not.
 *
 * A 4xx/5xx response never throws this; it is returned as a normal
 * {@see HttpResponse} (see {@see HttpClient}).
 */
final class HttpClientException extends RuntimeException {}
