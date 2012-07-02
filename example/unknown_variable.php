<?php
    require( dirname(__FILE__) . '/../src/php_error.php' );
    \php_error\reportErrors();

	function a() {
		b();
	}

	function b() {
		$foo = $bar;
	}

	echo '{a: 39}';
	a( "<script>alert('blah')</script>");

	echo '{a: 39}';
