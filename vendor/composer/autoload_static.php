<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticIniteb051ac0645a930a1668db56260c911d
{
    public static $prefixLengthsPsr4 = array (
        'c' => 
        array (
            'cebe\\markdown\\' => 14,
        ),
        'U' => 
        array (
            'Usility\\MarkdownPlus\\' => 21,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'cebe\\markdown\\' => 
        array (
            0 => __DIR__ . '/..' . '/cebe/markdown',
        ),
        'Usility\\MarkdownPlus\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticIniteb051ac0645a930a1668db56260c911d::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticIniteb051ac0645a930a1668db56260c911d::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticIniteb051ac0645a930a1668db56260c911d::$classMap;

        }, null, ClassLoader::class);
    }
}
