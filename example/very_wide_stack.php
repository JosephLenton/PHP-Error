<?
	require( __DIR__ . '/../src/php_error.php' );
	\php_error\reportErrors();

	function a() {
		b();
	}

	function b() {
		$foo = $bar;
	}

	a( "fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar" );