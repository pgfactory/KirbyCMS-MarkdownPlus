<?php

@include_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/src/MarkdownPlus.php';

Kirby::plugin('pgfactory/markdownplus', [
    'components' => [
        'markdown' => function (Kirby $kirby, string $text = null) {
            if (!$text) {
                return '';
            }
            $md = new Usility\MarkdownPlus\MarkdownPlus();
            return $md->compile($text);
        }
    ]
]);

