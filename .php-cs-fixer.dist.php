<?php

declare(strict_types=1);

/**
 * Coding standards for the plugin.
 *
 * PSR-12 plus the Symfony rules, since the code leans on Symfony components and
 * reads better in the same idiom. Risky rules are enabled deliberately: the
 * strict comparison and native-function rules catch real bugs, not just style.
 */
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/test'])
    ->append([
        __DIR__ . '/bootstrap.php',
        __DIR__ . '/.php-cs-fixer.dist.php',
    ])
    // Generated or vendored code is not ours to reformat.
    ->exclude(['docker']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                     => true,
        '@Symfony'                   => true,
        '@Symfony:risky'             => true,
        'declare_strict_types'       => true,
        'strict_comparison'          => true,
        'strict_param'               => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'ordered_imports'            => ['sort_algorithm' => 'alpha'],
        'global_namespace_import'    => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'phpdoc_to_comment'          => false,
        'concat_space'               => ['spacing' => 'one'],
        'yoda_style'                 => false,
        // Alignment makes the configuration and context arrays in this codebase
        // far easier to scan.
        'binary_operator_spaces' => ['default' => 'single_space', 'operators' => ['=>' => 'align_single_space_minimal']],
    ])
    ->setFinder($finder);
