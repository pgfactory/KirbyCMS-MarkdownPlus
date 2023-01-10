<?php

include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('usility/markdownplus', [
    'components' => [
        'markdown' => function (Kirby $kirby, string $text = null) {
            $md = new Usility\MarkdownPlus\MarkdownPlus($kirby);
            return $md->compile($text);
        }
    ]
]);
