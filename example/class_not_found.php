<?php
    require( __DIR__ . '/../src/php_error.php' );
    \php_error\reportErrors();

	function a() {
		b();
	}

	function b() {
		$f = new FooBar();
	}

	echo '{a: 39}';
	a( "<script>alert('blah')</script>");

	echo '{a: 39}';
