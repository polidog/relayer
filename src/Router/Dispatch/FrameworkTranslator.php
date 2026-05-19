<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\HttpException;
use Psr\Container\ContainerInterface;

/**
 * Translate framework-emitted strings (404 / 405 / auth reason phrases, …)
 * via the container-bound {@see Translator}, falling back to the verbatim
 * English fallback when no Translator is bound or the key is absent — so
 * the output is byte-identical to the pre-i18n behaviour for an
 * unconfigured / English app.
 *
 * Extracted from {@see AppRouter} so the API
 * dispatcher and error responder can localise without each holding their
 * own container reference.
 */
final class FrameworkTranslator
{
    public function __construct(
        private ?ContainerInterface $container = null,
    ) {}

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * Translate a framework key, falling back to the verbatim English string
     * when no Translator is bound or the key is absent.
     *
     * @param array<string, float|int|string> $params
     */
    public function trans(string $key, string $fallback, array $params = []): string
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
     * standard reason phrase is localised (by status, then by a generic
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

    private function translator(): ?Translator
    {
        if (null === $this->container || !$this->container->has(Translator::class)) {
            return null;
        }

        $translator = $this->container->get(Translator::class);

        return $translator instanceof Translator ? $translator : null;
    }
}
