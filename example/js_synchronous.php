<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors();
?>
<!DOCTYPE html>
<script>
    var sendOnce = true;

	var r = new XMLHttpRequest();
	r.onreadystatechange = function() {
        console.log( 'done inside ' + r.readyState );
        console.log( r.responseText.length );
	}

	r.open( 'get', './unknown_variable.php', false );
	r.send();

    console.log( 'done outside ' + r.responseText.length );
</script>
