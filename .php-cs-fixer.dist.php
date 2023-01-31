<?php

$_phpcs_finder = PhpCsFixer\Finder::create()
  ->in(__DIR__)
;

$_phpcs_config = new PhpCsFixer\Config();
return $_phpcs_config->setRules([
        '@PSR12' => true,
        //'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($_phpcs_finder)
;