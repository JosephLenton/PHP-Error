<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors();
?>
<!DOCTYPE html>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>

<script>
    var sendOnce = true;

	var r = new XMLHttpRequest();
	r.onreadystatechange = function() {
        console.log( 'done inside ' + r.readyState );
        console.log( r.responseText.length );
	}

	r.open( 'get', '/example/unknown_variable.php', false );
	r.send();

    console.log( 'done outside ' + r.responseText.length );
</script>
