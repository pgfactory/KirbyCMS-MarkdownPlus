<?php

@include_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/src/MarkdownPlus.php';
include_once __DIR__ . '/src/MdPlusHelper.php';

Kirby::plugin('usility/markdownplus', [
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
