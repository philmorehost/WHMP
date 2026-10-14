<?php

declare(strict_types=1);

/**
 * Coding style. Deliberately conservative: this is a 180,000-line codebase that
 * predates the formatter, so the rule set below is the subset that is safe to
 * apply everywhere and catches real problems (unused imports, stray whitespace,
 * non-strict comparisons of the obvious kind) rather than re-flowing every
 * written line. Widen @PSR12 rule-by-rule as files are touched — a whole-tree
 * reformat is a review-hostile diff, and the project's own style (long
 * explanatory comments, one class per file) is not PSR-12's business.
 *
 * Run `composer lint` to check, `composer lint:fix` to apply. CI enforces it.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/core', __DIR__ . '/bin', __DIR__ . '/routes', __DIR__ . '/tests', __DIR__ . '/public'])
    ->append([__DIR__ . '/index.php'])
    ->name('*.php')
    ->notName('schema.php')          // generated snapshot (bin/build-schema.php)
    ->exclude(['storage', 'vendor'])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,

        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => false,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'concat_space' => ['spacing' => 'one'],
        'unary_operator_spaces' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'not_operator_with_successor_space' => true,
        'no_empty_statement' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ternary_to_null_coalescing' => true,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => false,
        'phpdoc_align' => false,
        'phpdoc_summary' => false,
        'phpdoc_separation' => false,
    ])
    ->setFinder($finder);
