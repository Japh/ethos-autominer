<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite0199f68ecabf65dbb281ea32ea7a768
{
    public static $prefixLengthsPsr4 = array (
        'F' => 
        array (
            'Flintstone\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Flintstone\\' => 
        array (
            0 => __DIR__ . '/..' . '/fire015/flintstone/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInite0199f68ecabf65dbb281ea32ea7a768::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInite0199f68ecabf65dbb281ea32ea7a768::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
