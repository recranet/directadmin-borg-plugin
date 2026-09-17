<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Support\Format;
use Twig\Environment;
use Twig\Extension\EscaperExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig, configured for rendering a DirectAdmin skin fragment.
 *
 * The reason for a template engine here rather than string concatenation is
 * auto-escaping: every value on these pages is a filesystem path, an archive
 * name or a borg error message, and all three are attacker-influenced.
 */
final class TemplateRenderer
{
    private Environment $twig;

    /** @var callable(array<string,scalar>):string */
    private $urlGenerator;

    public function __construct(string $templateDir, string $cacheDir)
    {
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            // Compiled templates are written under the plugin's own state
            // directory (0700, root) rather than anywhere world-readable.
            'cache'            => $cacheDir . '/twig',
            'auto_reload'      => true,
            'strict_variables' => false,
            'autoescape'       => 'html',
        ]);

        $this->twig->getExtension(EscaperExtension::class)->setDefaultStrategy('html');

        // Twig cannot call a closure held in a template variable, so URL
        // building is exposed as a function backed by a swappable generator.
        $this->urlGenerator = static fn (array $params = []): string => '';
        $this->twig->addFunction(new TwigFunction('url', function (array $params = []): string {
            return ($this->urlGenerator)($params);
        }));

        $this->twig->addFilter(new TwigFilter('bytes', [Format::class, 'bytes']));
        $this->twig->addFilter(new TwigFilter('datetime', [Format::class, 'dateTime']));
        $this->twig->addFilter(new TwigFilter('age', [Format::class, 'age']));
        $this->twig->addFilter(new TwigFilter('date_only', [Format::class, 'date']));
        $this->twig->addFilter(new TwigFilter('time_only', [Format::class, 'time']));
    }

    /** @param callable(array<string,scalar>):string $generator */
    public function setUrlGenerator(callable $generator): void
    {
        $this->urlGenerator = $generator;
    }

    /**
     * The configured environment, so tooling (the template linter) validates
     * templates against the same filters and functions production uses.
     */
    public function environment(): Environment
    {
        return $this->twig;
    }

    /** @param array<string,mixed> $context */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
