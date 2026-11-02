<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\Router\HttpException;
use Psr\Container\ContainerInterface;

/**
 * The framework's client-facing messages, translated when the container binds
 * a {@see Translator}.
 *
 * @internal
 */
final readonly class HttpMessages
{
    public function __construct(private ?ContainerInterface $container) {}

    public function translator(): ?Translator
    {
        if (null === $this->container || !$this->container->has(Translator::class)) {
            return null;
        }

        $translator = $this->container->get(Translator::class);

        return $translator instanceof Translator ? $translator : null;
    }

    /**
     * Translate a framework key, falling back to the verbatim English
     * string when no Translator is bound or the key is absent — so the
     * output is byte-identical to the pre-i18n behavior for an
     * unconfigured / English app.
     *
     * @param array<string, float|int|string> $params
     */
    public function tr(string $key, string $fallback, array $params = []): string
    {
        $translator = $this->translator();
        if (null === $translator || !$translator->has($key)) {
            return $fallback;
        }

        return $translator->trans($key, $params);
    }

    /**
     * The error message for an {@see HttpException}: a custom reason
     * (`abort($status, 'msg')`) is passed through untouched; only the
     * standard reason phrase is localized (by status, then by a generic
     * client/server-error key, then English).
     */
    public function localizedReason(HttpException $exception): string
    {
        $reason = $exception->reason;

        if ($reason !== HttpException::reasonPhrase($exception->status)) {
            return $reason;
        }

        $translator = $this->translator();
        if (null === $translator) {
            return $reason;
        }

        $key = 'relayer.http.' . $exception->status;
        if ($translator->has($key)) {
            return $translator->trans($key);
        }

        $generic = $exception->status >= 500
            ? 'relayer.http.server_error'
            : 'relayer.http.client_error';

        return $translator->has($generic) ? $translator->trans($generic) : $reason;
    }
}
