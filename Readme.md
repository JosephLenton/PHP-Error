PHP Error | Improve Error Reporting for PHP
===========================================

PHP errors are not good enough for development, it's as simple as that. This aims to solve this.

![Better Error Message](http://i.imgur.com/1G77I.png)

When an error strikes, the page is replaced with a full stack trace, syntax highlighting, and all displayed to be readable.

Features
--------
 * trivial to use, it's just one file
 * makes errors as strict as possible (encourages code quality, and tends to improve performance)
 * code snippets across the whole stack trace
 * provides more information (such as full function signatures)
 * fixes some error messages which are just plain wrong
 * syntax highlighting
 * looks pretty!

Example Usage
-------------

 * [Get the library](https://github.com/JosephLenton/PHP-Error/blob/master/src/php_error.php), it's just one file.
 * Place it into your project.
 * import the file
 * call: \better_error_messages\reportErrors();
 * ???
 * profit! \o/

```php
	<?php
		require( 'php_error.php' );
		\php_error\reportErrors();
	?>
```

Do not use on a live site!
--------------------------

This is intended for __development only__. It shows more about your site, gives you more info, and makes trivial errors fatal.
All of that is awesome if you want to debug quicker, and force high quality standards.

On a production server, that sucks, and is potentially unsafe.

Advanced Features
-----------------

 * customization
 * manually turn it on and off
 * run specific sections without error reporting
 * ignore files allowing you to avoid highlighting code in your stack trace
 * application files; these are prioritized when an error strikes!
 
![Application Aware Stack Trace](http://i.imgur.com/qdwnb.png)

Customization
-------------

An optional array of parameters when you call 'reportErrors'.