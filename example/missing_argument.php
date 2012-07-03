<?php
    require( dirname(__FILE__) . '/../src/php_error.php' );
    \php_error\reportErrors();

	function a() {
		b( 1, 2 );
	}

	function b( $a, $b, $c, $d=null ) {
	}

	a( "<script>alert('blah')</script>");
