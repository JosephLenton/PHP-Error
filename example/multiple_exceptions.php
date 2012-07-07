<?php
    require( dirname(__FILE__) . '/../src/php_error.php' );
    \php_error\reportErrors();

    function a() {
        b();
    }

    function b() {
        try {
            c();
        } catch ( Exception $ex ) {
            throw new Exception( "thrown exception", 0, $ex );
        }
    }

    function c() {
        d();
    }

    function d() {
        throw new Exception( 'blah' );
    }

    a( "fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar" );
