<?php
    require( __DIR__ . '/../src/php_error.php' );
    \php_error\reportErrors();

	function a() {
		b();
	}

	function b() {
		$foo = $bar;
	}

	echo '{a: 39}';
	a();

	echo '{a: 39}';
