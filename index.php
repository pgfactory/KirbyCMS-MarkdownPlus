<?php

@include_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/src/MarkdownPlus.php';

Kirby::plugin('pgfactory/markdownplus', [
    'components' => [
        'markdown' => function (Kirby $kirby, string|null $text = null) {
            if (!$text) {
                return '';
            }
            $md = new PgFactory\MarkdownPlus\MarkdownPlus();
            return $md->compile($text);
        }
    ]
]);


/**
 * Intercept kirby's page() helper, which may override result based on global variable $pfyOverridePage
 * @param string|null $arg
 * @return \Kirby\Cms\Page|mixed|null
 */
function pfyPage(string|null $arg = null)
{
    if (($arg === null) && class_exists('\\PgFactory\\PageFactory\\PageFactory')) {
        $page = $GLOBALS['pfyOverridePage'] ?? false;
        if ($page) {
            return $page;
        }
    }
    return page($arg);
} // pfyPage
