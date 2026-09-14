<?php

/**
 * Compiles every Twig template against the environment the plugin actually
 * renders with, so a missing filter or a typo surfaces here rather than when a
 * particular tab is opened on a live server.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Recranet\DirectAdminBorg\Ui\TemplateRenderer;
use Symfony\Component\Finder\Finder;
use Twig\Error\Error;

$templateDir = dirname(__DIR__) . '/templates';

$renderer = new TemplateRenderer($templateDir, sys_get_temp_dir() . '/borg-template-lint');
$twig = $renderer->environment();

$failed = 0;
$checked = 0;

foreach ((new Finder())->files()->in($templateDir)->name('*.twig')->sortByName() as $file) {
    $name = $file->getRelativePathname();
    ++$checked;

    try {
        $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext($name)));
    } catch (Error $e) {
        ++$failed;
        printf("    FAIL %s: %s (line %d)\n", $name, $e->getRawMessage(), $e->getTemplateLine());
    }
}

if ($failed > 0) {
    printf("    %d of %d templates failed to compile\n", $failed, $checked);
    exit(1);
}

printf("    %d templates compile\n", $checked);
exit(0);
