<?php
// src/Web/View.php
//
// Renders plain PHP templates from src/Web/View.
//
// No template engine. The templates are PHP, and everything that reaches
// them arrives already escaped or passes through $e() on the way out. Two
// helpers are always available inside a template:
//
//   $e('text')              escape for HTML
//   $t('nav.overview')      translate a key
//
// A template produces the body; the body is then dropped into layout.php.
// A template rendered with $layout = false comes back on its own, which is
// what partial responses will need later.

declare(strict_types=1);

namespace DcInfraSnap\Web;

use DcInfraSnap\I18n\Translator;
use RuntimeException;
use Throwable;

final class View
{
    /** @var array<string, mixed> values handed to every template */
    private array $shared = [];

    public function __construct(
        private readonly string $dir,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Make a value available to every template, e.g. the navigation state.
     */
    public function share(string $key, mixed $value): self
    {
        $this->shared[$key] = $value;

        return $this;
    }

    /**
     * Render a template and wrap it in the layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], bool $layout = true): string
    {
        $body = $this->capture($template, $data);

        if (!$layout) {
            return $body;
        }

        return $this->capture('layout', $data + ['body' => $body]);
    }

    /**
     * Render a template and return what it printed.
     *
     * @param array<string, mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $file = $this->dir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        // Helpers every template can count on.
        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $t = fn (string $key, array $params = []): string => $this->translator->t($key, $params);

        $locale = $this->translator->locale();

        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (Throwable $exception) {
            // Without this, a failure halfway through a template leaves the
            // output buffer open and the page comes back truncated with no
            // sign of why.
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /**
     * A URL for a file under public/, carrying the file's modification time.
     *
     * The browser cache then never holds back a change to the stylesheet,
     * and the file is still cached between changes.
     */
    public static function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $stamp = is_file($file) ? (string) filemtime($file) : '0';

        return $path . '?v=' . $stamp;
    }
}
