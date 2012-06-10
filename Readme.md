PHP Better Error Reporting
==========================

PHP errors are not good enough for development, this aims to improve them.

![Better Error Message](http://i.imgur.com/WdvX9.png)

When an error strikes, the page is replaced with a full stack trace, syntax highlighting, and all displayed to be readable.

Features
--------
 * trivial to use, it's just one file
 * makes errors as strict as possible (encourages code quality, and tends to improve performance)
 * provides more information
 * fixes some errors which are just plain wrong
 * syntax highlighting
 * provides code snippets
 * looks pretty!

Example Usage
-------------

 * [Get the library](https://github.com/JosephLenton/PHP-Better-Error-Reporting/blob/master/src/better_error_reporting.php), it's just one file.
 * Place it into your project.
 * import the file
 * call: \better_error_messages\reportErrors();
 * ???
 * profit! \o/

```php
	<?
		require( 'better_error_message.php' );
		\better_error_message\reportErrors();
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
 
![Application Aware Stack Trace](http://i.imgur.com/tQxc0.png)

Customization
-------------

An optional array of parameters when you call 'reportErrors'.