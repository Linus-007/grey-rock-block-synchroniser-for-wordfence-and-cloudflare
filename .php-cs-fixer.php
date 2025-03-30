<?php

$finder = PhpCsFixer\Finder::create()
  ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
  ->setRules([
    '@PSR12' => true,
    'braces' => [
      'position_after_functions_and_oop_constructs' => 'same',
      'position_after_control_structures' => 'same',
      'position_after_anonymous_constructs' => 'same',
      'allow_single_line_closure' => true,
    ],
  ])
  ->setFinder($finder);
