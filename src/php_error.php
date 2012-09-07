<?php
    /**
     * @license
     * 
     * PHP Error
     * 
     * Copyright (c) 2012 Joseph Lenton
     * All rights reserved.
     * 
     * Redistribution and use in source and binary forms, with or without
     * modification, are permitted provided that the following conditions are met:
     *     * Redistributions of source code must retain the above copyright
     *       notice, this list of conditions and the following disclaimer.
     *     * Redistributions in binary form must reproduce the above copyright
     *       notice, this list of conditions and the following disclaimer in the
     *       documentation and/or other materials provided with the distribution.
     *     * Neither the name of the <organization> nor the
     *       names of its contributors may be used to endorse or promote products
     *       derived from this software without specific prior written permission.
     * 
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
     * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
     * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     * 
     * Uses:
     *      JSMin-php   https://github.com/rgrove/jsmin-php/
     *      jQuery      http://jquery.com/
     */

    /**
     * PHP Error
     * 
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     * 
     * WARNING! It is downright _DANGEROUS_ to use this in production, on
     * a live website. It should *ONLY* be used for development.
     * 
     * PHP Error will kill your environment at will, clear the output
     * buffers, and allows HTML injection from exceptions.
     * 
     * In future versions it plans to do far more then that.
     * 
     * If you use it in development, awesome! If you use it in production,
     * you're an idiot.
     * 
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     * 
     * = Info
     * 
     * A small API for replacing the standard PHP errors, with prettier
     * error reporting. This will change the error reporting level, and this
     * is deliberate, as I believe in strict development errors.
     * 
     * simple usage:
     * 
     *      \php_error\reportErrors();
     * 
     * Advanced example:
     * 
     * There is more too it if you want more customized error handling. You
     * can pass in options, to customize the setup, and you get back a
     * handler you can alter at runtime.
     * 
     *      $handler = new \php_error\ErrorHandler( $myOptions );
     *      $handler->turnOn();
     * 
     * There should only ever be one handler! This is an (underdstandable)
     * limitation in PHP. It's because if an exception or error is raised,
     * then there is a single point of handling it.
     * 
     * = INI Options
     * 
     * - php_error.force_disabled When set to a true value (such as on),
     *                            this forces this to be off.
     *                            This is so you can disable this script
     *                            in your production servers ini file,
     *                            incase you accidentally upload this there.
     * 
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     * 
     * @author Joseph Lenton | https://github.com/josephlenton
     */

    namespace php_error;

    use \php_error\ErrorException,
        \php_error\FileLinesSet,
        \php_error\ErrorHandler,

        \php_error\JSMin,
        \php_error\JSMinException;

    use \Closure,
        \Exception,
        \InvalidArgumentException;

    use \ReflectionMethod,
        \ReflectionFunction,
        \ReflectionParameter;

    global $_php_error_already_setup,
           $_php_error_global_handler,
           $_php_error_is_ini_enabled;

    /*
     * Avoid being run twice.
     */
    if ( empty($_php_error_already_setup) ) {
        $_php_error_already_setup = true;

        /*
         * These are used as token identifiers by PHP.
         * 
         * If they are missing, then they should never pop out of PHP,
         * so we just give them their future value.
         * 
         * They are primarily here so I don't have to alter the 5.3
         * compliant code. Instead I can delete pre-5.3 code (this
         * code), in the future.
         * 
         * As long as the value is unique, and does not clash with PHP,
         * then any number could be used. That is why they start counting
         * at 100,000.
         */
        if ( ! defined('T_DIR') ) {
            define( 'T_DIR', 100001 );
        }
        if ( ! defined('T_GOTO') ) {
            define( 'T_GOTO', 100002 );
        }
        if ( ! defined('T_NAMESPACE') ) {
            define( 'T_NAMESPACE', 100003 );
        }
        if ( ! defined('T_NS_C') ) {
            define( 'T_NS_C', 100004 );
        }
        if ( ! defined('T_NS_SEPARATOR') ) {
            define( 'T_NS_SEPARATOR', 100005 );
        }
        if ( ! defined('T_USE') ) {
            define( 'T_USE', 100006 );
        }

        /*
         * Check if it's empty, in case this file is loaded multiple times.
         */
        if ( ! isset($_php_error_global_handler) ) {
            $_php_error_global_handler = null;

            $_php_error_is_ini_enabled = false;

            /*
             * check both 'disable' and 'disabled' incase it's mispelt
             * check that display errors is on
             * and ensure we are *not* a command line script.
             */
            $_php_error_is_ini_enabled = 
                    ! @get_cfg_var( 'php_error.force_disabled' ) &&
                    ! @get_cfg_var( 'php_error.force_disable'  ) &&
                      @ini_get('display_errors') === '1'         &&
                       PHP_SAPI !== 'cli'
            ;
        }

        /**
         * This is shorthand for turning off error handling,
         * calling a block of code, and then turning it on.
         * 
         * However if 'reportErrors' has not been called,
         * then this will silently do nothing.
         * 
         * @param callback A PHP function to call.
         * @return The result of calling the callback.
         */
        function withoutErrors( $callback ) {
            global $_php_error_global_handler;

            if ( $_php_error_global_handler !== null ) {
                return $_php_error_global_handler->withoutErrors( $callback );
            } else {
                return $callback();
            }
        }

        /**
         * Turns on error reporting, and returns the handler.
         * 
         * If you just want error reporting on, then don't bother
         * catching the handler. If you're building something
         * clever, like a framework, then you might want to grab
         * and use it.
         * 
         * Note that calling this a second time will replace the
         * global error handling with a new error handler.
         * The existing one will be turned off, and the new one
         * turned on.
         * 
         * You can't use two at once!
         * 
         * @param options Optional, options declaring how PHP Error should be setup and used.
         * @return The ErrorHandler used for reporting errors.
         */
        function reportErrors( $options=null ) {
            $handler = new ErrorHandler( $options );
            return $handler->turnOn();
        }

        /**
         * The actual handler. There can only ever be one.
         */
        class ErrorHandler
        {
            const REGEX_DOCTYPE = '/<( )*!( *)DOCTYPE([^>]+)>/';

            const REGEX_PHP_IDENTIFIER = '\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
            const REGEX_PHP_CONST_IDENTIFIER = '/\b[A-Z_\x7f-\xff][A-Z0-9_\x7f-\xff]*/';

            /**
             * Matches:
             *  {closure}()
             *  blah::foo()
             *  foo()
             * 
             * It is:
             *      a closure
             *      or a method or function
             *      followed by parenthesis '()'
             * 
             *      a function is 'namespace function'
             *      a method is 'namespace class::function', or 'namespace class->function'
             *      the whole namespace is optional
             *          namespace is made up of an '\' and then repeating 'namespace\'
             *          both the first slash, and the repeating 'namespace\', are optional
             * 
             * 'END' matches it at the end of a string, the other one does not.
             */
            const REGEX_METHOD_OR_FUNCTION_END = '/(\\{closure\\})|(((\\\\)?(\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*)?\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?)\\(\\)$/';
            const REGEX_METHOD_OR_FUNCTION     = '/(\\{closure\\})|(((\\\\)?(\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*)?\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?)\\(\\)/';

            const REGEX_VARIABLE = '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';

            const REGEX_MISSING_SEMI_COLON_FOLLOWING_LINE = '/^ *(return|}|if|while|foreach|for|switch)/';

            /**
             * The number of lines to take from the file,
             * where the error is reported. This is the number
             * of lines around the line in question,
             * including that line.
             * 
             * So '9' will be the error line + 4 lines above + 4 lines below.
             */
            const NUM_FILE_LINES = 13;

            const FILE_TYPE_APPLICATION = 1;
            const FILE_TYPE_IGNORE      = 2;
            const FILE_TYPE_ROOT        = 3;

            const PHP_ERROR_MAGIC_HEADER_KEY = 'PHP_ERROR_MAGIC_HEADER';
            const PHP_ERROR_MAGIC_HEADER_VALUE = 'php_stack_error';
            const MAGIC_IS_PRETTY_ERRORS_MARKER = '<!-- __magic_php_error_is_a_stack_trace_constant__ -->';

            const PHP_ERROR_INI_PREFIX = 'php_error';

            /**
             * At the time of writing, scalar type hints are unsupported.
             * By scalar, I mean 'string' and 'integer'.
             * 
             * If they do get added, this is here as a trap to turn scalar
             * type hint warnings on and off.
             */
            private static $IS_SCALAR_TYPE_HINTING_SUPPORTED = false;

            private static $SCALAR_TYPES = array(
                    'string', 'integer', 'float', 'boolean',
                    'bool', 'int', 'number'
            );

            /**
             * A mapping of PHP internal symbols,
             * mapped to descriptions of them.
             */
            private static $PHP_SYMBOL_MAPPINGS = array(
                    '$end'                          => 'end of file',
                    'T_ABSTRACT'                    => 'abstract',
                    'T_AND_EQUAL'                   => "'&='",
                    'T_ARRAY'                       => 'array',
                    'T_ARRAY_CAST'                  => 'array cast',
                    'T_AS'                          => "'as'",
                    'T_BOOLEAN_AND'                 => "'&&'",
                    'T_BOOLEAN_OR'                  => "'||'",
                    'T_BOOL_CAST'                   => 'boolean cast',
                    'T_BREAK'                       => 'break',
                    'T_CASE'                        => 'case',
                    'T_CATCH'                       => 'catch',
                    'T_CLASS'                       => 'class',
                    'T_CLASS_C'                     => '__CLASS__',
                    'T_CLONE'                       => 'clone',
                    'T_CLOSE_TAG'                   => 'closing PHP tag',
                    'T_CONCAT_EQUAL'                => "'.='",
                    'T_CONST'                       => 'const',
                    'T_CONSTANT_ENCAPSED_STRING'    => 'string',
                    'T_CONTINUE'                    => 'continue',
                    'T_CURLY_OPEN'                  => '\'{$\'',
                    'T_DEC'                         => '-- (decrement)',
                    'T_DECLARE'                     => 'declare',
                    'T_DEFAULT'                     => 'default',
                    'T_DIR'                         => '__DIR__',
                    'T_DIV_EQUAL'                   => "'/='",
                    'T_DNUMBER'                     => 'number',
                    'T_DOLLAR_OPEN_CURLY_BRACES'    => '\'${\'',
                    'T_DO'                          => "'do'",
                    'T_DOUBLE_ARROW'                => "'=>'",
                    'T_DOUBLE_CAST'                 => 'double cast',
                    'T_DOUBLE_COLON'                => "'::'",
                    'T_ECHO'                        => 'echo',
                    'T_ELSE'                        => 'else',
                    'T_ELSEIF'                      => 'elseif',
                    'T_EMPTY'                       => 'empty',
                    'T_ENCAPSED_AND_WHITESPACE'     => 'non-terminated string',
                    'T_ENDDECLARE'                  => 'enddeclare',
                    'T_ENDFOR'                      => 'endfor',
                    'T_ENDFOREACH'                  => 'endforeach',
                    'T_ENDIF'                       => 'endif',
                    'T_ENDSWITCH'                   => 'endswitch',
                    'T_ENDWHILE'                    => 'endwhile',
                    'T_EVAL'                        => 'eval',
                    'T_EXIT'                        => 'exit call',
                    'T_EXTENDS'                     => 'extends',
                    'T_FILE'                        => '__FILE__',
                    'T_FINAL'                       => 'final',
                    'T_FOR'                         => 'for',
                    'T_FOREACH'                     => 'foreach',
                    'T_FUNCTION'                    => 'function',
                    'T_FUNC_C'                      => '__FUNCTION__',
                    'T_GLOBAL'                      => 'global',
                    'T_GOTO'                        => 'goto',
                    'T_HALT_COMPILER'               => '__halt_compiler',
                    'T_IF'                          => 'if',
                    'T_IMPLEMENTS'                  => 'implements',
                    'T_INC'                         => '++ (increment)',
                    'T_INCLUDE'                     => 'include',
                    'T_INCLUDE_ONCE'                => 'include_once',
                    'T_INSTANCEOF'                  => 'instanceof',
                    'T_INT_CAST'                    => 'int cast',
                    'T_INTERFACE'                   => 'interface',
                    'T_ISSET'                       => 'isset',
                    'T_IS_EQUAL'                    => "'=='",
                    'T_IS_GREATER_OR_EQUAL'         => "'>='",
                    'T_IS_IDENTICAL'                => "'==='",
                    'T_IS_NOT_EQUAL'                => "'!=' or '<>'",
                    'T_IS_NOT_IDENTICAL'            => "'!=='",
                    'T_IS_SMALLER_OR_EQUAL'         => "'<='",
                    'T_LINE'                        => '__LINE__',
                    'T_LIST'                        => 'list',
                    'T_LNUMBER'                     => 'number',
                    'T_LOGICAL_AND'                 => "'and'",
                    'T_LOGICAL_OR'                  => "'or'",
                    'T_LOGICAL_XOR'                 => "'xor'",
                    'T_METHOD_C'                    => '__METHOD__',
                    'T_MINUS_EQUAL'                 => "'-='",
                    'T_MOD_EQUAL'                   => "'%='",
                    'T_MUL_EQUAL'                   => "'*='",
                    'T_NAMESPACE'                   => 'namespace',
                    'T_NEW'                         => 'new',
                    'T_NUM_STRING'                  => 'array index in a string',
                    'T_NS_C'                        => '__NAMESPACE__',
                    'T_NS_SEPARATOR'                => 'namespace seperator',
                    'T_OBJECT_CAST'                 => 'object cast',
                    'T_OBJECT_OPERATOR'             => "'->'",
                    'T_OLD_FUNCTION'                => 'old_function',
                    'T_OPEN_TAG'                    => "'<?php' or '<?'",
                    'T_OPEN_TAG_WITH_ECHO'          => "'<?php echo '",
                    'T_OR_EQUAL'                    => "'|='",
                    'T_PAAMAYIM_NEKUDOTAYIM'        => "'::'",
                    'T_PLUS_EQUAL'                  => "'+='",
                    'T_PRINT'                       => 'print',
                    'T_PRIVATE'                     => 'private',
                    'T_PUBLIC'                      => 'public',
                    'T_PROTECTED'                   => 'protected',
                    'T_REQUIRE'                     => 'require',
                    'T_REQUIRE_ONCE'                => 'require_once',
                    'T_RETURN'                      => 'return',
                    'T_SL'                          => "'<<'",
                    'T_SL_EQUAL'                    => "'<<='",
                    'T_SR'                          => "'>>'",
                    'T_SR_EQUAL'                    => "'>>='",
                    'T_START_HEREDOC'               => "'<<<'",
                    'T_STATIC'                      => 'static',
                    'T_STRING'                      => 'string',
                    'T_STRING_CAST'                 => 'string cast',
                    'T_SWITCH'                      => 'switch',
                    'T_THROW'                       => 'throw',
                    'T_TRY'                         => 'try',
                    'T_UNSET'                       => 'unset',
                    'T_UNSET_CAST'                  => 'unset cast',
                    'T_USE'                         => 'use',
                    'T_VAR'                         => 'var',
                    'T_VARIABLE'                    => 'variable',
                    'T_WHILE'                       => 'while',
                    'T_WHITESPACE'                  => 'whitespace',
                    'T_XOR_EQUAL'                   => "'^='"
            );

            private static $syntaxMap = array(
                    'const'                       => 'syntax-literal',
                    'reference_ampersand'         => 'syntax-function',

                    T_COMMENT                     => 'syntax-comment',
                    T_DOC_COMMENT                 => 'syntax-comment',

                    T_ABSTRACT                    => 'syntax-keyword',
                    T_AS                          => 'syntax-keyword',
                    T_BREAK                       => 'syntax-keyword',
                    T_CASE                        => 'syntax-keyword',
                    T_CATCH                       => 'syntax-keyword',
                    T_CLASS                       => 'syntax-keyword',

                    T_CONST                       => 'syntax-keyword',

                    T_CONTINUE                    => 'syntax-keyword',
                    T_DECLARE                     => 'syntax-keyword',
                    T_DEFAULT                     => 'syntax-keyword',
                    T_DO                          => 'syntax-keyword',
                    
                    T_ELSE                        => 'syntax-keyword',
                    T_ELSEIF                      => 'syntax-keyword',
                    T_ENDDECLARE                  => 'syntax-keyword',
                    T_ENDFOR                      => 'syntax-keyword',
                    T_ENDFOREACH                  => 'syntax-keyword',
                    T_ENDIF                       => 'syntax-keyword',
                    T_ENDSWITCH                   => 'syntax-keyword',
                    T_ENDWHILE                    => 'syntax-keyword',
                    T_EXTENDS                     => 'syntax-keyword',

                    T_FINAL                       => 'syntax-keyword',
                    T_FOR                         => 'syntax-keyword',
                    T_FOREACH                     => 'syntax-keyword',
                    T_FUNCTION                    => 'syntax-keyword',
                    T_GLOBAL                      => 'syntax-keyword',
                    T_GOTO                        => 'syntax-keyword',
                    
                    T_IF                          => 'syntax-keyword',
                    T_IMPLEMENTS                  => 'syntax-keyword',
                    T_INSTANCEOF                  => 'syntax-keyword',
                    T_INTERFACE                   => 'syntax-keyword',

                    T_LOGICAL_AND                 => 'syntax-keyword',
                    T_LOGICAL_OR                  => 'syntax-keyword',
                    T_LOGICAL_XOR                 => 'syntax-keyword',
                    T_NAMESPACE                   => 'syntax-keyword',
                    T_NEW                         => 'syntax-keyword',
                    T_PRIVATE                     => 'syntax-keyword',
                    T_PUBLIC                      => 'syntax-keyword',
                    T_PROTECTED                   => 'syntax-keyword',
                    T_RETURN                      => 'syntax-keyword',
                    T_STATIC                      => 'syntax-keyword',
                    T_SWITCH                      => 'syntax-keyword',
                    T_THROW                       => 'syntax-keyword',
                    T_TRY                         => 'syntax-keyword',
                    T_USE                         => 'syntax-keyword',
                    T_VAR                         => 'syntax-keyword',
                    T_WHILE                       => 'syntax-keyword',

                    // __VAR__ type magic constants
                    T_CLASS_C                     => 'syntax-literal',
                    T_DIR                         => 'syntax-literal',
                    T_FILE                        => 'syntax-literal',
                    T_FUNC_C                      => 'syntax-literal',
                    T_LINE                        => 'syntax-literal',
                    T_METHOD_C                    => 'syntax-literal',
                    T_NS_C                        => 'syntax-literal',

                    T_DNUMBER                     => 'syntax-literal',
                    T_LNUMBER                     => 'syntax-literal',

                    T_CONSTANT_ENCAPSED_STRING    => 'syntax-string',
                    T_VARIABLE                    => 'syntax-variable',

                    // this is for unescaped strings, which appear differently
                    // this includes function names
                    T_STRING                      => 'syntax-function',

                    // in build keywords, which work like functions
                    T_ARRAY                       => 'syntax-function',
                    T_CLONE                       => 'syntax-function',
                    T_ECHO                        => 'syntax-function',
                    T_EMPTY                       => 'syntax-function',
                    T_EVAL                        => 'syntax-function',
                    T_EXIT                        => 'syntax-function',
                    T_HALT_COMPILER               => 'syntax-function',
                    T_INCLUDE                     => 'syntax-function',
                    T_INCLUDE_ONCE                => 'syntax-function',
                    T_ISSET                       => 'syntax-function',
                    T_LIST                        => 'syntax-function',
                    T_REQUIRE_ONCE                => 'syntax-function',
                    T_PRINT                       => 'syntax-function',
                    T_REQUIRE                     => 'syntax-function',
                    T_UNSET                       => 'syntax-function'
            );

            /**
             * A list of methods which are known to call the autoloader,
             * but should not error, if the class is not found.
             * 
             * They are allowed to fail, so we don't store a class not
             * found exception if they do.
             */
            private static $SAFE_AUTOLOADER_FUNCTIONS = array(
                    'class_exists',
                    'interface_exists',
                    'method_exists',
                    'property_exists',
                    'is_subclass_of'
            );

            /**
             * When returning values, if a mime type is set,
             * then PHP Error should only output if the mime type
             * is one of these.
             */
            private static $ALLOWED_RETURN_MIME_TYPES = array(
                    'text/html',
                    'application/xhtml+xml'
            );
            
            /**
             * This attempts to state if this is *not* a PHP request,
             * but it cannot say if it *is* a PHP request. It achieves
             * this by looking for a mime type.
             *
             * For example if the mime type is JavaScript, then we
             * know it's not PHP. However there is no "yes, this is
             * definitely a normal HTML response" flag we can check.
             */
            private static function isNonPHPRequest() {
                /*
                 * Check if we are a mime type that isn't allowed.
                 *
                 * Anything other than 'text/html' or similar will cause
                 * this to turn off.
                 */
                $response = ErrorHandler::getResponseHeaders();

                foreach ( $response as $key => $value ) {
                    if ( strtolower($key) === 'content-type' ) {
                        if ( ! in_array($value, ErrorHandler::$ALLOWED_RETURN_MIME_TYPES) ) {
                            return true;
                        }

                        break;
                    }
                }

                return false;
            }

            /**
             * Looks up a description for the symbol given,
             * and if found, it is returned.
             * 
             * If it's not found, then the symbol given is returned.
             */
            private static function phpSymbolToDescription( $symbol ) {
                if ( isset(ErrorHandler::$PHP_SYMBOL_MAPPINGS[$symbol]) ) {
                    return ErrorHandler::$PHP_SYMBOL_MAPPINGS[$symbol];
                } else {
                    return "'$symbol'";
                }
            }

            /**
             * Attempts to syntax highlight the code snippet done.
             * 
             * This is then returned as HTML, ready to be dumped to the screen.
             * 
             * @param code An array of code lines to syntax highlight.
             * @return HTML version of the code given, syntax highlighted.
             */
            private static function syntaxHighlight( $code ) {
                $syntaxMap = ErrorHandler::$syntaxMap;

                // @supress invalid code raises a warning
                $tokens = @token_get_all( "<?php " . $code . " ?" . ">" );
                $html = array();
                $len = count($tokens)-1;
                $inString = false;
                $stringBuff = null;
                $skip = false;

                for ( $i = 1; $i < $len; $i++ ) {
                    $token = $tokens[$i];

                    if ( is_array($token) ) {
                        $type = $token[0];
                        $code = $token[1];
                    } else {
                        $type = null;
                        $code = $token;
                    }

                    // work out any whitespace padding
                    if ( strpos($code, "\n") !== false && trim($code) === '' ) {
                        if ( $inString ) {
                            $html[]= "<span class='syntax-string'>" . join('', $stringBuff);
                            $stringBuff = array();
                        }
                    } else if ( $code === '&' ) {
                        if ( $i < $len ) {
                            $next = $tokens[$i+1];

                            if ( is_array($next) && $next[0] === T_VARIABLE ) {
                                $type = 'reference_ampersand';
                            }
                        }
                    } else if ( $code === '"' || $code === "'" ) {
                        if ( $inString ) {
                            $html[]= "<span class='syntax-string'>" . join('', $stringBuff) . htmlspecialchars($code) . "</span>";
                            $stringBuff = null;
                            $skip = true;
                        } else {
                            $stringBuff = array();
                        }

                        $inString = !$inString;
                    } else if ( $type === T_STRING ) {
                        $matches = array();
                        preg_match(ErrorHandler::REGEX_PHP_CONST_IDENTIFIER, $code, $matches);

                        if ( $matches && strlen($matches[0]) === strlen($code) ) {
                            $type = 'const';
                        }
                    }

                    if ( $skip ) {
                        $skip = false;
                    } else {
                        $code = htmlspecialchars( $code );

                        if ( $type !== null && isset($syntaxMap[$type]) ) {
                            $class = $syntaxMap[$type];

                            if ( $type === T_CONSTANT_ENCAPSED_STRING && strpos($code, "\n") !== false ) {
                                $append = "<span class='$class'>" .
                                            join(
                                                    "</span>\n<span class='$class'>",
                                                    explode( "\n", $code )
                                            ) .
                                        "</span>" ;
                            } else if ( strrpos($code, "\n") === strlen($code)-1 ) {
                                $append = "<span class='$class'>" . substr($code, 0, strlen($code)-1) . "</span>\n";
                            } else {
                                $append = "<span class='$class'>$code</span>";
                            }
                        } else if ( $inString && $code !== '"' ) {
                            $append = "<span class='syntax-string'>$code</span>";
                        } else {
                            $append = $code;
                        }

                        if ( $inString ) {
                            $stringBuff[]= $append;
                        } else {
                            $html[]= $append;
                        }
                    }
                }

                if ( $stringBuff !== null ) {
                    $html[]= "<span class='syntax-string'>" . join('', $stringBuff) . '</span>';
                    $stringBuff = null;
                }

                return join( '', $html );
            }

            /**
             * Splits a given function name into it's 'class, function' parts.
             * If there is no class, then null is returned.
             * 
             * It also returns these parts in an array of: array( $className, $functionName );
             * 
             * Usage:
             * 
             *      list( $class, $function ) = ErrorHandler::splitFunction( $name );
             * 
             * @param name The function name to split.
             * @return An array containing class and function name.
             */
            private static function splitFunction( $name ) {
                $name = preg_replace( '/\\(\\)$/', '', $name );

                if ( strpos($name, '::') !== false ) {
                    $parts = explode( '::', $name );
                    $className = $parts[0];
                    $type = '::';
                    $functionName = $parts[1];
                } else if ( strpos($name, '->') !== false ) {
                    $parts = explode( '->', $name );
                    $className = $parts[0];
                    $type = '->';
                    $functionName = $parts[1];
                } else {
                    $className = null;
                    $type = null;
                    $functionName = $name;
                }

                return array( $className, $type, $functionName );
            }

            private static function newArgument( $name, $type=false, $isPassedByReference=false, $isOptional=false, $optionalValue=null, $highlight=false ) {
                if ( $name instanceof ReflectionParameter ) {
                    $highlight = func_num_args() > 1 ?
                            $highlight = $type :
                            false;

                    $klass = $name->getDeclaringClass();
                    $functionName = $name->getDeclaringFunction()->name;
                    if ( $klass !== null ) {
                        $klass = $klass->name;
                    }

                    $export = ReflectionParameter::export(
                            ( $klass ?
                                    array( "\\$klass", $functionName ) :
                                    $functionName ),
                            $name->name, 
                            true
                    );

                    $paramType = preg_replace('/.*?(\w+)\s+\$'.$name->name.'.*/', '\\1', $export);
                    if ( strpos($paramType, '[') !== false || strlen($paramType) === 0 ) {
                        $paramType = null;
                    }

                    return ErrorHandler::newArgument(
                            $name->name,
                            $paramType,
                            $name->isPassedByReference(),
                            $name->isDefaultValueAvailable(),
                            ( $name->isDefaultValueAvailable() ?
                                    var_export( $name->getDefaultValue(), true ) :
                                    null ),
                            ( func_num_args() > 1 ?
                                    $type :
                                    false )
                    );
                } else {
                    return array(
                            'name'              => $name,
                            'has_type'          => ( $type !== false ),
                            'type'              => $type,
                            'is_reference'      => $isPassedByReference,
                            'has_default'       => $isOptional,
                            'default_val'       => $optionalValue,
                            'is_highlighted'    => $highlight
                    );
                }
            }

            private static function syntaxHighlightFunctionMatch( $match, &$stackTrace, $highlightArg=null, &$numHighlighted=0 ) {
                list( $className, $type, $functionName ) = ErrorHandler::splitFunction( $match );

                // is class::method()
                if ( $className !== null ) {
                    $reflectFun = new ReflectionMethod( $className, $functionName );
                // is a function
                } else if ( $functionName === '{closure}' ) {
                    return '<span class="syntax-variable">$closure</span>';
                } else {
                    $reflectFun = new ReflectionFunction( $functionName );
                }

                if ( $reflectFun ) {
                    $params = $reflectFun->getParameters();

                    if ( $params ) {
                        $args = array();
                        $min = 0;
                        foreach( $params as $i => $param ) {
                            $arg = ErrorHandler::newArgument( $param );

                            if ( ! $arg['has_default'] ) {
                                $min = $i;
                            }

                            $args[]= $arg;
                        }

                        if ( $highlightArg !== null ) {
                            for ( $i = $highlightArg; $i <= $min; $i++ ) {
                                $args[$i]['is_highlighted'] = true;
                            }

                            $numHighlighted = $min-$highlightArg;
                        }

                        if ( $className !== null ) {
                            if ( $stackTrace && isset($stackTrace[1]) && isset($stackTrace[1]['type']) ) {
                                $type = htmlspecialchars( $stackTrace[1]['type'] );
                            }
                        } else {
                            $type = null;
                        }

                        return ErrorHandler::syntaxHighlightFunction( $className, $type, $functionName, $args );
                    }
                }

                return null;
            }

            /**
             * Returns the values given, as HTML, syntax highlighted.
             * It's a shorter, slightly faster, more no-nonsense approach
             * then 'syntaxHighlight'.
             * 
             * This is for syntax highlighting:
             *  - fun( [args] )
             *  - class->fun( [args] )
             *  - class::fun( [args] )
             * 
             * Class and type can be null, to denote no class, but are not optional.
             */
            private static function syntaxHighlightFunction( $class, $type, $fun, &$args=null ) {
                $info = array();

                // set the info
                if ( isset($class) && $class && isset($type) && $type ) {
                    if ( $type === '->' ) {
                        $type = '-&gt;';
                    }

                    $info []= "<span class='syntax-class'>$class</span>$type";
                }

                if ( isset($fun) && $fun ) {
                    $info []= "<span class='syntax-function'>$fun</span>";
                }

                if ( $args ) {
                    $info []= '( ';

                    foreach ($args as $i => $arg) {
                        if ( $i > 0 ) {
                            $info[]= ', ';
                        }

                        if ( is_string($arg) ) {
                            $info[]= $arg;
                        } else {
                            $highlight = $arg['is_highlighted'];
                            $name = $arg['name'];

                            if ( $highlight ) {
                                $info[]= '<span class="syntax-higlight-variable">';
                            }

                            if ( $name === '_' ) {
                                $info[]= '<span class="syntax-variable-not-important">';
                            }

                            if ( $arg['has_type'] ) {
                                $info []= "<span class='syntax-class'>";
                                    $info []= $arg['type'];
                                $info []= '</span> ';
                            }

                            if ( $arg['is_reference'] ) {
                                $info []= '<span class="syntax-function">&amp;</span>';
                            }

                            $info []= "<span class='syntax-variable'>\$$name</span>";

                            if ( $arg['has_default'] ) {
                                $info []= '=<span class="syntax-literal">' . $arg['default_val'] . '</span>';
                            }

                            if ( $name === '_' ) {
                                $info[]= '</span>';
                            }
                            if ( $highlight ) {
                                $info[]= '</span>';
                            }
                        }
                    }

                    $info []= ' )';
                } else {
                    $info []= '()';
                }

                return join( '', $info );
            }

            /**
             * Checks if the item is in options, and if it is, then it is removed and returned.
             * 
             * If it is not found, or if options is not an array, then the alt is returned.
             */
            private static function optionsPop( &$options, $key, $alt=null ) {
                if ( $options && isset($options[$key]) ) {
                    $val = $options[$key];
                    unset( $options[$key] );

                    return $val;
                } else {
                    $iniAlt = @get_cfg_var( ErrorHandler::PHP_ERROR_INI_PREFIX . '.' . $key );

                    if ( $iniAlt !== false ) {
                        return $iniAlt;
                    } else {
                        return $alt;
                    }
                }
            }

            private static function folderTypeToCSS( $type ) {
                if ( $type === ErrorHandler::FILE_TYPE_ROOT ) {
                    return 'file-root';
                } else if ( $type === ErrorHandler::FILE_TYPE_IGNORE ) {
                    return 'file-ignore';
                } else if ( $type === ErrorHandler::FILE_TYPE_APPLICATION ) {
                    return 'file-app';
                } else {
                    return 'file-common';
                }
            }

            private static function isFolderType( &$folders, $longest, $file ) {
                $parts = explode( '/', $file );

                $len = min( count($parts), $longest );

                for ( $i = $len; $i > 0; $i-- ) {
                    if ( isset($folders[$i]) ) {
                        $folderParts = &$folders[ $i ];

                        $success = false;
                        for ( $j = 0; $j < count($folderParts); $j++ ) {
                            $folderNames = $folderParts[$j];

                            for ( $k = 0; $k < count($folderNames); $k++ ) {
                                if ( $folderNames[$k] === $parts[$k] ) {
                                    $success = true;
                                } else {
                                    $success = false;
                                    break;
                                }
                            }
                        }

                        if ( $success ) {
                            return true;
                        }
                    }
                }

                return false;
            }

            private static function setFolders( &$origFolders, &$longest, $folders ) {
                $newFolders = array();
                $newLongest = 0;

                if ( $folders ) {
                    if ( is_array($folders) ) {
                        foreach ( $folders as $folder ) {
                            ErrorHandler::setFoldersInner( $newFolders, $newLongest, $folder );
                        }
                    } else if ( is_string($folders) ) {
                        ErrorHandler::setFoldersInner( $newFolders, $newLongest, $folders );
                    } else {
                        throw new Exception( "Unknown value given for folder: " . $folders );
                    }
                }

                $origFolders = $newFolders;
                $longest     = $newLongest;
            }

            private static function setFoldersInner( &$newFolders, &$newLongest, $folder ) {
                $folder = str_replace( '\\', '/', $folder );
                $folder = preg_replace( '/(^\\/+)|(\\/+$)/', '', $folder );
                $parts  = explode( '/', $folder );
                $count  = count( $parts );

                $newLongest = max( $newLongest, $count );
                
                if ( isset($newFolders[$count]) ) {
                    $folds = &$newFolders[$count];
                    $folds[]= $parts;
                } else {
                    $newFolders[$count] = array( $parts );
                }
            }

            private static function getRequestHeaders() {
                if ( function_exists('getallheaders') ) {
                    return getallheaders();
                } else {
                    $headers = array();
                    
                    foreach ( $_SERVER as $key => $value ) {
                        if ( strpos($key, 'HTTP_') === 0 ) {
                            $key = str_replace( " ", "-", ucwords(strtolower( str_replace("_", " ", substr($key, 5)) )) ); 
                            $headers[ $key ] = $value;
                        }
                    }

                    return $headers;
                }
            }

            private static function getResponseHeaders() {
                $headers = function_exists('apache_response_headers') ?
                        apache_response_headers() :
                        array() ;

                /*
                 * Merge the headers_list into apache_response_headers.
                 * 
                 * This is because sometimes things are in one, which are
                 * not present in the other.
                 */
                if ( function_exists('headers_list') ) {
                    $hList = headers_list();

                    foreach ($hList as $header) {
                        $header = explode(":", $header);
                        $headers[ array_shift($header) ] = trim( implode(":", $header) );
                    }
                }

                return $headers;
            }

            public static function identifyTypeHTML( $arg, $recurseLevels=1 ) {
                if ( ! is_array($arg) && !is_object($arg) ) {
                    if ( is_string($arg) ) {
                        return "<span class='syntax-string'>&quot;" . htmlentities($arg) . "&quot;</span>";
                    } else {
                        return "<span class='syntax-literal'>" . var_export( $arg, true ) . '</span>';
                    }
                } else if ( is_array($arg) ) {
                    if ( count($arg) === 0 ) {
                        return "[]";
                    } else if ( $recurseLevels > 0 ) {
                        $argArr = array();

                        foreach ($arg as $ag) {
                            $argArr[]= ErrorHandler::identifyTypeHTML( $ag, $recurseLevels-1 );
                        }

                        if ( ($recurseLevels % 2) === 0 ) {
                            return "["  . join(', ', $argArr) .  "]";
                        } else {
                            return "[ " . join(', ', $argArr) . " ]";
                        }
                    } else {
                        return "[...]";
                    }
                } else if ( get_class($arg) === 'Closure' ) {
                    return '<span class="syntax-variable">$Closure</span>()';
                } else {
                    $argKlass = get_class( $arg );

                    if ( preg_match(ErrorHandler::REGEX_PHP_CONST_IDENTIFIER, $argKlass) ) {
                        return '<span class="syntax-literal">$' . $argKlass . '</span>';
                    } else {
                        return '<span class="syntax-variable">$' . $argKlass . '</span>';
                    }
                }
            }

            private $cachedFiles;

            private $isShutdownRegistered;
            private $isOn;

            private $ignoreFolders = array();
            private $ignoreFoldersLongest = 0;

            private $applicationFolders = array();
            private $applicationFoldersLongest = 0;

            private $defaultErrorReportingOn;
            private $defaultErrorReportingOff;
            private $applicationRoot;
            private $serverName;

            private $catchClassNotFound;
            private $catchSurpressedErrors;
            private $catchAjaxErrors;

            private $backgroundText;
            private $numLines;

            private $displayLineNumber;
            private $htmlOnly;

            private $bufferOutput;
            private $isInEndBuffer;

            private $isAjax;

            private $lastGlobalErrorHandler;

            private $classNotFoundException;

            /**
             * = Options =
             * 
             * All options are optional, and so is passing in an options item.
             * You don't have to supply any, it's up to you.
             * 
             * Note that if 'php_error.force_disable' is true, then this object
             * will try to look like it works, but won't actually do anything.
             * 
             * All options can also be passed in from 'php.ini'. You do this
             * by setting it with 'php_error.' prefix. For example:
             * 
             *      php_error.catch_ajax_errors = On
             *      php_error.error_reporting_on = E_ALL | E_STRICT
             * 
             * Includes:
             *  = Types of errors this will catch =
             *  - catch_ajax_errors         When on, this will inject JS Ajax wrapping code, to allow this to catch any future JSON errors. Defaults to true.
             *  - catch_supressed_errors    The @ supresses errors. If set to true, then they are still reported anyway, but respected when false. Defaults to false.
             *  - catch_class_not_found     When true, loading a class that does not exist will be caught. This defaults to true.
             * 
             *  = Error reporting level =
             *  - error_reporting_on        value for when errors are on, defaults to all errors
             *  - error_reporting_off       value for when errors are off, defaults to php.ini's error_reporting.
             * 
             *  = Setup Details =
             *  - application_root          When it's working out hte stack trace, this is the root folder of the application, to use as it's base.
             *                              Defaults to the servers root directory.
             * 
             *                              A relative path can be given, but lets be honest, an explicit path is the way to guarantee that you
             *                              will get the path you want. My relative might not be the same as your relative.
             * 
             *  - snippet_num_lines         The number of lines to display in the code snippet. 
             *                              That includes the line being reported.
             * 
             *  - server_name               The name for this server, defaults to "$_SERVER['SERVER_NAME']"
             * 
             *  - ignore_folders            This is allows you to highlight non-framework code in a stack trace.
             *                              An array of folders to ignore, when working out the stack trace.
             *                              This is folder prefixes in relation to the application_root, whatever that might be.
             *                              They are only ignored if there is a file found outside of them.
             *                              If you still don't get what this does, don't worry, it's here cos I use it.
             * 
             *  - application_folders       Just like ignore, but anything found in these folders takes precedence
             *                              over anything else.
             * 
             *  - background_text           The text that appeares in the background. By default this is blank.
             *                              Why? You can replace this with the name of your framework, for extra customization spice.
             * 
             *  - html_only                 By default, PHP Error only runs on ajax and HTML pages.
             *                              If this is false, then it will also run when on non-HTML
             *                              pages too, such as replying with images of JavaScript
             *                              from your PHP. Defaults to true.
             * 
             * @param options Optional, an array of values to customize this handler.
             * @throws Exception This is raised if given an options that does *not* exist (so you know that option is meaningless).
             */
            public function __construct( $options=null ) {
                // there can only be one to rule them all
                global $_php_error_global_handler;
                if ( $_php_error_global_handler !== null ) {
                    $this->lastGlobalErrorHandler = $_php_error_global_handler;
                } else {
                    $this->lastGlobalErrorHandler = null;
                }
                $_php_error_global_handler = $this;

                $this->cachedFiles = array();

                $this->isShutdownRegistered = false;
                $this->isOn = false;

                /*
                 * Deal with the options.
                 * 
                 * They are removed one by one, and any left, will raise an error.
                 */

                $ignoreFolders                  = ErrorHandler::optionsPop( $options, 'ignore_folders'     , null );
                $appFolders                     = ErrorHandler::optionsPop( $options, 'application_folders', null );

                if ( $ignoreFolders !== null ) {
                    ErrorHandler::setFolders( $this->ignoreFolders, $this->ignoreFoldersLongest, $ignoreFolders );
                }
                if ( $appFolders !== null ) {
                    ErrorHandler::setFolders( $this->applicationFolders, $this->applicationFoldersLongest, $appFolders );
                }

                $this->defaultErrorReportingOn  = ErrorHandler::optionsPop( $options, 'error_reporting_on' , -1 );
                $this->defaultErrorReportingOff = ErrorHandler::optionsPop( $options, 'error_reporting_off', error_reporting() );

                $this->applicationRoot          = ErrorHandler::optionsPop( $options, 'application_root'   , $_SERVER['DOCUMENT_ROOT'] );
                $this->serverName               = ErrorHandler::optionsPop( $options, 'error_reporting_off', $_SERVER['SERVER_NAME']   );

                /*
                 * Relative paths might be given for document root,
                 * so we make it explicit.
                 */
                $dir = @realpath( $this->applicationRoot );
                if ( ! is_string($dir) ) {
                    throw new Exception("Document root not found: " . $this->applicationRoot);
                } else {
                    $this->applicationRoot =  str_replace( '\\', '/', $dir );
                }

                $this->catchClassNotFound       = !! ErrorHandler::optionsPop( $options, 'catch_class_not_found' , true  );
                $this->catchSurpressedErrors    = !! ErrorHandler::optionsPop( $options, 'catch_supressed_errors', false );
                $this->catchAjaxErrors          = !! ErrorHandler::optionsPop( $options, 'catch_ajax_errors'     , true  );

                $this->backgroundText           = ErrorHandler::optionsPop( $options, 'background_text'       , ''    );
                $this->numLines                 = ErrorHandler::optionsPop( $options, 'snippet_num_lines'     , ErrorHandler::NUM_FILE_LINES        );
                $this->displayLineNumber        = ErrorHandler::optionsPop( $options, 'display_line_numbers'  , false );

                $this->htmlOnly                 = !! ErrorHandler::optionsPop( $options, 'html_only', true );

                $this->classNotFoundException   = null;

                $wordpress = ErrorHandler::optionsPop( $options, 'wordpress', false );
                if ( $wordpress ) {
                    // php doesn't like | in constants and privates, so just set it directly : (
                    $this->defaultErrorReportingOn = E_ERROR | E_WARNING | E_PARSE | E_USER_DEPRECATED & ~E_DEPRECATED & ~E_STRICT;
                }

                if ( $options ) {
                    foreach ( $options as $key => $val ) {
                        throw new InvalidArgumentException( "Unknown option given $key" );
                    }
                }

                $this->isAjax =
                        isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) &&
                        ( $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' );

                $this->bufferOutput  = '';
                $this->isInEndBuffer = false;
                $this->startBuffer();
            }

            /*
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             * Public Functions
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             */

            /**
             * @return true if this is currently on, false if not.
             */
            public function isOn() {
                return $this->isOn;
            }

            /**
             * @return If this is off, this returns true, otherwise false.
             */
            public function isOff() {
                return !$this->isOn;
            }

            /**
             * Turns error reporting on.
             * 
             * This will use the strictest error reporting available, or the
             * level you pass in when creating this using the 'error_reporting_on'
             * option.
             * 
             * @return This error reporting handler, for method chaining.
             */
            public function turnOn() {
                $this->propagateTurnOff();
                $this->setEnabled( true );

                return $this;
            }

            /**
             * Turns error reporting off.
             * 
             * This will use the 'php.ini' setting for the error_reporting level,
             * or one you have passed in if you used the 'error_reporting_off'
             * option when creating this.
             * 
             * @return This error reporting handler, for method chaining.
             */
            public function turnOff() {
                $this->setEnabled( false );

                return $this;
            }

            /**
             * Allows you to run a callback with strict errors turned off.
             * Standard errors still apply, but this will use the default
             * error and exception handlers.
             * 
             * This is useful for when loading libraries which do not
             * adhere to strict errors, such as Wordpress.
             * 
             * To use:
             * 
             *      withoutErrors( function() {
             *          // unsafe code here
             *      });
             * 
             * This will use the error_reporting value for when this is
             * turned off.
             * 
             * @param callback A PHP function to call.
             * @return The result of calling the callback.
             */
            public function withoutErrors( $callback ) {
                if ( ! is_callable($callback) ) {
                    throw new Exception( "non callable callback given" );
                }

                if ( $this->isOn() ) {
                    $this->turnOff();
                    $result = $callback();
                    $this->turnOn();

                    return $result;
                } else {
                    return $callback();
                }
            }
            
            /**
             * This is the shutdown function, which should *only* be called 
             * via 'register_shutdown_function'.
             * 
             * It's exposed because it has to be exposed.
             */
            public function __onShutdown() {
                global $_php_error_is_ini_enabled;
                
                if ( $_php_error_is_ini_enabled ) {
                    if ( $this->isOn() ) {
                        $error = error_get_last();

                        // fatal and syntax errors
                        if (
                                $error && (
                                        $error['type'] ===  1 ||
                                        $error['type'] ===  4 ||
                                        $error['type'] === 64
                                )
                        ) {
                            $this->reportError( $error['type'], $error['message'], $error['line'], $error['file'] );
                        } else {
                            $this->endBuffer();
                        }
                    }
                }
            }

            /*
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             * Private Functions
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             */

            private function propagateTurnOff() {
                if ( $this->lastGlobalErrorHandler !== null ) {
                    $this->lastGlobalErrorHandler->turnOff();
                    $this->lastGlobalErrorHandler->propagateTurnOff();
                    $this->lastGlobalErrorHandler = null;
                }
            }
            
            /**
             * This is intended to be used closely with 'onShutdown'.
             * 
             * This ensures that output_buffering is turned on.
             */
            private function startBuffer() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled ) {
                    ini_set( 'implicit_flush', false );
                    ob_implicit_flush( false );

                    if ( ! @ini_get('output_buffering') ) {
                        ini_set( 'output_buffering', 'on' );
                    }

                    $output = '';
                    $inEndBuffer = false;
                    $this->bufferOutput  = &$output;
                    $this->isInEndBuffer = &$inEndBuffer;

                    ob_start( function($string) use (&$output, &$inEndBuffer) {
                        if ( $inEndBuffer ) {
                            $output = $string;
                            return '';
                        } else {
                            return $string;
                        }
                    });
                }
            }

            /**
             * This expects 'startBuffer' to have been called,
             * to ensure that 'output buffering' is already turned on.
             * 
             * This will inject JS into the output, before it is done.
             */
            private function endBuffer() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled ) {
                    if ( 
                            !$this->isAjax &&
                             $this->catchAjaxErrors &&
                            (!$this->htmlOnly || !ErrorHandler::isNonPHPRequest())
                    ) {
                        $content  = ob_get_contents();
                        $handlers = ob_list_handlers();

                        $wasGZHandler = false;
                        $this->isInEndBuffer = true;
                        for ( $i = count($handlers)-1; $i >= 0; $i-- ) {
                            $handler = $handlers[$i];

                            if ( $handler === 'ob_gzhandler' ) {
                                $wasGZHandler = true;
                                ob_end_clean();
                            } else if ( $handler === 'default output handler' ) {
                                ob_end_clean();
                            } else {
                                ob_end_flush();
                            }
                        }
                        $this->isInEndBuffer = false;

                        if ( $this->bufferOutput ) {
                            $content = $this->bufferOutput;
                        }

                        if ( $wasGZHandler ) {
                            ob_start('ob_gzhandler');
                        } else {
                            ob_start();
                        }
           
                        $js = $this->getContent( 'displayJSInjection' );
                        $js = JSMin::minify( $js );

                        // attemp to inject the script into the HTML, after the doctype
                        $matches = array();
                        preg_match( ErrorHandler::REGEX_DOCTYPE, $content, $matches );

                        if ( $matches ) {
                            $doctype = $matches[0];
                            $content = preg_replace( ErrorHandler::REGEX_DOCTYPE, "$doctype $js", $content );
                        } else {
                            echo $js;
                        }
          
                        echo $content;
                    }
                }
            }

            /**
             * Calls the given method on this object,
             * captures it's output, and then returns it.
             * 
             * @param method The name of the method to call.
             * @return All of the text outputted during the method call.
             */
            private function getContent( $method ) {
                ob_start();
                $this->$method();
                $content = ob_get_contents();
                ob_end_clean();

                return $content;
            }

            private function isApplicationFolder( $file ) {
                return ErrorHandler::isFolderType(
                        $this->applicationFolders,
                        $this->applicationFoldersLongest,
                        $file
                );
            }

            private function isIgnoreFolder( $file ) {
                return ErrorHandler::isFolderType(
                        $this->ignoreFolders,
                        $this->ignoreFoldersLongest,
                        $file
                );
            }

            private function getFolderType( $root, $file ) {
                $testFile = $this->removeRootPath( $root, $file );

                // it's this file : (
                if ( $file === __FILE__ ) {
                    $type = ErrorHandler::FILE_TYPE_IGNORE;
                } else if ( strpos($testFile, '/') === false ) {
                    $type = ErrorHandler::FILE_TYPE_ROOT;
                } else if ( $this->isApplicationFolder($testFile) ) {
                    $type = ErrorHandler::FILE_TYPE_APPLICATION;
                } else if ( $this->isIgnoreFolder($testFile) ) {
                    $type = ErrorHandler::FILE_TYPE_IGNORE;
                } else {
                    $type = false;
                }

                return array( $type, $testFile );
            }

            /**
             * Finds the file named, and returns it's contents in an array.
             * 
             * It's essentially the same as 'file_get_contents'. However
             * this will add caching at this PHP layer, avoiding lots of
             * duplicate calls.
             * 
             * It also splits the file into an array of lines, and makes
             * it html safe.
             * 
             * @param path The file to get the contents of.
             * @return The file we are after, as an array of lines.
             */
            private function getFileContents( $path ) {
                if ( isset($this->cachedFiles[$path]) ) {
                    return $this->cachedFiles[$path];
                } else {
                    $contents = @file_get_contents( $path );

                    if ( $contents ) {
                        $contents = explode(
                                "\n",
                                preg_replace(
                                        '/(\r\n)|(\n\r)|\r/',
                                        "\n",
                                        str_replace( "\t", '    ', $contents )
                                )
                        );

                        $this->cachedFiles[ $path ] = $contents;

                        return $contents;
                    }
                }

                return array();
            }

            /**
             * Reads out the code from the section of the line,
             * which is at fault.
             * 
             * The array is in a mapping of: array( line-number => line )
             * 
             * If something goes wrong, then null is returned.
             */
            private function readCodeFile( $errFile, $errLine ) {
                try {
                    $lines = $this->getFileContents( $errFile );

                    if ( $lines ) {
                        $numLines = $this->numLines;

                        $searchUp   = ceil( $numLines*0.75 );
                        $searchDown = $numLines - $searchUp;
                        
                        $countLines = count( $lines );

                        /*
                         * Search around the errLine.
                         * We should aim get half of the lines above, and half from below.
                         * If that fails we get as many as we can.
                         */

                        /*
                         * If we are near the bottom edge,
                         * we go down as far as we can,
                         * then work up the search area.
                         */
                        if ( $errLine+$searchDown > $countLines ) {
                            $minLine = max( 0, $countLines-$numLines );
                            $maxLine = $countLines;
                        /*
                         * Go up as far as we can, up to half the search area.
                         * Then stretch down the whole search area.
                         */
                        } else {
                            $minLine = max( 0, $errLine-$searchUp );
                            $maxLine = min( $minLine+$numLines, count($lines) );
                        }

                        $fileLines = array_splice( $lines, $minLine, $maxLine-$minLine );

                        $stripSize = -1;
                        foreach ( $fileLines as $i => $line ) {
                            $newLine = ltrim( $line, ' ' );

                            if ( strlen($newLine) > 0 ) {
                                $numSpaces = strlen($line) - strlen($newLine);

                                if ( $stripSize === -1 ) {
                                    $stripSize = $numSpaces;
                                } else {
                                    $stripSize = min( $stripSize, $numSpaces );
                                }
                            } else {
                                $fileLines[$i] = $newLine;
                            }
                        }
                        if ( $stripSize > 0 ) {
                            /*
                             * It's pretty common that PHP code is not flush with the left hand edge,
                             * so subtract 4 spaces, if we can,
                             * to account for this.
                             */
                            if ( $stripSize > 4 ) {
                                $stripSize -= 4;
                            }

                            foreach ( $fileLines as $i => $line ) {
                                if ( strlen($line) > $stripSize ) {
                                    $fileLines[$i] = substr( $line, $stripSize );
                                }
                            }
                        }

                        $fileLines = join( "\n", $fileLines );
                        $fileLines = ErrorHandler::syntaxHighlight( $fileLines );
                        $fileLines = explode( "\n", $fileLines );

                        $lines = array();
                        for ( $i = 0; $i < count($fileLines); $i++ ) {
                            // +1 is because line numbers start at 1, whilst arrays start at 0
                            $lines[ $i+$minLine+1 ] = $fileLines[$i];
                        }
                    }

                    return $lines;
                } catch ( Exception $ex ) {
                    return null;
                }

                return null;
            }

            /**
             * Attempts to remove the root path from the path given.
             * If the path can't be removed, then the original path is returned.
             * 
             * For example if root is 'C:/users/projects/my_site',
             * and the file is 'C:/users/projects/my_site/index.php',
             * then the root is removed, and we are left with just 'index.php'.
             * 
             * This is to remove line noise; you don't need to be told the
             * 'C:/whatever' bit 20 times.
             * 
             * @param root The root path to remove.
             * @param path The file we are removing the root section from.
             */
            private function removeRootPath( $root, $path ) {
                $filePath = str_replace( '\\', '/', $path );

                if (
                        strpos($filePath, $root) === 0 &&
                        strlen($root) < strlen($filePath)
                ) {
                    return substr($filePath, strlen($root)+1 );
                } else {
                    return $filePath;
                }
            }

            /**
             * Parses, and alters, the errLine, errFile and message given.
             * 
             * This includes adding syntax highlighting, removing duplicate
             * information we already have, and making the error easier to
             * read.
             */
            private function improveErrorMessage( $ex, $code, $message, $errLine, $errFile, $root, &$stackTrace ) {
                // change these to change where the source file is come from
                $srcErrFile = $errFile;
                $srcErrLine = $errLine;
                $altInfo = null;
                $stackSearchI = 0;

                $skipStackFirst = function( &$stackTrace ) {
                    $skipFirst = true;

                    foreach ( $stackTrace as $i => $trace ) {
                         if ( $skipFirst ) {
                              $skipFirst = false;
                         } else {
                              if ( $trace && isset($trace['file']) && isset($trace['line']) ) {
                                   return array( $trace['file'], $trace['line'], $i );
                              }
                        }
                    }

                    return array( null, null, null );
                };

                /*
                 * This is for calling a function that doesn't exist.
                 * 
                 * The message contains a long description of where this takes
                 * place, even though we are already told this through line and
                 * file info. So we cut it out.
                 */
                if ( $code === 1 ) {
                    if (
                            ( strpos($message, " undefined method ") !== false ) ||
                            ( strpos($message, " undefined function ") !== false )
                    ) {
                        $matches = array();
                        preg_match( '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((->|::)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/', $message, $matches );

                        /*
                         * undefined function or method call
                         */
                        if ( $matches ) {
                            list( $className, $type, $functionName ) = ErrorHandler::splitFunction( $matches[0] );

                            if ( $stackTrace && isset($stackTrace[1]) && $stackTrace[1]['args'] ) {
                                $numArgs = count( $stackTrace[1]['args'] );

                                for ( $i = 0; $i < $numArgs; $i++ ) {
                                    $args[]= ErrorHandler::newArgument( "_" );
                                }
                            }

                            $message = preg_replace(
                                    '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((->|::)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/',
                                    ErrorHandler::syntaxHighlightFunction( $className, $type, $functionName, $args ),
                                    $message
                            );
                        }
                    } else if ( $message === 'Using $this when not in object context' ) {
                        $message = 'Using <span class="syntax-variable">$this</span> outside object context';
                    /*
                     * Class not found error.
                     */
                    } else if (
                        strpos($message, "Class ") !== false &&
                        strpos($message, "not found") !== false
                    ) {
                        $matches = array();
                        preg_match( '/\'(\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+\'/', $message, $matches );

                        if ( count($matches) > 0 ) {
                            // lose the 'quotes'
                            $className = $matches[0];
                            $className = substr( $className, 1, strlen($className)-2 );

                            $message = preg_replace(
                                    '/\'(\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+\'/',
                                    "<span class='syntax-class'>$className</span>",
                                    $message
                            );
                        }
                    }
                } else if ( $code === 2 ) {
                    if ( strpos($message, "Missing argument ") === 0 ) {
                        $message = preg_replace( '/, called in .*$/', '', $message );

                        $matches = array();
                        preg_match( ErrorHandler::REGEX_METHOD_OR_FUNCTION_END, $message, $matches );

                        if ( $matches ) {
                            $argumentMathces = array();
                            preg_match( '/^Missing argument ([0-9]+)/', $message, $argumentMathces );
                            $highlightArg = count($argumentMathces) === 2 ?
                                    (((int) $argumentMathces[1])-1) :
                                    null ;

                            $numHighlighted = 0;
                            $altInfo = ErrorHandler::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg, $numHighlighted );

                            if ( $numHighlighted > 0 ) {
                                $message = preg_replace( '/^Missing argument ([0-9]+)/', 'Missing arguments ', $message );
                            }

                            if ( $altInfo ) {
                                $message = preg_replace( ErrorHandler::REGEX_METHOD_OR_FUNCTION_END, $altInfo, $message );

                                list( $srcErrFile, $srcErrLine, $stackSearchI ) = $skipStackFirst( $stackTrace );
                            }
                        }
                    } else if (
                            strpos($message, 'require(') === 0 ||
                            strpos($message, 'include(') === 0
                    ) {
                        $endI  = strpos( $message, '):' );

                        if ( $endI ) {
                            // include( is the same length
                            $requireLen = strlen('require(');

                            /*
                             * +2 to include the ): at the end of the string
                             */
                            $postMessage = substr( $message, $endI+2 );
                            $postMessage = str_replace( 'failed to open stream: No ', 'no ', $postMessage );
                            $message = substr_replace( $message, $postMessage, $endI+2 );

                            /*
                             * If this string is in there, and where we think it should be,
                             * swap it with a shorter message.
                             */
                            $replaceBit = 'failed to open stream: No ';
                            if ( strpos($message, $replaceBit) === $endI+2 ) {
                                $message  = substr_replace( $message, 'no ', $endI+2, strlen($replaceBit) );
                            }

                            /*
                             * Now put the string highlighting in there.
                             */
                            $match = substr( $message, $requireLen, $endI-$requireLen );
                            $newString = "<span class='syntax-string'>'$match'</span>),";
                            $message  = substr_replace( $message, $newString, $requireLen, ($endI-$requireLen)+2 );
                        }
                    }
                /*
                 * Unexpected symbol errors.
                 * For example 'unexpected T_OBJECT_OPERATOR'.
                 * 
                 * This swaps the 'T_WHATEVER' for the symbolic representation.
                 */
                } else if ( $code === 4 ) {
                    if ( $message === "syntax error, unexpected T_ENCAPSED_AND_WHITESPACE" ) {
                        $message = "syntax error, string is not closed";
                    } else {
                        $semiColonError = false;
                        if ( strpos($message, 'syntax error,') === 0 && $errLine > 2 ) {
                            $lines = ErrorHandler::getFileContents( $errFile );
                            
                            $line = $lines[$errLine-1];
                            if ( preg_match( ErrorHandler::REGEX_MISSING_SEMI_COLON_FOLLOWING_LINE, $line ) !== 0 ) {
                                $content = rtrim( join( "\n", array_slice($lines, 0, $errLine-1) ) );

                                if ( strrpos($content, ';') !== strlen($content)-1 ) {
                                    $message = "Missing semi-colon";
                                    $errLine--;
                                    $srcErrLine = $errLine;
                                    $semiColonError = true;
                                }
                            }
                        }

                        if ( $semiColonError ) {
                            $matches = array();
                            $num = preg_match( '/\bunexpected ([A-Z_]+|\\$end)\b/', $message, $matches );

                            if ( $num > 0 ) {
                                $match = $matches[0];
                                $newSymbol = ErrorHandler::phpSymbolToDescription( str_replace('unexpected ', '', $match) );

                                $message = str_replace( $match, "unexpected $newSymbol", $message );
                            }

                            $matches = array();
                            $num = preg_match( '/, expecting ([A-Z_]+|\\$end)( or ([A-Z_]+|\\$end))*/', $message, $matches );

                            if ( $num > 0 ) {
                                $match = $matches[0];
                                $newMatch = str_replace( ", expecting ", '', $match );
                                $symbols = explode( ' or ', $newMatch );
                                foreach ( $symbols as $i => $sym ) {
                                    $symbols[$i] = ErrorHandler::phpSymbolToDescription( $sym );
                                }
                                $newMatch = join( ', or ', $symbols );

                                $message = str_replace( $match, ", expecting $newMatch", $message );
                            }
                        }
                    }
                /**
                 * Undefined Variable, add syntax highlighting and make variable from 'foo' too '$foo'.
                 */
                } else if ( $code === 8 ) {
                    if (
                        strpos($message, "Undefined variable:") !== false
                    ) {
                        $matches = array();
                        preg_match( ErrorHandler::REGEX_VARIABLE, $message, $matches );

                        if ( count($matches) > 0 ) {
                            $message = 'Undefined variable <span class="syntax-variable">$' . $matches[0] . '</span>' ;
                        }
                    }
                /**
                 * Invalid type given.
                 */
                } else if ( $code === 4096 ) {
                    if ( strpos($message, 'must be an ') ) {
                        $message = preg_replace( '/, called in .*$/', '', $message );

                        $matches = array();
                        preg_match( ErrorHandler::REGEX_METHOD_OR_FUNCTION, $message, $matches );

                        if ( $matches ) {
                            $argumentMathces = array();
                            preg_match( '/^Argument ([0-9]+)/', $message, $argumentMathces );
                            $highlightArg = count($argumentMathces) === 2 ?
                                    (((int) $argumentMathces[1])-1) :
                                    null ;

                            $fun = ErrorHandler::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg );

                            if ( $fun ) {
                                $message = str_replace( 'passed to ', 'calling ', $message );
                                $message = preg_replace( ErrorHandler::REGEX_METHOD_OR_FUNCTION, $fun, $message );
                                $prioritizeCaller = true;

                                /*
                                 * scalars not supported.
                                 */
                                $scalarType = null;
                                if ( ! ErrorHandler::$IS_SCALAR_TYPE_HINTING_SUPPORTED ) {
                                    foreach ( ErrorHandler::$SCALAR_TYPES as $scalar ) {
                                        if ( stripos($message, "must be an instance of $scalar,") !== false ) {
                                            $scalarType = $scalar;
                                            break;
                                        }
                                    }
                                }

                                if ( $scalarType !== null ) {
                                    $message = preg_replace( '/^Argument [0-9]+ calling /', 'Incorrect type hinting for ', $message );
                                    $message = preg_replace(
                                            '/ must be an instance of ' . ErrorHandler::REGEX_PHP_IDENTIFIER . '\b.*$/',
                                            ", ${scalarType} is not supported",
                                            $message
                                    );

                                    $prioritizeCaller = false;
                                } else {
                                    $message = preg_replace( '/ must be an (instance of )?' . ErrorHandler::REGEX_PHP_IDENTIFIER . '\b/', '', $message );

                                    if ( preg_match('/, none given$/', $message) ) {
                                        $message = preg_replace( '/^Argument /', 'Missing argument ', $message );
                                        $message = preg_replace( '/, none given$/', '', $message );
                                    } else {
                                        $message = preg_replace( '/^Argument /', 'Incorrect argument ', $message );
                                    }
                                }

                                if ( $prioritizeCaller ) {
                                    list( $srcErrFile, $srcErrLine, $stackSearchI ) = $skipStackFirst( $stackTrace );
                                }
                            }
                        }
                    }
                }

                if ( $stackTrace !== null ) {
                    $isEmpty = count( $stackTrace ) === 0 ;

                    if ( $isEmpty ) {
                        array_unshift( $stackTrace, array(
                                'line' => $errLine,
                                'file' => $errFile
                        ) );
                    } else if (
                            count($stackTrace) > 0 && (
                                    (! isset($stackTrace[0]['line'])) ||
                                    ($stackTrace[0]['line'] !== $errLine)
                            )
                    ) {
                        array_unshift( $stackTrace, array(
                                'line' => $errLine,
                                'file' => $errFile
                        ) );
                    }

                    if ( $stackTrace && !$isEmpty ) {
                        $ignoreCommons = false;
                        $len = count($stackTrace);

                        /*
                         * The code above can prioritize a location in the stack trace,
                         * this is 'stackSearchI'. So we should start our search from there,
                         * and work down the stack.
                         * 
                         * This is built in a way so that when it reaches the end, it'll loop
                         * back round to the beginning, and check the traces we didn't check
                         * last time.
                         * 
                         * If stackSearchI was not altered, then it just searches from top
                         * through to the bottom.
                         */
                        for ( $i = $stackSearchI; $i < $stackSearchI+$len; $i++ ) {
                            $trace = &$stackTrace[ $i % $len ];

                            if ( isset($trace['file']) && isset($trace['line']) ) {
                                list( $type, $_ ) = $this->getFolderType( $root, $trace['file'] );

                                if ( $type !== ErrorHandler::FILE_TYPE_IGNORE ) {
                                    if ( $type === ErrorHandler::FILE_TYPE_APPLICATION ) {
                                        $srcErrLine = $trace['line'];
                                        $srcErrFile = $trace['file'];

                                        break;
                                    } else if ( ! $ignoreCommons ) {
                                        $srcErrLine = $trace['line'];
                                        $srcErrFile = $trace['file'];

                                        $ignoreCommons = true;
                                    }
                                }
                            }
                        }
                    }
                }

                return array( $message, $srcErrFile, $srcErrLine, $altInfo );
            }

            /**
             * Parses the stack trace, and makes it look pretty.
             * 
             * This includes adding in the syntax highlighting,
             * highlighting the colours for the files,
             * and padding with whitespace.
             *
             * If stackTrace is null, then null is returned.
             */
            private function parseStackTrace( $code, $message, $errLine, $errFile, &$stackTrace, $root, $altInfo=null ) {
                if ( $stackTrace !== null ) {
                    /*
                     * For whitespace padding.
                     */
                    $lineLen = 0;
                    $fileLen = 0;

                    // parse the stack trace, and remove the long urls
                    foreach ( $stackTrace as $i => $trace ) {
                        if ( $trace ) {
                            if ( isset($trace['line'] ) ) {
                                $lineLen = max( $lineLen, strlen($trace['line']) );
                            } else {
                                $trace['line'] = '';
                            }

                            $info = '';

                            if ( $i === 0 && $altInfo !== null ) {
                                $info = $altInfo;
                            /*
                             * Skip for the first iteration,
                             * as it's usually magical PHP calls.
                             */
                            } else if (
                                $i > 0 && (
                                        isset($trace['class']) ||
                                        isset($trace['type']) ||
                                        isset($trace['function'])
                                )
                            ) {
                                $args = array();
                                if ( isset($trace['args']) ) {
                                    foreach ( $trace['args'] as $arg ) {
                                        $args[]= ErrorHandler::identifyTypeHTML( $arg, 1 );
                                    }
                                }

                                $info = ErrorHandler::syntaxHighlightFunction(
                                        isset($trace['class'])      ? $trace['class']       : null,
                                        isset($trace['type'])       ? $trace['type']        : null,
                                        isset($trace['function'])   ? $trace['function']    : null,
                                        $args
                                );
                            } else if ( isset($trace['info']) && $trace['info'] !== '' ) {
                                $info = ErrorHandler::syntaxHighlight( $trace['info'] );
                            } else if ( isset($trace['file']) && !isset($trace['info']) ) {
                                $contents = $this->getFileContents( $trace['file'] );

                                if ( $contents ) {
                                    $info = ErrorHandler::syntaxHighlight(
                                            trim( $contents[$trace['line']-1] )
                                    );
                                }
                            } 

                            $trace['info'] = $info;

                            if ( isset($trace['file']) ) {
                                list( $type, $file ) = $this->getFolderType( $root, $trace['file'] );

                                $trace['file_type'] = $type;
                                $trace['is_native'] = false;
                            } else {
                                $file = '[Internal PHP]';

                                $trace['file_type'] = '';
                                $trace['is_native'] = true;
                            }

                            $trace['file'] = $file;

                            $fileLen = max( $fileLen, strlen($file) );

                            $stackTrace[$i] = $trace;
                        }
                    }

                    /*
                     * We are allowed to highlight just once, that's it.
                     */
                    $highlightI = -1;
                    foreach ( $stackTrace as $i => $trace ) {
                        if (
                                $trace['line'] === $errLine &&
                                $trace['file'] === $errFile
                        ) {
                            $highlightI = $i;
                            break;
                        }
                    }

                    foreach ( $stackTrace as $i => $trace ) {
                        if ( $trace ) {
                            // line
                            $line = str_pad( $trace['line']     , $lineLen, ' ', STR_PAD_LEFT  );

                            // file
                            $file = $trace['file'];
                            $fileKlass = '';
                            if ( $trace['is_native'] ) {
                                $fileKlass = 'file-internal-php';
                            } else {
                                $fileKlass = 'filename ' . ErrorHandler::folderTypeToCSS( $trace['file_type'] );
                            }
                            $file = $file . str_pad( '', $fileLen-strlen($file), ' ', STR_PAD_LEFT );

                            // info
                            $info = $trace['info'];
                            if ( $info ) {
                                $info = str_replace( "\n", '\n', $info );
                                $info = str_replace( "\r", '\r', $info );
                            } else {
                                $info = '&nbsp;';
                            }

                            // line + file + info
                            $stackStr =
                                    "<td class='linenumber'>$line</td>" .
                                    "<td class='$fileKlass'>$file</td>" .
                                    "<td class='lineinfo'>$info</td>"   ;

                            if ( $trace['is_native'] ) {
                                $cssClass = 'is-native ';
                            } else {
                                $cssClass = '';
                            }

                            if ( $highlightI === $i ) {
                                $cssClass .= ' highlight';
                            } else if ( $highlightI > $i ) {
                                $cssClass .= ' pre-highlight';
                            }

                            if (
                                    $i !== 0 &&
                                    isset($trace['exception']) &&
                                    $trace['exception']
                            ) {
                                $ex = $trace['exception'];

                                $exHtml = '<tr class="error-stack-trace-exception"><td>' .
                                            'exception &quot;' .
                                            htmlspecialchars( $ex->getMessage() ) .
                                            '&quot;' .
                                        '</td></tr>';
                            } else {
                                $exHtml = '';
                            }

                            $data = '';
                            if ( isset($trace['file-lines-id']) ) {
                                $data = 'data-file-lines-id="' . $trace['file-lines-id'] . '"';
                            }

                            $stackTrace[$i] = "$exHtml<tr class='error-stack-trace-line $cssClass' $data>$stackStr</tr>";
                        }
                    }

                    return '<table id="error-stack-trace">' . join( "", $stackTrace ) . '</table>';
                } else {
                    return null;
                }
            }

            private function logError( $message, $file, $line, $ex=null ) {
                if ( $ex ) {
                    $trace = $ex->getTraceAsString();
                    $parts = explode( "\n", $trace );
                    $trace = "        " . join( "\n        ", $parts );

                    error_log( "$message \n           $file, $line \n$trace" );
                } else {
                    error_log( "$message \n           $file, $line" );
                }
            }

            /**
             * Given a class name, which can include a namespace,
             * this will report that it is not found.
             * 
             * This will also report it as an exception,
             * so you will get a full stack trace.
             */
            public function reportClassNotFound( $className ) {
                throw new ErrorException( "Class '$className' not found", E_ERROR, E_ERROR, __FILE__, __LINE__ );
            }

            /**
             * Given an exception, this will report it.
             */
            public function reportException( $ex ) {
                $this->reportError(
                        $ex->getCode(),
                        $ex->getMessage(),
                        $ex->getLine(),
                        $ex->getFile(),
                        $ex
                );
            }

            /**
             * The entry point for handling an error.
             * 
             * This is the lowest entry point for error reporting,
             * and for that reason it can either take just error info,
             * or a combination of error and exception information.
             * 
             * Note that this will still log errors in the error log
             * even when it's disabled with ini. It just does nothing
             * more than that.
             */
            public function reportError( $code, $message, $errLine, $errFile, $ex=null ) {
                if (
                        $ex === null &&
                        $code === 1 &&
                        strpos($message, "Class ") === 0 &&
                        strpos($message, "not found") !== false &&
                        $this->classNotFoundException !== null
                ) {
                    $ex = $this->classNotFoundException;

                    $code       = $ex->getCode();
                    $message    = $ex->getMessage();
                    $errLine    = $ex->getLine();
                    $errFile    = $ex->getFile();
                    $stackTrace = $ex->getTrace();
                }

                $this->logError( $message, $errFile, $errLine, $ex );

                /**
                 * It runs if:
                 *  - it is globally enabled
                 *  - this error handler is enabled
                 *  - we believe it is a regular html request, or ajax
                 */
                global $_php_error_is_ini_enabled;
                if (
                        $_php_error_is_ini_enabled &&
                        $this->isOn() && (
                                $this->isAjax ||
                                !$this->htmlOnly ||
                                !ErrorHandler::isNonPHPRequest()
                        )
                ) {
                    $root = $this->applicationRoot;

                    list( $ex, $stackTrace, $code, $errFile, $errLine ) =
                            $this->getStackTrace( $ex, $code, $errFile, $errLine );

                    list( $message, $srcErrFile, $srcErrLine, $altInfo ) =
                            $this->improveErrorMessage(
                                    $ex,
                                    $code,
                                    $message,
                                    $errLine,
                                    $errFile,
                                    $root,
                                    $stackTrace
                            );

                    $errFile = $srcErrFile;
                    $errLine = $srcErrLine;

                    list( $fileLinesSets, $numFileLines ) = $this->generateFileLineSets( $srcErrFile, $srcErrLine, $stackTrace );

                    list( $type, $errFile ) = $this->getFolderType( $root, $errFile );
                    $errFileType = ErrorHandler::folderTypeToCSS( $type );

                    $stackTrace = $this->parseStackTrace( $code, $message, $errLine, $errFile, $stackTrace, $root, $altInfo );
                    $fileLines  = $this->readCodeFile( $srcErrFile, $srcErrLine );

                    // load the session, if it's there
                    if (isset($_COOKIE[session_name()]) && empty($_SESSION)) {
                        session_start();
                    }

                    $request  = ErrorHandler::getRequestHeaders();
                    $response = ErrorHandler::getResponseHeaders();

                    $dump = $this->generateDumpHTML(
                            array(
                                    'post'    => ( isset($_POST)    ? $_POST    : array() ),
                                    'get'     => ( isset($_GET)     ? $_GET     : array() ),
                                    'session' => ( isset($_SESSION) ? $_SESSION : array() ),
                                    'cookies' => ( isset($_COOKIE)  ? $_COOKIE  : array() )
                            ),

                            $request,
                            $response,

                            $_SERVER
                    );
                    $this->displayError( $message, $srcErrLine, $errFile, $errFileType, $stackTrace, $fileLinesSets, $numFileLines, $dump );

                    // exit in order to end processing
                    $this->turnOff();
                    exit(0);
                }
            }

            private function getStackTrace( $ex, $code, $errFile, $errLine ) {
                $stackTrace = null;

                if ( $ex !== null ) {
                    $next = $ex;
                    $stackTrace = array();
                    $skipStacks = 0;

                    for (
                            $next = $ex;
                            $next !== null;
                            $next = $next->getPrevious()
                    ) {
                        $ex = $next;

                        $stack = $ex->getTrace();
                        $file  = $ex->getFile();
                        $line  = $ex->getLine();

                        if ( $stackTrace !== null && count($stackTrace) > 0 ) {
                            $stack = array_slice( $stack, 0, count($stack)-count($stackTrace) + 1 );
                        }

                        if ( count($stack) > 0 && (
                            !isset($stack[0]['file']) ||
                            !isset($stack[0]['line']) ||
                            $stack[0]['file'] !== $file ||
                            $stack[0]['line'] !== $line
                        ) ) {
                            array_unshift( $stack, array(
                                    'file' => $file,
                                    'line' => $line
                            ) );
                        }

                        $stackTrace = ( $stackTrace !== null ) ?
                                array_merge( $stack, $stackTrace ) :
                                $stack ;

                        if ( count($stackTrace) > 0 ) {
                            $stackTrace[0]['exception'] = $ex;
                        }
                    }

                    $message = $ex->getMessage();
                    $errFile = $ex->getFile();
                    $errLine = $ex->getLine();

                    $code = method_exists($ex, 'getSeverity') ?
                            $ex->getSeverity() :
                            $ex->getCode()     ;
                }

                return array( $ex, $stackTrace, $code, $errFile, $errLine );
            }

            private function generateDumpHTML( $arrays, $request, $response, $server ) {
                $arrToHtml = function( $name, $array, $css='' ) {
                    $max = 0;

                    foreach ( $array as $e => $v ) {
                        $max = max( $max, strlen( $e ) );
                    }

                    $snippet = "<h2 class='error_dump_header'>$name</h2>";

                    foreach ( $array as $e => $v ) {
                        $e = str_pad( $e, $max, ' ', STR_PAD_RIGHT );

                        $e = htmlentities( $e );
                        $v = ErrorHandler::identifyTypeHTML( $v, 3 );

                        $snippet .= "<div class='error_dump_key'>$e</div><div class='error_dump_mapping'>=&gt;</div><div class='error_dump_value'>$v</div>";
                    }

                    return "<div class='error_dump $css'>$snippet</div>";
                };

                $html = '';
                foreach ( $arrays as $key => $value ) {
                    if ( isset($value) && $value ) {
                        $html .= $arrToHtml( $key, $value );
                    } else {
                        unset($arrays[$key]);
                    }
                }

                return "<div class='error_dumps'>" .
                            $html .
                            $arrToHtml( 'request', $request, 'dump_request' ) .
                            $arrToHtml( 'response', $response, 'dump_response' ) .
                            $arrToHtml( 'server', $server, 'dump_server' ) .
                        "</div>";
            }

            private function generateFileLineSets( $srcErrFile, $srcErrLine, &$stackTrace ) {
                $fileLineID = 1;
                $srcErrID = "file-line-$fileLineID";
                $fileLineID++;

                $lines = $this->readCodeFile( $srcErrFile, $srcErrLine );
                $minSize = count( $lines );
                $fileLinesSets = array( new FileLinesSet( $srcErrLine, $srcErrID, $lines, true ) );

                if ( $stackTrace ) {
                    foreach ( $stackTrace as $i => &$trace ) {
                        if ( $trace && isset($trace['file']) && isset($trace['line']) ) {
                            $file = $trace['file'];
                            $line = $trace['line'];

                            if ( $file === $srcErrFile && $line === $srcErrLine ) {
                                $trace['file-lines-id'] = $srcErrID;
                            } else {
                                $traceFileID = "file-line-$fileLineID";
                                $trace['file-lines-id'] = $traceFileID;

                                $lines = $this->readCodeFile( $file, $line );
                                $minSize = max( $minSize, count($lines) );
                                $fileLinesSets[]= new FileLinesSet( $line, $traceFileID, $lines, false );

                                $fileLineID++;
                            }
                        }
                    }
                }

                return array( $fileLinesSets, $minSize );
            }

            /*
             * Even if disabled, we still act like reporting is on,
             * if it's turned on.
             * 
             * We just don't do anything.
             */
            private function setEnabled( $isOn ) {
                $wasOn = $this->isOn;
                $this->isOn = $isOn;

                global $_php_error_is_ini_enabled;
                if ( $_php_error_is_ini_enabled ) {
                    /*
                     * Only turn off, if we're moving from on to off.
                     * 
                     * This is so if it's turned off without turning on,
                     * we don't change anything.
                     */
                    if ( !$isOn ) {
                        if ( $wasOn ) {
                            $this->runDisableErrors();
                        }
                    /*
                     * Always turn it on, even if already on.
                     * 
                     * This is incase it was messed up in some way
                     * by the user.
                     */
                    } else if ( $isOn ) {
                        $this->runEnableErrors();
                    }
                }
            }

            private function runDisableErrors() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled ) {
                    error_reporting( $this->defaultErrorReportingOff );

                    @ini_restore( 'html_errors' );
                }
            }

            /*
             * Now the actual hooking into PHP's error reporting.
             * 
             * We enable _ALL_ errors, and make them all exceptions.
             * We also need to hook into the shutdown function so
             * we can catch fatal and compile time errors.
             */
            private function runEnableErrors() {
                global $_php_error_is_ini_enabled;
                
                if ( $_php_error_is_ini_enabled ) {
                    $catchSurpressedErrors = &$this->catchSurpressedErrors;
                    $self = $this;

                    // all errors \o/ !
                    error_reporting( $this->defaultErrorReportingOn );
                    @ini_set( 'html_errors', false );

                    set_error_handler(
                            function( $code, $message, $file, $line, $context ) use ( $self, &$catchSurpressedErrors ) {
                                /*
                                 * DO NOT! log the error.
                                 * 
                                 * Either it's thrown as an exception, and so logged by the exception handler,
                                 * or we return false, and it's logged by PHP.
                                 * 
                                 * Also DO NOT! throw an exception, instead report it.
                                 * This is because if an operation raises both a user AND
                                 * fatal error (such as require), then the exception is
                                 * silently ignored.
                                 */
                                if ( $self->isOn() ) {
                                    /*
                                     * When using an @, the error reporting drops to 0.
                                     */
                                    if ( error_reporting() !== 0 || $catchSurpressedErrors ) {
                                        $ex = new ErrorException( $message, $code, $code, $file, $line );

                                        $self->reportException( $ex );
                                    }
                                } else {
                                    return false;
                                }
                            },
                            $this->defaultErrorReportingOn 
                    );

                    set_exception_handler( function($ex) use ( $self ) {
                        if ( $self->isOn() ) {
                            $self->reportException( $ex );
                        } else {
                            return false;
                        }
                    });

                    if ( ! $self->isShutdownRegistered ) {
                        if ( $self->catchClassNotFound ) {
                            $classException = &$self->classNotFoundException;
                            $autoloaderFuns = ErrorHandler::$SAFE_AUTOLOADER_FUNCTIONS;

                            /*
                             * When this is called, the key point is that we don't error!
                             *
                             * Instead we record that an error has occurred,
                             * if we believe one has, and then let PHP error as normal.
                             * The stack trace we record is then used later.
                             *
                             * This is done for two reasons:
                             *  - functions like 'class_exists' will run the autoloader, and we shouldn't error on them
                             *  - on PHP 5.3.0, the class loader registered functions does *not* return closure objects, so we can't do anything clever.
                             * 
                             * So we watch, but don't touch.
                             */
                            spl_autoload_register( function($className) use ( $self, &$classException, &$autoloaderFuns ) {
                                if ( $self->isOn() ) {
                                    $classException = null;

                                    // search the stack first, to check if we are running from 'class_exists' before we error
                                    if ( defined('DEBUG_BACKTRACE_IGNORE_ARGS') ) {
                                        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
                                    } else {
                                        $trace = debug_backtrace();
                                    }
                                    $error = true;

                                    foreach ( $trace as $row ) {
                                        if ( isset($row['function']) ) {
                                            $function = $row['function'];

                                            // they are just checking, so don't error
                                            if ( in_array($function, $autoloaderFuns, true) ) {
                                                $error = false;
                                                break;
                                            // not us, and not the autoloader, so error!
                                            } else if (
                                                    $function !== '__autoload' &&
                                                    $function !== 'spl_autoload_call' &&
                                                    strpos($function, 'php_error\\') === false
                                            ) {
                                                break;
                                            }
                                        }
                                    }

                                    if ( $error ) {
                                        $classException = new ErrorException( "Class '$className' not found", E_ERROR, E_ERROR, __FILE__, __LINE__ );
                                    }
                                }
                            } );
                        }

                        register_shutdown_function( function() use ( $self ) {
                            $self->__onShutdown();
                        });

                        $self->isShutdownRegistered = true;
                    }
                }
            }

            private function displayJSInjection() {
                ?><script data-php_error="magic JS, just ignore this!">
                    "use strict";

                    (function( window ) {
                        if ( window.XMLHttpRequest ) {
                            /** 
                             * A method wrapping helper function.
                             * 
                             * Wraps the method given, from the old prototype to the new
                             * XMLHttpRequest prototype.
                             * 
                             * This only happens if the old one actually has that prototype.
                             * If the browser doesn't support a prototype, then it doesn't
                             * get wrapped.
                             */
                            var wrapMethod = function( XMLHttpRequest, old, prop ) {
                                if ( old.prototype[prop] ) {
                                    var behaviours = ( arguments.length > 3 ) ?
                                            Array.prototype.slice.call( arguments, 3 ) :
                                            null ;

                                    XMLHttpRequest.prototype[prop] = function() {
                                        if ( behaviours !== null ) {
                                            for ( var i = 0; i < behaviours.length; i++ ) {
                                                behaviours[i].call( this, arguments, prop );
                                            }
                                        }

                                        return this.__.inner[prop].apply( this.__.inner, arguments );
                                    };
                                }
                            }

                            var postMethod = function( XMLHttpRequest, prop ) {
                                if ( XMLHttpRequest.prototype[prop] ) {
                                    var behaviours = Array.prototype.slice.call( arguments, 2 );

                                    var previous = XMLHttpRequest.prototype[prop];
                                    XMLHttpRequest.prototype[prop] = function() {
                                        var r = previous.apply( this, arguments );

                                        for ( var i = 0; i < behaviours.length; i++ ) {
                                            behaviours[i].call( this, arguments, prop );
                                        }

                                        return r;
                                    };
                                }
                            }

                            /*
                             * Certain properties will error when read,
                             * and which ones do vary from browser to browser.
                             * 
                             * I've found both Chrome and Firefox will error
                             * on _different_ properties.
                             * 
                             * So every read needs to be wrapped in a try/catch,
                             * and just hope it doesn't error.
                             */
                            var copyProperties = function( src, dest, props ) {
                                for ( var i = 0; i < props.length; i++ ) {
                                    try {
                                        var prop = props[i];
                                        dest[prop] = src[prop];
                                    } catch( ex ) { }
                                }
                            };

                            var copyResponseProperties = function( src, dest ) {
                                copyProperties( src, dest, [
                                        'response',
                                        'responseText',
                                        'responseXML'
                                ]);
                            }

                            var copyRequestProperties = function( src, dest, includeReadOnly, skipResponse ) {
                                copyProperties( src, dest, [
                                        'readyState',
                                        'timeout',
                                        'upload',
                                        'withCredentials',
                                        'responseType',

                                        'mozBackgroundRequest',
                                        'mozArrayBuffer',
                                        'multipart'
                                ]);

                                if ( includeReadOnly ) {
                                    copyProperties( src, dest, [
                                            'status',
                                            'statusText',
                                            'channel'
                                    ]);

                                    if ( ! skipResponse ) {
                                        copyResponseProperties( src, dest );
                                    }
                                }

                                return dest;
                            }

                            var runFail = function( ev ) {
                                var self = this;
                                var xmlHttpRequest = this.__.inner;

                                var iframe = document.createElement('iframe');
                                iframe.setAttribute('width', '100%');
                                iframe.setAttribute('height', '100%');
                                iframe.setAttribute('src', 'about:blank');

                                iframe.style.transition =
                                iframe.style.OTransition =
                                iframe.style.MsTransition =
                                iframe.style.MozTransition =
                                iframe.style.WebkitTransition = 'opacity 200ms linear';

                                iframe.style.background = 'transparent';
                                iframe.style.opacity = 0;
                                iframe.style.zIndex = 100000;
                                iframe.style.top = 0;
                                iframe.style.right = 0;
                                iframe.style.left = 0;
                                iframe.style.bottom = 0;
                                iframe.style.position = 'fixed';

                                var response = xmlHttpRequest.responseText;

                                iframe.onload = function() {
                                    var iDoc = iframe.contentWindow || iframe.contentDocument;
                                    if ( iDoc.document) {
                                        iDoc = iDoc.document;
                                    }

                                    var iBody = iDoc.getElementsByTagName("body")[0];
                                    iBody.innerHTML = response; 
                                    var iHead = iDoc.getElementsByTagName("head")[0];

                                    // re-run the script tags
                                    var scripts = iDoc.getElementsByTagName('script');
                                    for ( var i = 0; i < scripts.length; i++ ) {
                                        var script = scripts[i];
                                        var parent = script.parentNode;

                                        if ( parent ) {
                                            parent.removeChild( script );

                                            var newScript = iDoc.createElement('script');
                                            newScript.innerHTML = script.innerHTML;

                                            iHead.appendChild( newScript );
                                        }
                                    }

                                    var closed = false;
                                    var closeIFrame = function() {
                                        if ( ! closed ) {
                                            closed = true;

                                            iframe.style.opacity = 0;

                                            setTimeout( function() {
                                                iframe.parentNode.removeChild( iframe );
                                            }, 220 );
                                        }
                                    }

                                    /*
                                     * Retry Handler.
                                     * 
                                     * Clear this, make a new (real) XMLHttpRequest,
                                     * and then re-run everything.
                                     */
                                    iDoc.getElementById('ajax-retry').onclick = function() {
                                        var methodCalls = self.__.methodCalls;

                                        initializeXMLHttpRequest.call( self );
                                        for ( var i = 0; i < methodCalls.length; i++ ) {
                                            var method = methodCalls[i];
                                            self[method.method].apply( self, method.args );
                                        }

                                        closeIFrame();

                                        return false;
                                    };

                                    /*
                                     * The close handler.
                                     * 
                                     * When closed, the response is cleared,
                                     * and then the request finishes with null info.
                                     */
                                    iDoc.getElementById('ajax-close').onclick = function() {
                                        copyRequestProperties( self.__.inner, self, true );

                                        // clear the response
                                        self.response       = '';
                                        self.responseText   = '';
                                        self.responseXML    = null;

                                        if ( self.onreadystatechange ) {
                                            self.onreadystatechange( ev );
                                        }

                                        closeIFrame();
                                        return false;
                                    };

                                    var html = iDoc.getElementsByTagName('html')[0];
                                    html.setAttribute( 'class', 'ajax' );

                                    setTimeout( function() {
                                        iframe.style.opacity = 1;
                                    }, 1 );
                                }

                                /*
                                 * Placed inside a timeout, incase the document doesn't exist yet.
                                 * 
                                 * Can happen if the page ajax's straight away.
                                 */
                                setTimeout( function() {
                                    var body = document.getElementsByTagName('body')[0];
                                    body.appendChild( iframe );
                                }, 1 );
                            }

                            var old = window.XMLHttpRequest;

                            /**
                             * The middle man http request object.
                             * 
                             * Acts just like a normal one, but will show errors if they
                             * occur instead of running the result.
                             */
                            var XMLHttpRequest = function() {
                                initializeXMLHttpRequest.call( this );
                            }

                            var initializeXMLHttpRequest = function() {
                                var self = this,
                                    inner = new old();

                                /**
                                 * With a buggy XMLHttpRequest, it's possible to accidentally run the error handler
                                 * multiple times.
                                 * 
                                 * This is a flag to only do it once, to keep the code more defensive.
                                 */
                                var errorOnce   = true,
                                    isAjaxError = false;

                                var stateResults = [];

                                inner.onreadystatechange = function( ev ) {
                                    copyRequestProperties( inner, self, true, true );

                                    var state = inner.readyState;

                                    /*
                                     * Check headers for error.
                                     */
                                    if ( ! isAjaxError && state >= 2 ) {
                                        var header = inner.getResponseHeader( '<?php echo ErrorHandler::PHP_ERROR_MAGIC_HEADER_KEY ?>' );

                                        if ( header !== null ) {
                                            self.__.isAjaxError = true;
                                            isAjaxError = true;
                                        }
                                    }

                                    if ( ! isAjaxError && state >= 2 ) {
                                        copyResponseProperties( inner, self );
                                    }

                                    /*
                                     * Success ! \o/
                                     * 
                                     * Pass any state change on to the parent caller,
                                     * unless we hit an ajaxy error.
                                     */
                                    if ( !isAjaxError && self.onreadystatechange ) {
                                        /*
                                         * One of three things happens:
                                         *  = cache the requests until we know there is no error (state 4)
                                         *  = we know there is no error, and so we run our cache
                                         *  = cache is done, but we've been called again, so just pass it on
                                         */
                                        if ( state < 4 ) {
                                            stateResults.push( copyRequestProperties(self, {}, true) );
                                        } else {
                                            if ( stateResults !== null ) {
                                                var currentState = copyRequestProperties( self, {}, true );

                                                for ( var i = 0; i < stateResults.length; i++ ) {
                                                    var store = stateResults[i];
                                                    copyRequestProperties( store, self, true );

                                                    // must check a second time here,
                                                    // in case it gets changed within an onreadystatechange
                                                    if ( self.onreadystatechange ) {
                                                        self.onreadystatechange( ev );
                                                    }
                                                }

                                                copyRequestProperties( currentState, self, true );
                                                stateResults = null;
                                            }

                                            if ( self.onreadystatechange ) {
                                                self.onreadystatechange( ev );
                                            }
                                        }
                                    }

                                    /*
                                     * Fail : (
                                     */
                                    if (
                                            isAjaxError &&
                                            state === 4 &&
                                            errorOnce
                                    ) {
                                        errorOnce = false;
                                        runFail.call( self, ev );
                                    }
                                };

                                copyRequestProperties( inner, this, true );

                                /*
                                 * Private fields are stored underneath an unhappy face,
                                 * to localize them.
                                 * 
                                 * Access becomes:
                                 *  this.__.fieldName
                                 */
                                this.__ = {
                                        methodCalls: [],
                                        inner: inner,
                                        isAjaxError: false,
                                        isSynchronous: false
                                };
                            }

                            /*
                             * We build the methods for the fake XMLHttpRequest.
                             */

                            var copyIn = function() {
                                copyRequestProperties( this, this.__.inner );
                            }
                            var copyOut = function() {
                                copyRequestProperties( this.__.inner, this, true, this.__.isSynchronous && this.__.isAjaxError );
                            }
                            var addHeader = function() {
                                this.__.inner.setRequestHeader( 'HTTP_X_REQUESTED_WITH', 'XMLHttpRequest' );
                            }
                            var isSynchronous = function( args ) {
                                this.__.isSynchronous = ( args[2] === false );
                            }
                            var saveRequest = function( args, method ) {
                                this.__.methodCalls.push({
                                    method: method,
                                    args: args
                                });
                            }

                            wrapMethod( XMLHttpRequest, old, 'open'        , saveRequest, copyIn, isSynchronous );
                            wrapMethod( XMLHttpRequest, old, 'abort'       , saveRequest, copyIn );
                            wrapMethod( XMLHttpRequest, old, 'send'        , saveRequest, copyIn, addHeader );
                            wrapMethod( XMLHttpRequest, old, 'sendAsBinary', saveRequest, copyIn, addHeader );

                            postMethod( XMLHttpRequest,      'send'        , copyOut );
                            postMethod( XMLHttpRequest,      'sendAsBinary', copyOut );

                            wrapMethod( XMLHttpRequest, old, 'getAllResponseHeaders', saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'getResponseHeader'    , saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'setRequestHeader'     , saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'overrideMimeType'     , saveRequest );

                            window.XMLHttpRequest = XMLHttpRequest;
                        }
                    })( window );
                </script><?php
            }

            /**
             * The actual display logic.
             * This outputs the error details in HTML.
             */
            private function displayError( $message, $errLine, $errFile, $errFileType, $stackTrace, &$fileLinesSets, $numFileLines, $dumpInfo ) {
                $applicationRoot = $this->applicationRoot;
                $serverName      = $this->serverName;
                $backgroundText  = $this->backgroundText;
                $requestUrl      = str_replace( $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );
                $displayLineNumber = $this->displayLineNumber;

                $this->displayHTML(
                        // pre, in the head
                        function() use( $message, $errFile, $errLine ) {
                                echo "<!--\n" .
                                        "$message\n" .
                                        "$errFile, $errLine\n" .
                                    "-->";
                        },

                        // the content
                        function() use (
                                $requestUrl,
                                $backgroundText, $serverName, $applicationRoot,
                                $message, $errLine, $errFile, $errFileType, $stackTrace,
                                &$fileLinesSets, $numFileLines,
                                $displayLineNumber,
                                $dumpInfo
                        ) {
                            if ( $backgroundText ) { ?>
                                <div id="error-wrap">
                                    <div id="error-back"><?php echo $backgroundText ?></div>
                                </div>
                            <?php } ?>
                            <h2 id="error-file-root"><?php echo $serverName ?> | <?php echo $applicationRoot ?></h2>
                            <h2 id="ajax-info">
                                <span id="ajax-tab" class="ajax-button">AJAX PAUSED</span>

                                <span class="ajax-url"><?php echo $serverName ?><?php echo $requestUrl ?></span>
                                <span class="ajax-buttons">
                                    <a href="#" id="ajax-close" class="ajax-button">X</a>
                                    <a href="#" id="ajax-retry" class="ajax-button">RETRY</a>
                                </span>
                            </h2>
                            <h1 id="error-title"><?php echo $message ?></h1>
                            <h2 id="error-file" class="<?php echo $fileLinesSets ? 'has_code' : '' ?>"><span id="error-linenumber"><?php echo $errLine ?></span> <span id="error-filename" class="<?php echo $errFileType ?>"><?php echo $errFile ?></span></h2>
                            <?php if ( $fileLinesSets ) { ?>
                                <div id="error-files">
                                    <?php
                                        foreach ( $fileLinesSets as $fileLinesSet ) {
                                            $id            = $fileLinesSet->getHTMLID();
                                            $fileLines     = $fileLinesSet->getLines();
                                            $show          = $fileLinesSet->isShown();
                                            $highlightLine = $fileLinesSet->getLine();
                                            
                                            // calculate last line number length
                                            end($fileLines);
                                            $maxLineNumber = key($fileLines);
                                            $lineDecimals  = strlen($maxLineNumber);
                                        ?>
                                            <div id="<?php echo $id ?>" class="error-file-lines <?php echo $show ? 'show' : '' ?>">
                                                <?php
                                                    foreach ( $fileLines as $lineNum => $origLine ) {
                                                        $line = ltrim($origLine, ' ');
                                                        $numSpaces = strlen($origLine) - strlen($line);

                                                        $size = 8*$numSpaces + 64;
                                                        $style = "style='padding-left: " . $size . "px; text-indent: -" . $size . "px;'";

                                                        for ( $i = 0; $i < $numSpaces; $i++ ) {
                                                            $line = "&nbsp;$line";
                                                        }

                                                        if ($displayLineNumber) {
                                                            $lineNumLabel = str_replace(' ', '&nbsp;', sprintf("%{$lineDecimals}d", $lineNum));
                                                        } else {
                                                            $lineNumLabel = '';
                                                        }

                                                        ?><div <?php echo $style ?> class="error-file-line <?php echo ($lineNum === $highlightLine) ? 'highlight' : '' ?>">
                                                            <span class="error-file-line-number"><?php echo $lineNumLabel ?></span>
                                                            <span class="error-file-line-content"><?php echo $line ?></span>
                                                        </div>
                                                        <?php
                                                    }
                                                ?>
                                            </div>
                                    <?php } ?>
                                </div>
                            <?php }
                            
                            if ( $stackTrace !== null ) {
                                echo $stackTrace;
                            }

                            if ( $dumpInfo !== null ) {
                                echo $dumpInfo;
                            }
                        },

                        /**
                         * Adds:
                         *  = mouse movement for switching the code snippet in real time
                         */
                        function() {
                            ?><script>
                                "use strict";

                                $(document).ready( function() {
                                    $('#ajax-close', '#ajax-retry').click( function(ev) {
                                        ev.preventDefault();
                                    });

                                    if ( $('#error-files').size() > 0 && $('#error-stack-trace').size() > 0 ) {
                                        var FADE_SPEED = 150,
                                            lines = $('#error-files .error-file-lines'),
                                            currentID = '#' + lines.filter( '.show' ).attr( 'id' );

                                        var filename   = $('#error-filename'),
                                            linenumber = $('#error-linenumber');

                                        $( '.error-stack-trace-line' ).
                                                mouseover( function() {
                                                    var $this = $(this);

                                                    if ( ! $this.hasClass('select-highlight') ) {
                                                        $this.addClass( 'select-highlight' );
                                                    }
                                                }).
                                                mouseout( function(ev) {
                                                    var $this = $(this);

                                                    $this.removeClass( 'select-highlight' );
                                                }).
                                                click( function() {
                                                    var $this = $(this);

                                                    if ( ! $this.hasClass('highlight') && !$this.hasClass('is-native') ) {
                                                        $( '.error-stack-trace-line.highlight' ).removeClass( 'highlight' );

                                                        $this.addClass( 'highlight' );

                                                        var lineID = $this.data( 'file-lines-id' );
                                                        if ( lineID ) {
                                                            var newCurrent = '#' + lineID;

                                                            if ( newCurrent !== currentID ) {
                                                                currentID = newCurrent;

                                                                lines.removeClass( 'show' );
                                                                lines.filter( currentID ).addClass( 'show' );

                                                                var $file = $this.find('.filename');
                                                                var file = $file.text(),
                                                                    line = $this.find('.linenumber').text();

                                                                filename.text( file );
                                                                filename.attr( 'class', $file.attr('class') );
                                                                linenumber.text( line );
                                                            }
                                                        }
                                                    }
                                                });
                                        $('#error-stack-trace').mouseleave( function() {
                                            lines.filter('.show').removeClass( 'show' );
                                            lines.filter( currentID ).addClass( 'show' );
                                        });
                                    }
                                } );
                            </script><?php
                        }
                );
            }

            /**
             * A generic function for clearing the buffer, and displaying error output.
             * 
             * A function needs to be given, and then this is run at the correct time.
             * There are two ways this can be used:
             * 
             *  displayHTML( $head, $body )
             * 
             * Here 'head' is run straight after the doctype, whilst 'body' is run as
             * the body for the content. The other way is:
             * 
             *  displayHTML( $body )
             * 
             * Here there is only content.
             */
            function displayHTML( Closure $head, $body=null, $javascript=null ) {
                if ( func_num_args() === 2 ) {
                    $body = $head;
                    $head = null;
                }
           
                // clean out anything displayed already
                try {
                    ob_clean();
                } catch ( Exception $ex ) { /* do nothing */ }

                if (!$this->htmlOnly && ErrorHandler::isNonPHPRequest()) {
                    header( "Content-Type: text/html" );
                }
                header( ErrorHandler::PHP_ERROR_MAGIC_HEADER_KEY . ': ' . ErrorHandler::PHP_ERROR_MAGIC_HEADER_VALUE );

                echo '<!DOCTYPE html>';

                if ( $head !== null ) {
                    $head();
                }

                ?><style>
                    html, body {
                        margin: 0;
                        padding: 0; 
                        width: 100%;
                        height: 100%;
                    }
                        body {
                            color: #f0f0f0;
                            background-color: #111;
                            tab-size: 4;
                        }

                    ::-moz-selection{background: #662039 !important; color: #fff !important; text-shadow: none;}
                    ::selection {background: #662039 !important; color: #fff !important; text-shadow: none;} 

                    a,
                    .error-stack-trace-line {
                        -webkit-transition: color 120ms linear, background 120ms linear;
                           -moz-transition: color 120ms linear, background 120ms linear;
                            -ms-transition: color 120ms linear, background 120ms linear;
                             -o-transition: color 120ms linear, background 120ms linear;
                                transition: color 120ms linear, background 120ms linear;
                    }

                    a,
                    a:visited,
                    a:hover,
                    a:active {
                        color: #9ae;
                        text-decoration: none;
                    }
                    a:hover {
                        color: #aff;
                    }

                    h1,
                    h2,
                    .background {
                        font: 17px monaco, consolas, monospace;
                    }

                    h1 {
                        font-size: 32px;
                        margin-bottom: 0;
                    }
                    h2 {
                        font-size: 24px;
                        margin-top: 0;
                    }
                            .background {
                                width: 100%;

                                padding: 18px 24px;
                                -moz-box-sizing: border-box;
                                box-sizing: border-box;

                                position: relative;

                                height: 100%;
                                overflow: auto;
                            }
                    html.ajax {
                        background: transparent;
                    }
                        html.ajax > body {
                            background: rgba( 0, 0, 0, 0.3 );
                            -moz-box-sizing: border-box;
                            box-sizing: border-box;

                            padding: 30px 48px;
                        }
                            html.ajax > body > .background {
                                border-radius: 4px;
                                box-shadow: 5px 8px 18px rgba( 0, 0, 0, 0.4 );

                                height: auto;
                                min-height: 0;

                                overflow: visible;
                            }

                    #ajax-info {
                        display: none;
                        position: relative;
                        line-height: 100%;

                        white-space: nowrap;
                    }
                        html.ajax #ajax-info {
                            display: block;
                        }
                        html.ajax #error-file-root {
                            display: none;
                        }
                    .ajax-button {
                        padding: 3px 12px;
                        margin-top: -3px;
                        border-radius: 3px;
                        color: #bbb;
                    }
                    .ajax-button,
                    .ajax-button:visited,
                    .ajax-button:active,
                    .ajax-button:hover {
                        text-decoration: none;
                    }
                    a.ajax-button:hover {
                        color: #fff;
                    }
                    #ajax-tab {
                        float: left;
                        margin-right: 12px;

                        background: #000;
                        color: inherit;
                        border: 3px solid #333;
                        margin-top: -6px;
                    }
                    .ajax-buttons {
                        position: absolute;
                        right: 0;
                        top: 0;
                    }
                        #ajax-retry {
                            float: right;
                            background: #0E4973;
                            margin-right: 12px;
                        }
                            #ajax-retry:hover {
                                background: #0C70B7;
                            }
                        #ajax-close {
                            float: right;
                            background: #622;
                        }
                            #ajax-close:hover {
                                background: #aa4040;
                            }

                    #error-title {
                        white-space: pre-wrap;
                    }

                    <?php
                     /*
                     * Error Background Text.
                     */
                    ?>
                    #error-wrap {
                        right: 0;
                        top: 0;
                        position: absolute;
                        overflow: hidden;

                        z-index: -1;
                        width: 100%;
                        height: 100%;
                    }
                    #error-back {
                        font-size: 240px;
                        color: #211600;
                        position: absolute;
                        top: 60px;
                        right: -80px;

                        -webkit-transform: rotate( 24deg );
                           -moz-transform: rotate( 24deg );
                            -ms-transform: rotate( 24deg );
                             -o-transform: rotate( 24deg );
                                transform: rotate( 24deg );
                    }
                    <?php
                    /*
                     * Code Snippets at the top
                     */
                    ?>
                    #error-file.has_code {
                        margin: 24px 0 0 167px;
                    }
                        #error-linenumber {
                            position: absolute;
                            text-align: right;
                            left: 0;
                            width: 178px;
                        }
                    #ajax-info,
                    #error-file-root {
                        color: #666;
                    }
                    #error-files {
                        line-height: 0;
                        font-size: 0;

                        position: relative;
                        padding: 3px 0 24px 0;

                        display: inline-block;

                        width: 100%;
                        -moz-box-sizing: border-box;
                        box-sizing: border-box;
                        padding-left: 166px;

                        overflow: hidden;
                    }
                        /**
                         * Two transitions are used to get them to smoothly fade,
                         * in both directions.
                         *
                         * The second keeps it on screen for long enough for the
                         * fade to occur, and then does the margin transtion to move
                         * it out.
                         */
                        .error-file-lines {
                            display: inline-block;
                            opacity: 0;

                            float: left;
                            clear: none;

                            width: 100%;
                            margin-right: -100%;

                            -webkit-transition: opacity 300ms;
                               -moz-transition: opacity 300ms;
                                -ms-transition: opacity 300ms;
                                 -o-transition: opacity 300ms;
                                    transition: opacity 300ms;
                        }
                        .error-file-lines.show {
                            height: auto;

                            opacity: 1;

                            margin: 0;

                            -webkit-transition: opacity 300ms, margin 100ms linear 300ms;
                               -moz-transition: opacity 300ms, margin 100ms linear 300ms;
                                -ms-transition: opacity 300ms, margin 100ms linear 300ms;
                                 -o-transition: opacity 300ms, margin 100ms linear 300ms;
                                    transition: opacity 300ms, margin 100ms linear 300ms;
                        }
                            .error-file-line {
                                line-height: 21px;

                                font-size: 16px;
                                color: #ddd;
                                list-style-type: none;
                                /* needed for empty lines */
                                min-height: 20px;
                                padding-right: 18px;
                                padding-bottom: 1px;

                                border-radius: 2px;

                                -moz-box-sizing: border-box;
                                box-sizing: border-box;

                                display: inline-block;
                                float: left;
                                clear: both;

                                position: relative;
                            }
                                .error-file-line-number {
                                    position: absolute;
                                    top: 0;
                                    right: 100%;
                                    margin-right: 12px;
                                    display: block;
                                    text-indent: 0;
                                    text-align: left;
                                }
                    <?php
                    /*
                     * Stack Trace
                     */
                    ?>
                    #error-stack-trace,
                    .error-stack-trace-line {
                        border-spacing: 0;
                        width: 100%;
                    }
                    #error-stack-trace {
                        line-height: 28px;
                        cursor: pointer;
                    }
                        .error-stack-trace-exception {
                            color: #b33;
                        }
                            .error-stack-trace-exception > td {
                                padding-top: 18px;
                            }
                        .error-stack-trace-line {
                            float: left;
                        }
                        .error-stack-trace-line.is-exception {
                            margin-top: 18px;
                            border-top: 1px solid #422;
                        }
                            .error-stack-trace-line:first-of-type > td:first-of-type {
                                border-top-left-radius: 2px;
                            }
                            .error-stack-trace-line:first-of-type > td:last-of-type {
                                border-top-right-radius: 2px;
                            }
                            .error-stack-trace-line:last-of-type > td:first-of-type {
                                border-bottom-left-radius: 2px;
                            }
                            .error-stack-trace-line:last-of-type > td:last-of-type {
                                border-bottom-right-radius: 2px;
                            }

                            .error-stack-trace-line > td {
                                padding: 3px 0;
                                vertical-align: top;
                            }
                            .error-stack-trace-line > .linenumber,
                            .error-stack-trace-line > .filename,
                            .error-stack-trace-line > .file-internal-php,
                            .error-stack-trace-line > .lineinfo {
                                padding-left:  18px;
                                padding-right: 12px;
                            }
                            .error-stack-trace-line > .linenumber,
                            .error-stack-trace-line > .file-internal-php,
                            .error-stack-trace-line > .filename {
                                white-space: pre;
                            }
                            .error-stack-trace-line > .linenumber {
                                text-align: right;
                            }
                            .error-stack-trace-line > .file-internal-php,
                            .error-stack-trace-line > .filename {
                            }
                            .error-stack-trace-line > .lineinfo {
                                padding-right:18px;
                                padding-left: 82px;
                                text-indent: -64px;
                            }
                    <?php
                    /*
                     * Error Dump Info (post, get, session)
                     */
                    ?>
                    .error_dumps {
                        margin-top: 48px;
                        padding-top: 32px;
                        width: 100%;
                        max-width: 100%;
                        overflow: hidden;
                    }
                        .error_dump {
                            float: left;
                            clear: none;

                            -moz-box-sizing: border-box;
                            box-sizing: border-box;

                            padding: 0 32px 24px 12px;
                            max-width: 100%;
                        }
                        .error_dump.dump_request {
                            clear: left;
                            max-width: 50%;
                            min-width: 600px;
                        }
                        .error_dump.dump_response {
                            max-width: 50%;
                            min-width: 600px;
                        }
                        .error_dump.dump_server {
                            width: 100%;
                            clear: both;
                        }
                        .error_dump_header {
                            font-size: 24px;
                            color: #eb4; 
                            margin: 0;
                            margin-left: -6px;
                        }
                        .error_dump_key,
                        .error_dump_mapping,
                        .error_dump_value {
                            white-space: pre;
                            padding: 3px 6px 3px 6px;
                            float: left;
                        }
                        .error_dump_key {
                            clear: left;
                        }
                        .error_dump_mapping {
                            padding: 3px 12px;
                        }
                        .error_dump_value {
                            clear: right;
                            white-space: normal;
                            max-width: 100%;
                        }
                        
                    <?php
                    /*
                     * Code and Stack highlighting colours
                     * 
                     * The way this works, is that syntax highlighting is turned off
                     * for .pre-highlight. It then gets turns on for .pre-highlight,
                     * if it matches certain criteria.
                     * 
                     * The emphasis is that pre-highlight is by default 'no highlight'.
                     */
                    ?>
                    .pre-highlight,
                    .highlight {
                    }
                    .is-native,
                    .pre-highlight {
                        opacity: 0.3;
                        color: #999;
                    }
                    .is-native {
                        opacity: 0.3 !important;
                    }
                    .highlight,
                    .pre-highlight.highlight,
                    .highlight ~ .pre-highlight {
                        color: #eee;
                        opacity: 1;
                    }

                    .select-highlight {
                        background: #261313;
                    }
                    .select-highlight.is-native {
                        background: #222;
                    }
                    .highlight {
                        background: #391414;
                    }
                    .highlight.select-highlight {
                        background: #451915;
                    }

                    .pre-highlight span,
                    .pre-highlight:not(.highlight):first-of-type span {
                        color : #999;
                        border: none !important;
                    }

                    <?php
                    /*
                     * Syntax Highlighting
                     */
                    ?>
                    .pre-highlight:first-of-type .syntax-class,
                    .highlight ~ .pre-highlight  .syntax-class,
                    .pre-highlight.highlight     .syntax-class,
                                                 .syntax-class {
                        color: #C07041;
                    }
                    .pre-highlight:first-of-type .syntax-function,
                    .highlight ~ .pre-highlight  .syntax-function,
                    .pre-highlight.highlight     .syntax-function,
                                                 .syntax-function {
                        color: #F9EE98;
                    }
                    .pre-highlight:first-of-type .syntax-literal,
                    .highlight ~ .pre-highlight  .syntax-literal,
                    .pre-highlight.highlight     .syntax-literal,
                                                 .syntax-literal {
                        color: #cF5d33;
                    }
                    .pre-highlight:first-of-type .syntax-string,
                    .highlight ~ .pre-highlight  .syntax-string,
                    .pre-highlight.highlight     .syntax-string,
                                                 .syntax-string {
                        color: #7C9D5D;
                    }
                    .pre-highlight:first-of-type .syntax-variable-not-important,
                    .highlight ~ .pre-highlight  .syntax-variable-not-important,
                    .pre-highlight.highlight     .syntax-variable-not-important,
                                                 .syntax-variable-not-important {
                        opacity: 0.5;
                    }
                    .pre-highlight:first-of-type .syntax-higlight-variable,
                    .highlight ~ .pre-highlight  .syntax-higlight-variable,
                    .pre-highlight.highlight     .syntax-higlight-variable,
                                                 .syntax-higlight-variable {
                        color: #f00;
                        border-bottom: 3px dashed #c33;
                    }
                    .pre-highlight:first-of-type .syntax-variable,
                    .highlight ~ .pre-highlight  .syntax-variable,
                    .pre-highlight.highlight     .syntax-variable,
                    .syntax-variable {
                        color: #798aA0;
                    }
                    .pre-highlight:first-of-type .syntax-keyword,
                    .highlight ~ .pre-highlight  .syntax-keyword,
                    .pre-highlight.highlight     .syntax-keyword,
                    .syntax-keyword {
                        color: #C07041;
                    }
                    .pre-highlight:first-of-type .syntax-comment,
                    .highlight ~ .pre-highlight  .syntax-comment,
                    .pre-highlight.highlight     .syntax-comment,
                    .syntax-comment {
                        color: #5a5a5a;
                    }

                    <?php
                    /*
                     * File Highlighting
                     */
                    ?>
                    .file-internal-php {
                        color: #555 !important;
                    }
                    .pre-highlight:first-of-type .file-common,
                    .highlight ~ .pre-highlight  .file-common,
                    .pre-highlight.highlight     .file-common,
                                                 .file-common {
                        color: #eb4;
                    }
                    .pre-highlight:first-of-type .file-ignore,
                    .highlight ~ .pre-highlight  .file-ignore,
                    .pre-highlight.highlight     .file-ignore,
                                                 .file-ignore {
                        color: #585;
                    }
                    .pre-highlight:first-of-type .file-app,
                    .highlight ~ .pre-highlight  .file-app,
                    .pre-highlight.highlight     .file-app,
                                                 .file-app {
                        color: #66c6d5;
                    }
                    .pre-highlight:first-of-type .file-root,
                    .highlight ~ .pre-highlight  .file-root,
                    .pre-highlight.highlight     .file-root,
                                                 .file-root {
                        color: #b69;
                    }
                </style><?php

                ?><div class="background"><?php
                    $body();
                ?></div><?php

                ?><script>
                    /*! jQuery v1.7.2 jquery.com | jquery.org/license */
                    (function(a,b){function cy(a){return f.isWindow(a)?a:a.nodeType===9?a.defaultView||a.parentWindow:!1}function cu(a){if(!cj[a]){var b=c.body,d=f("<"+a+">").appendTo(b),e=d.css("display");d.remove();if(e==="none"||e===""){ck||(ck=c.createElement("iframe"),ck.frameBorder=ck.width=ck.height=0),b.appendChild(ck);if(!cl||!ck.createElement)cl=(ck.contentWindow||ck.contentDocument).document,cl.write((f.support.boxModel?"<!doctype html>":"")+"<html><body>"),cl.close();d=cl.createElement(a),cl.body.appendChild(d),e=f.css(d,"display"),b.removeChild(ck)}cj[a]=e}return cj[a]}function ct(a,b){var c={};f.each(cp.concat.apply([],cp.slice(0,b)),function(){c[this]=a});return c}function cs(){cq=b}function cr(){setTimeout(cs,0);return cq=f.now()}function ci(){try{return new a.ActiveXObject("Microsoft.XMLHTTP")}catch(b){}}function ch(){try{return new a.XMLHttpRequest}catch(b){}}function cb(a,c){a.dataFilter&&(c=a.dataFilter(c,a.dataType));var d=a.dataTypes,e={},g,h,i=d.length,j,k=d[0],l,m,n,o,p;for(g=1;g<i;g++){if(g===1)for(h in a.converters)typeof h=="string"&&(e[h.toLowerCase()]=a.converters[h]);l=k,k=d[g];if(k==="*")k=l;else if(l!=="*"&&l!==k){m=l+" "+k,n=e[m]||e["* "+k];if(!n){p=b;for(o in e){j=o.split(" ");if(j[0]===l||j[0]==="*"){p=e[j[1]+" "+k];if(p){o=e[o],o===!0?n=p:p===!0&&(n=o);break}}}}!n&&!p&&f.error("No conversion from "+m.replace(" "," to ")),n!==!0&&(c=n?n(c):p(o(c)))}}return c}function ca(a,c,d){var e=a.contents,f=a.dataTypes,g=a.responseFields,h,i,j,k;for(i in g)i in d&&(c[g[i]]=d[i]);while(f[0]==="*")f.shift(),h===b&&(h=a.mimeType||c.getResponseHeader("content-type"));if(h)for(i in e)if(e[i]&&e[i].test(h)){f.unshift(i);break}if(f[0]in d)j=f[0];else{for(i in d){if(!f[0]||a.converters[i+" "+f[0]]){j=i;break}k||(k=i)}j=j||k}if(j){j!==f[0]&&f.unshift(j);return d[j]}}function b_(a,b,c,d){if(f.isArray(b))f.each(b,function(b,e){c||bD.test(a)?d(a,e):b_(a+"["+(typeof e=="object"?b:"")+"]",e,c,d)});else if(!c&&f.type(b)==="object")for(var e in b)b_(a+"["+e+"]",b[e],c,d);else d(a,b)}function b$(a,c){var d,e,g=f.ajaxSettings.flatOptions||{};for(d in c)c[d]!==b&&((g[d]?a:e||(e={}))[d]=c[d]);e&&f.extend(!0,a,e)}function bZ(a,c,d,e,f,g){f=f||c.dataTypes[0],g=g||{},g[f]=!0;var h=a[f],i=0,j=h?h.length:0,k=a===bS,l;for(;i<j&&(k||!l);i++)l=h[i](c,d,e),typeof l=="string"&&(!k||g[l]?l=b:(c.dataTypes.unshift(l),l=bZ(a,c,d,e,l,g)));(k||!l)&&!g["*"]&&(l=bZ(a,c,d,e,"*",g));return l}function bY(a){return function(b,c){typeof b!="string"&&(c=b,b="*");if(f.isFunction(c)){var d=b.toLowerCase().split(bO),e=0,g=d.length,h,i,j;for(;e<g;e++)h=d[e],j=/^\+/.test(h),j&&(h=h.substr(1)||"*"),i=a[h]=a[h]||[],i[j?"unshift":"push"](c)}}}function bB(a,b,c){var d=b==="width"?a.offsetWidth:a.offsetHeight,e=b==="width"?1:0,g=4;if(d>0){if(c!=="border")for(;e<g;e+=2)c||(d-=parseFloat(f.css(a,"padding"+bx[e]))||0),c==="margin"?d+=parseFloat(f.css(a,c+bx[e]))||0:d-=parseFloat(f.css(a,"border"+bx[e]+"Width"))||0;return d+"px"}d=by(a,b);if(d<0||d==null)d=a.style[b];if(bt.test(d))return d;d=parseFloat(d)||0;if(c)for(;e<g;e+=2)d+=parseFloat(f.css(a,"padding"+bx[e]))||0,c!=="padding"&&(d+=parseFloat(f.css(a,"border"+bx[e]+"Width"))||0),c==="margin"&&(d+=parseFloat(f.css(a,c+bx[e]))||0);return d+"px"}function bo(a){var b=c.createElement("div");bh.appendChild(b),b.innerHTML=a.outerHTML;return b.firstChild}function bn(a){var b=(a.nodeName||"").toLowerCase();b==="input"?bm(a):b!=="script"&&typeof a.getElementsByTagName!="undefined"&&f.grep(a.getElementsByTagName("input"),bm)}function bm(a){if(a.type==="checkbox"||a.type==="radio")a.defaultChecked=a.checked}function bl(a){return typeof a.getElementsByTagName!="undefined"?a.getElementsByTagName("*"):typeof a.querySelectorAll!="undefined"?a.querySelectorAll("*"):[]}function bk(a,b){var c;b.nodeType===1&&(b.clearAttributes&&b.clearAttributes(),b.mergeAttributes&&b.mergeAttributes(a),c=b.nodeName.toLowerCase(),c==="object"?b.outerHTML=a.outerHTML:c!=="input"||a.type!=="checkbox"&&a.type!=="radio"?c==="option"?b.selected=a.defaultSelected:c==="input"||c==="textarea"?b.defaultValue=a.defaultValue:c==="script"&&b.text!==a.text&&(b.text=a.text):(a.checked&&(b.defaultChecked=b.checked=a.checked),b.value!==a.value&&(b.value=a.value)),b.removeAttribute(f.expando),b.removeAttribute("_submit_attached"),b.removeAttribute("_change_attached"))}function bj(a,b){if(b.nodeType===1&&!!f.hasData(a)){var c,d,e,g=f._data(a),h=f._data(b,g),i=g.events;if(i){delete h.handle,h.events={};for(c in i)for(d=0,e=i[c].length;d<e;d++)f.event.add(b,c,i[c][d])}h.data&&(h.data=f.extend({},h.data))}}function bi(a,b){return f.nodeName(a,"table")?a.getElementsByTagName("tbody")[0]||a.appendChild(a.ownerDocument.createElement("tbody")):a}function U(a){var b=V.split("|"),c=a.createDocumentFragment();if(c.createElement)while(b.length)c.createElement(b.pop());return c}function T(a,b,c){b=b||0;if(f.isFunction(b))return f.grep(a,function(a,d){var e=!!b.call(a,d,a);return e===c});if(b.nodeType)return f.grep(a,function(a,d){return a===b===c});if(typeof b=="string"){var d=f.grep(a,function(a){return a.nodeType===1});if(O.test(b))return f.filter(b,d,!c);b=f.filter(b,d)}return f.grep(a,function(a,d){return f.inArray(a,b)>=0===c})}function S(a){return!a||!a.parentNode||a.parentNode.nodeType===11}function K(){return!0}function J(){return!1}function n(a,b,c){var d=b+"defer",e=b+"queue",g=b+"mark",h=f._data(a,d);h&&(c==="queue"||!f._data(a,e))&&(c==="mark"||!f._data(a,g))&&setTimeout(function(){!f._data(a,e)&&!f._data(a,g)&&(f.removeData(a,d,!0),h.fire())},0)}function m(a){for(var b in a){if(b==="data"&&f.isEmptyObject(a[b]))continue;if(b!=="toJSON")return!1}return!0}function l(a,c,d){if(d===b&&a.nodeType===1){var e="data-"+c.replace(k,"-$1").toLowerCase();d=a.getAttribute(e);if(typeof d=="string"){try{d=d==="true"?!0:d==="false"?!1:d==="null"?null:f.isNumeric(d)?+d:j.test(d)?f.parseJSON(d):d}catch(g){}f.data(a,c,d)}else d=b}return d}function h(a){var b=g[a]={},c,d;a=a.split(/\s+/);for(c=0,d=a.length;c<d;c++)b[a[c]]=!0;return b}var c=a.document,d=a.navigator,e=a.location,f=function(){function J(){if(!e.isReady){try{c.documentElement.doScroll("left")}catch(a){setTimeout(J,1);return}e.ready()}}var e=function(a,b){return new e.fn.init(a,b,h)},f=a.jQuery,g=a.$,h,i=/^(?:[^#<]*(<[\w\W]+>)[^>]*$|#([\w\-]*)$)/,j=/\S/,k=/^\s+/,l=/\s+$/,m=/^<(\w+)\s*\/?>(?:<\/\1>)?$/,n=/^[\],:{}\s]*$/,o=/\\(?:["\\\/bfnrt]|u[0-9a-fA-F]{4})/g,p=/"[^"\\\n\r]*"|true|false|null|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?/g,q=/(?:^|:|,)(?:\s*\[)+/g,r=/(webkit)[ \/]([\w.]+)/,s=/(opera)(?:.*version)?[ \/]([\w.]+)/,t=/(msie) ([\w.]+)/,u=/(mozilla)(?:.*? rv:([\w.]+))?/,v=/-([a-z]|[0-9])/ig,w=/^-ms-/,x=function(a,b){return(b+"").toUpperCase()},y=d.userAgent,z,A,B,C=Object.prototype.toString,D=Object.prototype.hasOwnProperty,E=Array.prototype.push,F=Array.prototype.slice,G=String.prototype.trim,H=Array.prototype.indexOf,I={};e.fn=e.prototype={constructor:e,init:function(a,d,f){var g,h,j,k;if(!a)return this;if(a.nodeType){this.context=this[0]=a,this.length=1;return this}if(a==="body"&&!d&&c.body){this.context=c,this[0]=c.body,this.selector=a,this.length=1;return this}if(typeof a=="string"){a.charAt(0)!=="<"||a.charAt(a.length-1)!==">"||a.length<3?g=i.exec(a):g=[null,a,null];if(g&&(g[1]||!d)){if(g[1]){d=d instanceof e?d[0]:d,k=d?d.ownerDocument||d:c,j=m.exec(a),j?e.isPlainObject(d)?(a=[c.createElement(j[1])],e.fn.attr.call(a,d,!0)):a=[k.createElement(j[1])]:(j=e.buildFragment([g[1]],[k]),a=(j.cacheable?e.clone(j.fragment):j.fragment).childNodes);return e.merge(this,a)}h=c.getElementById(g[2]);if(h&&h.parentNode){if(h.id!==g[2])return f.find(a);this.length=1,this[0]=h}this.context=c,this.selector=a;return this}return!d||d.jquery?(d||f).find(a):this.constructor(d).find(a)}if(e.isFunction(a))return f.ready(a);a.selector!==b&&(this.selector=a.selector,this.context=a.context);return e.makeArray(a,this)},selector:"",jquery:"1.7.2",length:0,size:function(){return this.length},toArray:function(){return F.call(this,0)},get:function(a){return a==null?this.toArray():a<0?this[this.length+a]:this[a]},pushStack:function(a,b,c){var d=this.constructor();e.isArray(a)?E.apply(d,a):e.merge(d,a),d.prevObject=this,d.context=this.context,b==="find"?d.selector=this.selector+(this.selector?" ":"")+c:b&&(d.selector=this.selector+"."+b+"("+c+")");return d},each:function(a,b){return e.each(this,a,b)},ready:function(a){e.bindReady(),A.add(a);return this},eq:function(a){a=+a;return a===-1?this.slice(a):this.slice(a,a+1)},first:function(){return this.eq(0)},last:function(){return this.eq(-1)},slice:function(){return this.pushStack(F.apply(this,arguments),"slice",F.call(arguments).join(","))},map:function(a){return this.pushStack(e.map(this,function(b,c){return a.call(b,c,b)}))},end:function(){return this.prevObject||this.constructor(null)},push:E,sort:[].sort,splice:[].splice},e.fn.init.prototype=e.fn,e.extend=e.fn.extend=function(){var a,c,d,f,g,h,i=arguments[0]||{},j=1,k=arguments.length,l=!1;typeof i=="boolean"&&(l=i,i=arguments[1]||{},j=2),typeof i!="object"&&!e.isFunction(i)&&(i={}),k===j&&(i=this,--j);for(;j<k;j++)if((a=arguments[j])!=null)for(c in a){d=i[c],f=a[c];if(i===f)continue;l&&f&&(e.isPlainObject(f)||(g=e.isArray(f)))?(g?(g=!1,h=d&&e.isArray(d)?d:[]):h=d&&e.isPlainObject(d)?d:{},i[c]=e.extend(l,h,f)):f!==b&&(i[c]=f)}return i},e.extend({noConflict:function(b){a.$===e&&(a.$=g),b&&a.jQuery===e&&(a.jQuery=f);return e},isReady:!1,readyWait:1,holdReady:function(a){a?e.readyWait++:e.ready(!0)},ready:function(a){if(a===!0&&!--e.readyWait||a!==!0&&!e.isReady){if(!c.body)return setTimeout(e.ready,1);e.isReady=!0;if(a!==!0&&--e.readyWait>0)return;A.fireWith(c,[e]),e.fn.trigger&&e(c).trigger("ready").off("ready")}},bindReady:function(){if(!A){A=e.Callbacks("once memory");if(c.readyState==="complete")return setTimeout(e.ready,1);if(c.addEventListener)c.addEventListener("DOMContentLoaded",B,!1),a.addEventListener("load",e.ready,!1);else if(c.attachEvent){c.attachEvent("onreadystatechange",B),a.attachEvent("onload",e.ready);var b=!1;try{b=a.frameElement==null}catch(d){}c.documentElement.doScroll&&b&&J()}}},isFunction:function(a){return e.type(a)==="function"},isArray:Array.isArray||function(a){return e.type(a)==="array"},isWindow:function(a){return a!=null&&a==a.window},isNumeric:function(a){return!isNaN(parseFloat(a))&&isFinite(a)},type:function(a){return a==null?String(a):I[C.call(a)]||"object"},isPlainObject:function(a){if(!a||e.type(a)!=="object"||a.nodeType||e.isWindow(a))return!1;try{if(a.constructor&&!D.call(a,"constructor")&&!D.call(a.constructor.prototype,"isPrototypeOf"))return!1}catch(c){return!1}var d;for(d in a);return d===b||D.call(a,d)},isEmptyObject:function(a){for(var b in a)return!1;return!0},error:function(a){throw new Error(a)},parseJSON:function(b){if(typeof b!="string"||!b)return null;b=e.trim(b);if(a.JSON&&a.JSON.parse)return a.JSON.parse(b);if(n.test(b.replace(o,"@").replace(p,"]").replace(q,"")))return(new Function("return "+b))();e.error("Invalid JSON: "+b)},parseXML:function(c){if(typeof c!="string"||!c)return null;var d,f;try{a.DOMParser?(f=new DOMParser,d=f.parseFromString(c,"text/xml")):(d=new ActiveXObject("Microsoft.XMLDOM"),d.async="false",d.loadXML(c))}catch(g){d=b}(!d||!d.documentElement||d.getElementsByTagName("parsererror").length)&&e.error("Invalid XML: "+c);return d},noop:function(){},globalEval:function(b){b&&j.test(b)&&(a.execScript||function(b){a.eval.call(a,b)})(b)},camelCase:function(a){return a.replace(w,"ms-").replace(v,x)},nodeName:function(a,b){return a.nodeName&&a.nodeName.toUpperCase()===b.toUpperCase()},each:function(a,c,d){var f,g=0,h=a.length,i=h===b||e.isFunction(a);if(d){if(i){for(f in a)if(c.apply(a[f],d)===!1)break}else for(;g<h;)if(c.apply(a[g++],d)===!1)break}else if(i){for(f in a)if(c.call(a[f],f,a[f])===!1)break}else for(;g<h;)if(c.call(a[g],g,a[g++])===!1)break;return a},trim:G?function(a){return a==null?"":G.call(a)}:function(a){return a==null?"":(a+"").replace(k,"").replace(l,"")},makeArray:function(a,b){var c=b||[];if(a!=null){var d=e.type(a);a.length==null||d==="string"||d==="function"||d==="regexp"||e.isWindow(a)?E.call(c,a):e.merge(c,a)}return c},inArray:function(a,b,c){var d;if(b){if(H)return H.call(b,a,c);d=b.length,c=c?c<0?Math.max(0,d+c):c:0;for(;c<d;c++)if(c in b&&b[c]===a)return c}return-1},merge:function(a,c){var d=a.length,e=0;if(typeof c.length=="number")for(var f=c.length;e<f;e++)a[d++]=c[e];else while(c[e]!==b)a[d++]=c[e++];a.length=d;return a},grep:function(a,b,c){var d=[],e;c=!!c;for(var f=0,g=a.length;f<g;f++)e=!!b(a[f],f),c!==e&&d.push(a[f]);return d},map:function(a,c,d){var f,g,h=[],i=0,j=a.length,k=a instanceof e||j!==b&&typeof j=="number"&&(j>0&&a[0]&&a[j-1]||j===0||e.isArray(a));if(k)for(;i<j;i++)f=c(a[i],i,d),f!=null&&(h[h.length]=f);else for(g in a)f=c(a[g],g,d),f!=null&&(h[h.length]=f);return h.concat.apply([],h)},guid:1,proxy:function(a,c){if(typeof c=="string"){var d=a[c];c=a,a=d}if(!e.isFunction(a))return b;var f=F.call(arguments,2),g=function(){return a.apply(c,f.concat(F.call(arguments)))};g.guid=a.guid=a.guid||g.guid||e.guid++;return g},access:function(a,c,d,f,g,h,i){var j,k=d==null,l=0,m=a.length;if(d&&typeof d=="object"){for(l in d)e.access(a,c,l,d[l],1,h,f);g=1}else if(f!==b){j=i===b&&e.isFunction(f),k&&(j?(j=c,c=function(a,b,c){return j.call(e(a),c)}):(c.call(a,f),c=null));if(c)for(;l<m;l++)c(a[l],d,j?f.call(a[l],l,c(a[l],d)):f,i);g=1}return g?a:k?c.call(a):m?c(a[0],d):h},now:function(){return(new Date).getTime()},uaMatch:function(a){a=a.toLowerCase();var b=r.exec(a)||s.exec(a)||t.exec(a)||a.indexOf("compatible")<0&&u.exec(a)||[];return{browser:b[1]||"",version:b[2]||"0"}},sub:function(){function a(b,c){return new a.fn.init(b,c)}e.extend(!0,a,this),a.superclass=this,a.fn=a.prototype=this(),a.fn.constructor=a,a.sub=this.sub,a.fn.init=function(d,f){f&&f instanceof e&&!(f instanceof a)&&(f=a(f));return e.fn.init.call(this,d,f,b)},a.fn.init.prototype=a.fn;var b=a(c);return a},browser:{}}),e.each("Boolean Number String Function Array Date RegExp Object".split(" "),function(a,b){I["[object "+b+"]"]=b.toLowerCase()}),z=e.uaMatch(y),z.browser&&(e.browser[z.browser]=!0,e.browser.version=z.version),e.browser.webkit&&(e.browser.safari=!0),j.test(" ")&&(k=/^[\s\xA0]+/,l=/[\s\xA0]+$/),h=e(c),c.addEventListener?B=function(){c.removeEventListener("DOMContentLoaded",B,!1),e.ready()}:c.attachEvent&&(B=function(){c.readyState==="complete"&&(c.detachEvent("onreadystatechange",B),e.ready())});return e}(),g={};f.Callbacks=function(a){a=a?g[a]||h(a):{};var c=[],d=[],e,i,j,k,l,m,n=function(b){var d,e,g,h,i;for(d=0,e=b.length;d<e;d++)g=b[d],h=f.type(g),h==="array"?n(g):h==="function"&&(!a.unique||!p.has(g))&&c.push(g)},o=function(b,f){f=f||[],e=!a.memory||[b,f],i=!0,j=!0,m=k||0,k=0,l=c.length;for(;c&&m<l;m++)if(c[m].apply(b,f)===!1&&a.stopOnFalse){e=!0;break}j=!1,c&&(a.once?e===!0?p.disable():c=[]:d&&d.length&&(e=d.shift(),p.fireWith(e[0],e[1])))},p={add:function(){if(c){var a=c.length;n(arguments),j?l=c.length:e&&e!==!0&&(k=a,o(e[0],e[1]))}return this},remove:function(){if(c){var b=arguments,d=0,e=b.length;for(;d<e;d++)for(var f=0;f<c.length;f++)if(b[d]===c[f]){j&&f<=l&&(l--,f<=m&&m--),c.splice(f--,1);if(a.unique)break}}return this},has:function(a){if(c){var b=0,d=c.length;for(;b<d;b++)if(a===c[b])return!0}return!1},empty:function(){c=[];return this},disable:function(){c=d=e=b;return this},disabled:function(){return!c},lock:function(){d=b,(!e||e===!0)&&p.disable();return this},locked:function(){return!d},fireWith:function(b,c){d&&(j?a.once||d.push([b,c]):(!a.once||!e)&&o(b,c));return this},fire:function(){p.fireWith(this,arguments);return this},fired:function(){return!!i}};return p};var i=[].slice;f.extend({Deferred:function(a){var b=f.Callbacks("once memory"),c=f.Callbacks("once memory"),d=f.Callbacks("memory"),e="pending",g={resolve:b,reject:c,notify:d},h={done:b.add,fail:c.add,progress:d.add,state:function(){return e},isResolved:b.fired,isRejected:c.fired,then:function(a,b,c){i.done(a).fail(b).progress(c);return this},always:function(){i.done.apply(i,arguments).fail.apply(i,arguments);return this},pipe:function(a,b,c){return f.Deferred(function(d){f.each({done:[a,"resolve"],fail:[b,"reject"],progress:[c,"notify"]},function(a,b){var c=b[0],e=b[1],g;f.isFunction(c)?i[a](function(){g=c.apply(this,arguments),g&&f.isFunction(g.promise)?g.promise().then(d.resolve,d.reject,d.notify):d[e+"With"](this===i?d:this,[g])}):i[a](d[e])})}).promise()},promise:function(a){if(a==null)a=h;else for(var b in h)a[b]=h[b];return a}},i=h.promise({}),j;for(j in g)i[j]=g[j].fire,i[j+"With"]=g[j].fireWith;i.done(function(){e="resolved"},c.disable,d.lock).fail(function(){e="rejected"},b.disable,d.lock),a&&a.call(i,i);return i},when:function(a){function m(a){return function(b){e[a]=arguments.length>1?i.call(arguments,0):b,j.notifyWith(k,e)}}function l(a){return function(c){b[a]=arguments.length>1?i.call(arguments,0):c,--g||j.resolveWith(j,b)}}var b=i.call(arguments,0),c=0,d=b.length,e=Array(d),g=d,h=d,j=d<=1&&a&&f.isFunction(a.promise)?a:f.Deferred(),k=j.promise();if(d>1){for(;c<d;c++)b[c]&&b[c].promise&&f.isFunction(b[c].promise)?b[c].promise().then(l(c),j.reject,m(c)):--g;g||j.resolveWith(j,b)}else j!==a&&j.resolveWith(j,d?[a]:[]);return k}}),f.support=function(){var b,d,e,g,h,i,j,k,l,m,n,o,p=c.createElement("div"),q=c.documentElement;p.setAttribute("className","t"),p.innerHTML="   <link/><table></table><a href='/a' style='top:1px;float:left;opacity:.55;'>a</a><input type='checkbox'/>",d=p.getElementsByTagName("*"),e=p.getElementsByTagName("a")[0];if(!d||!d.length||!e)return{};g=c.createElement("select"),h=g.appendChild(c.createElement("option")),i=p.getElementsByTagName("input")[0],b={leadingWhitespace:p.firstChild.nodeType===3,tbody:!p.getElementsByTagName("tbody").length,htmlSerialize:!!p.getElementsByTagName("link").length,style:/top/.test(e.getAttribute("style")),hrefNormalized:e.getAttribute("href")==="/a",opacity:/^0.55/.test(e.style.opacity),cssFloat:!!e.style.cssFloat,checkOn:i.value==="on",optSelected:h.selected,getSetAttribute:p.className!=="t",enctype:!!c.createElement("form").enctype,html5Clone:c.createElement("nav").cloneNode(!0).outerHTML!=="<:nav></:nav>",submitBubbles:!0,changeBubbles:!0,focusinBubbles:!1,deleteExpando:!0,noCloneEvent:!0,inlineBlockNeedsLayout:!1,shrinkWrapBlocks:!1,reliableMarginRight:!0,pixelMargin:!0},f.boxModel=b.boxModel=c.compatMode==="CSS1Compat",i.checked=!0,b.noCloneChecked=i.cloneNode(!0).checked,g.disabled=!0,b.optDisabled=!h.disabled;try{delete p.test}catch(r){b.deleteExpando=!1}!p.addEventListener&&p.attachEvent&&p.fireEvent&&(p.attachEvent("onclick",function(){b.noCloneEvent=!1}),p.cloneNode(!0).fireEvent("onclick")),i=c.createElement("input"),i.value="t",i.setAttribute("type","radio"),b.radioValue=i.value==="t",i.setAttribute("checked","checked"),i.setAttribute("name","t"),p.appendChild(i),j=c.createDocumentFragment(),j.appendChild(p.lastChild),b.checkClone=j.cloneNode(!0).cloneNode(!0).lastChild.checked,b.appendChecked=i.checked,j.removeChild(i),j.appendChild(p);if(p.attachEvent)for(n in{submit:1,change:1,focusin:1})m="on"+n,o=m in p,o||(p.setAttribute(m,"return;"),o=typeof p[m]=="function"),b[n+"Bubbles"]=o;j.removeChild(p),j=g=h=p=i=null,f(function(){var d,e,g,h,i,j,l,m,n,q,r,s,t,u=c.getElementsByTagName("body")[0];!u||(m=1,t="padding:0;margin:0;border:",r="position:absolute;top:0;left:0;width:1px;height:1px;",s=t+"0;visibility:hidden;",n="style='"+r+t+"5px solid #000;",q="<div "+n+"display:block;'><div style='"+t+"0;display:block;overflow:hidden;'></div></div>"+"<table "+n+"' cellpadding='0' cellspacing='0'>"+"<tr><td></td></tr></table>",d=c.createElement("div"),d.style.cssText=s+"width:0;height:0;position:static;top:0;margin-top:"+m+"px",u.insertBefore(d,u.firstChild),p=c.createElement("div"),d.appendChild(p),p.innerHTML="<table><tr><td style='"+t+"0;display:none'></td><td>t</td></tr></table>",k=p.getElementsByTagName("td"),o=k[0].offsetHeight===0,k[0].style.display="",k[1].style.display="none",b.reliableHiddenOffsets=o&&k[0].offsetHeight===0,a.getComputedStyle&&(p.innerHTML="",l=c.createElement("div"),l.style.width="0",l.style.marginRight="0",p.style.width="2px",p.appendChild(l),b.reliableMarginRight=(parseInt((a.getComputedStyle(l,null)||{marginRight:0}).marginRight,10)||0)===0),typeof p.style.zoom!="undefined"&&(p.innerHTML="",p.style.width=p.style.padding="1px",p.style.border=0,p.style.overflow="hidden",p.style.display="inline",p.style.zoom=1,b.inlineBlockNeedsLayout=p.offsetWidth===3,p.style.display="block",p.style.overflow="visible",p.innerHTML="<div style='width:5px;'></div>",b.shrinkWrapBlocks=p.offsetWidth!==3),p.style.cssText=r+s,p.innerHTML=q,e=p.firstChild,g=e.firstChild,i=e.nextSibling.firstChild.firstChild,j={doesNotAddBorder:g.offsetTop!==5,doesAddBorderForTableAndCells:i.offsetTop===5},g.style.position="fixed",g.style.top="20px",j.fixedPosition=g.offsetTop===20||g.offsetTop===15,g.style.position=g.style.top="",e.style.overflow="hidden",e.style.position="relative",j.subtractsBorderForOverflowNotVisible=g.offsetTop===-5,j.doesNotIncludeMarginInBodyOffset=u.offsetTop!==m,a.getComputedStyle&&(p.style.marginTop="1%",b.pixelMargin=(a.getComputedStyle(p,null)||{marginTop:0}).marginTop!=="1%"),typeof d.style.zoom!="undefined"&&(d.style.zoom=1),u.removeChild(d),l=p=d=null,f.extend(b,j))});return b}();var j=/^(?:\{.*\}|\[.*\])$/,k=/([A-Z])/g;f.extend({cache:{},uuid:0,expando:"jQuery"+(f.fn.jquery+Math.random()).replace(/\D/g,""),noData:{embed:!0,object:"clsid:D27CDB6E-AE6D-11cf-96B8-444553540000",applet:!0},hasData:function(a){a=a.nodeType?f.cache[a[f.expando]]:a[f.expando];return!!a&&!m(a)},data:function(a,c,d,e){if(!!f.acceptData(a)){var g,h,i,j=f.expando,k=typeof c=="string",l=a.nodeType,m=l?f.cache:a,n=l?a[j]:a[j]&&j,o=c==="events";if((!n||!m[n]||!o&&!e&&!m[n].data)&&k&&d===b)return;n||(l?a[j]=n=++f.uuid:n=j),m[n]||(m[n]={},l||(m[n].toJSON=f.noop));if(typeof c=="object"||typeof c=="function")e?m[n]=f.extend(m[n],c):m[n].data=f.extend(m[n].data,c);g=h=m[n],e||(h.data||(h.data={}),h=h.data),d!==b&&(h[f.camelCase(c)]=d);if(o&&!h[c])return g.events;k?(i=h[c],i==null&&(i=h[f.camelCase(c)])):i=h;return i}},removeData:function(a,b,c){if(!!f.acceptData(a)){var d,e,g,h=f.expando,i=a.nodeType,j=i?f.cache:a,k=i?a[h]:h;if(!j[k])return;if(b){d=c?j[k]:j[k].data;if(d){f.isArray(b)||(b in d?b=[b]:(b=f.camelCase(b),b in d?b=[b]:b=b.split(" ")));for(e=0,g=b.length;e<g;e++)delete d[b[e]];if(!(c?m:f.isEmptyObject)(d))return}}if(!c){delete j[k].data;if(!m(j[k]))return}f.support.deleteExpando||!j.setInterval?delete j[k]:j[k]=null,i&&(f.support.deleteExpando?delete a[h]:a.removeAttribute?a.removeAttribute(h):a[h]=null)}},_data:function(a,b,c){return f.data(a,b,c,!0)},acceptData:function(a){if(a.nodeName){var b=f.noData[a.nodeName.toLowerCase()];if(b)return b!==!0&&a.getAttribute("classid")===b}return!0}}),f.fn.extend({data:function(a,c){var d,e,g,h,i,j=this[0],k=0,m=null;if(a===b){if(this.length){m=f.data(j);if(j.nodeType===1&&!f._data(j,"parsedAttrs")){g=j.attributes;for(i=g.length;k<i;k++)h=g[k].name,h.indexOf("data-")===0&&(h=f.camelCase(h.substring(5)),l(j,h,m[h]));f._data(j,"parsedAttrs",!0)}}return m}if(typeof a=="object")return this.each(function(){f.data(this,a)});d=a.split(".",2),d[1]=d[1]?"."+d[1]:"",e=d[1]+"!";return f.access(this,function(c){if(c===b){m=this.triggerHandler("getData"+e,[d[0]]),m===b&&j&&(m=f.data(j,a),m=l(j,a,m));return m===b&&d[1]?this.data(d[0]):m}d[1]=c,this.each(function(){var b=f(this);b.triggerHandler("setData"+e,d),f.data(this,a,c),b.triggerHandler("changeData"+e,d)})},null,c,arguments.length>1,null,!1)},removeData:function(a){return this.each(function(){f.removeData(this,a)})}}),f.extend({_mark:function(a,b){a&&(b=(b||"fx")+"mark",f._data(a,b,(f._data(a,b)||0)+1))},_unmark:function(a,b,c){a!==!0&&(c=b,b=a,a=!1);if(b){c=c||"fx";var d=c+"mark",e=a?0:(f._data(b,d)||1)-1;e?f._data(b,d,e):(f.removeData(b,d,!0),n(b,c,"mark"))}},queue:function(a,b,c){var d;if(a){b=(b||"fx")+"queue",d=f._data(a,b),c&&(!d||f.isArray(c)?d=f._data(a,b,f.makeArray(c)):d.push(c));return d||[]}},dequeue:function(a,b){b=b||"fx";var c=f.queue(a,b),d=c.shift(),e={};d==="inprogress"&&(d=c.shift()),d&&(b==="fx"&&c.unshift("inprogress"),f._data(a,b+".run",e),d.call(a,function(){f.dequeue(a,b)},e)),c.length||(f.removeData(a,b+"queue "+b+".run",!0),n(a,b,"queue"))}}),f.fn.extend({queue:function(a,c){var d=2;typeof a!="string"&&(c=a,a="fx",d--);if(arguments.length<d)return f.queue(this[0],a);return c===b?this:this.each(function(){var b=f.queue(this,a,c);a==="fx"&&b[0]!=="inprogress"&&f.dequeue(this,a)})},dequeue:function(a){return this.each(function(){f.dequeue(this,a)})},delay:function(a,b){a=f.fx?f.fx.speeds[a]||a:a,b=b||"fx";return this.queue(b,function(b,c){var d=setTimeout(b,a);c.stop=function(){clearTimeout(d)}})},clearQueue:function(a){return this.queue(a||"fx",[])},promise:function(a,c){function m(){--h||d.resolveWith(e,[e])}typeof a!="string"&&(c=a,a=b),a=a||"fx";var d=f.Deferred(),e=this,g=e.length,h=1,i=a+"defer",j=a+"queue",k=a+"mark",l;while(g--)if(l=f.data(e[g],i,b,!0)||(f.data(e[g],j,b,!0)||f.data(e[g],k,b,!0))&&f.data(e[g],i,f.Callbacks("once memory"),!0))h++,l.add(m);m();return d.promise(c)}});var o=/[\n\t\r]/g,p=/\s+/,q=/\r/g,r=/^(?:button|input)$/i,s=/^(?:button|input|object|select|textarea)$/i,t=/^a(?:rea)?$/i,u=/^(?:autofocus|autoplay|async|checked|controls|defer|disabled|hidden|loop|multiple|open|readonly|required|scoped|selected)$/i,v=f.support.getSetAttribute,w,x,y;f.fn.extend({attr:function(a,b){return f.access(this,f.attr,a,b,arguments.length>1)},removeAttr:function(a){return this.each(function(){f.removeAttr(this,a)})},prop:function(a,b){return f.access(this,f.prop,a,b,arguments.length>1)},removeProp:function(a){a=f.propFix[a]||a;return this.each(function(){try{this[a]=b,delete this[a]}catch(c){}})},addClass:function(a){var b,c,d,e,g,h,i;if(f.isFunction(a))return this.each(function(b){f(this).addClass(a.call(this,b,this.className))});if(a&&typeof a=="string"){b=a.split(p);for(c=0,d=this.length;c<d;c++){e=this[c];if(e.nodeType===1)if(!e.className&&b.length===1)e.className=a;else{g=" "+e.className+" ";for(h=0,i=b.length;h<i;h++)~g.indexOf(" "+b[h]+" ")||(g+=b[h]+" ");e.className=f.trim(g)}}}return this},removeClass:function(a){var c,d,e,g,h,i,j;if(f.isFunction(a))return this.each(function(b){f(this).removeClass(a.call(this,b,this.className))});if(a&&typeof a=="string"||a===b){c=(a||"").split(p);for(d=0,e=this.length;d<e;d++){g=this[d];if(g.nodeType===1&&g.className)if(a){h=(" "+g.className+" ").replace(o," ");for(i=0,j=c.length;i<j;i++)h=h.replace(" "+c[i]+" "," ");g.className=f.trim(h)}else g.className=""}}return this},toggleClass:function(a,b){var c=typeof a,d=typeof b=="boolean";if(f.isFunction(a))return this.each(function(c){f(this).toggleClass(a.call(this,c,this.className,b),b)});return this.each(function(){if(c==="string"){var e,g=0,h=f(this),i=b,j=a.split(p);while(e=j[g++])i=d?i:!h.hasClass(e),h[i?"addClass":"removeClass"](e)}else if(c==="undefined"||c==="boolean")this.className&&f._data(this,"__className__",this.className),this.className=this.className||a===!1?"":f._data(this,"__className__")||""})},hasClass:function(a){var b=" "+a+" ",c=0,d=this.length;for(;c<d;c++)if(this[c].nodeType===1&&(" "+this[c].className+" ").replace(o," ").indexOf(b)>-1)return!0;return!1},val:function(a){var c,d,e,g=this[0];{if(!!arguments.length){e=f.isFunction(a);return this.each(function(d){var g=f(this),h;if(this.nodeType===1){e?h=a.call(this,d,g.val()):h=a,h==null?h="":typeof h=="number"?h+="":f.isArray(h)&&(h=f.map(h,function(a){return a==null?"":a+""})),c=f.valHooks[this.type]||f.valHooks[this.nodeName.toLowerCase()];if(!c||!("set"in c)||c.set(this,h,"value")===b)this.value=h}})}if(g){c=f.valHooks[g.type]||f.valHooks[g.nodeName.toLowerCase()];if(c&&"get"in c&&(d=c.get(g,"value"))!==b)return d;d=g.value;return typeof d=="string"?d.replace(q,""):d==null?"":d}}}}),f.extend({valHooks:{option:{get:function(a){var b=a.attributes.value;return!b||b.specified?a.value:a.text}},select:{get:function(a){var b,c,d,e,g=a.selectedIndex,h=[],i=a.options,j=a.type==="select-one";if(g<0)return null;c=j?g:0,d=j?g+1:i.length;for(;c<d;c++){e=i[c];if(e.selected&&(f.support.optDisabled?!e.disabled:e.getAttribute("disabled")===null)&&(!e.parentNode.disabled||!f.nodeName(e.parentNode,"optgroup"))){b=f(e).val();if(j)return b;h.push(b)}}if(j&&!h.length&&i.length)return f(i[g]).val();return h},set:function(a,b){var c=f.makeArray(b);f(a).find("option").each(function(){this.selected=f.inArray(f(this).val(),c)>=0}),c.length||(a.selectedIndex=-1);return c}}},attrFn:{val:!0,css:!0,html:!0,text:!0,data:!0,width:!0,height:!0,offset:!0},attr:function(a,c,d,e){var g,h,i,j=a.nodeType;if(!!a&&j!==3&&j!==8&&j!==2){if(e&&c in f.attrFn)return f(a)[c](d);if(typeof a.getAttribute=="undefined")return f.prop(a,c,d);i=j!==1||!f.isXMLDoc(a),i&&(c=c.toLowerCase(),h=f.attrHooks[c]||(u.test(c)?x:w));if(d!==b){if(d===null){f.removeAttr(a,c);return}if(h&&"set"in h&&i&&(g=h.set(a,d,c))!==b)return g;a.setAttribute(c,""+d);return d}if(h&&"get"in h&&i&&(g=h.get(a,c))!==null)return g;g=a.getAttribute(c);return g===null?b:g}},removeAttr:function(a,b){var c,d,e,g,h,i=0;if(b&&a.nodeType===1){d=b.toLowerCase().split(p),g=d.length;for(;i<g;i++)e=d[i],e&&(c=f.propFix[e]||e,h=u.test(e),h||f.attr(a,e,""),a.removeAttribute(v?e:c),h&&c in a&&(a[c]=!1))}},attrHooks:{type:{set:function(a,b){if(r.test(a.nodeName)&&a.parentNode)f.error("type property can't be changed");else if(!f.support.radioValue&&b==="radio"&&f.nodeName(a,"input")){var c=a.value;a.setAttribute("type",b),c&&(a.value=c);return b}}},value:{get:function(a,b){if(w&&f.nodeName(a,"button"))return w.get(a,b);return b in a?a.value:null},set:function(a,b,c){if(w&&f.nodeName(a,"button"))return w.set(a,b,c);a.value=b}}},propFix:{tabindex:"tabIndex",readonly:"readOnly","for":"htmlFor","class":"className",maxlength:"maxLength",cellspacing:"cellSpacing",cellpadding:"cellPadding",rowspan:"rowSpan",colspan:"colSpan",usemap:"useMap",frameborder:"frameBorder",contenteditable:"contentEditable"},prop:function(a,c,d){var e,g,h,i=a.nodeType;if(!!a&&i!==3&&i!==8&&i!==2){h=i!==1||!f.isXMLDoc(a),h&&(c=f.propFix[c]||c,g=f.propHooks[c]);return d!==b?g&&"set"in g&&(e=g.set(a,d,c))!==b?e:a[c]=d:g&&"get"in g&&(e=g.get(a,c))!==null?e:a[c]}},propHooks:{tabIndex:{get:function(a){var c=a.getAttributeNode("tabindex");return c&&c.specified?parseInt(c.value,10):s.test(a.nodeName)||t.test(a.nodeName)&&a.href?0:b}}}}),f.attrHooks.tabindex=f.propHooks.tabIndex,x={get:function(a,c){var d,e=f.prop(a,c);return e===!0||typeof e!="boolean"&&(d=a.getAttributeNode(c))&&d.nodeValue!==!1?c.toLowerCase():b},set:function(a,b,c){var d;b===!1?f.removeAttr(a,c):(d=f.propFix[c]||c,d in a&&(a[d]=!0),a.setAttribute(c,c.toLowerCase()));return c}},v||(y={name:!0,id:!0,coords:!0},w=f.valHooks.button={get:function(a,c){var d;d=a.getAttributeNode(c);return d&&(y[c]?d.nodeValue!=="":d.specified)?d.nodeValue:b},set:function(a,b,d){var e=a.getAttributeNode(d);e||(e=c.createAttribute(d),a.setAttributeNode(e));return e.nodeValue=b+""}},f.attrHooks.tabindex.set=w.set,f.each(["width","height"],function(a,b){f.attrHooks[b]=f.extend(f.attrHooks[b],{set:function(a,c){if(c===""){a.setAttribute(b,"auto");return c}}})}),f.attrHooks.contenteditable={get:w.get,set:function(a,b,c){b===""&&(b="false"),w.set(a,b,c)}}),f.support.hrefNormalized||f.each(["href","src","width","height"],function(a,c){f.attrHooks[c]=f.extend(f.attrHooks[c],{get:function(a){var d=a.getAttribute(c,2);return d===null?b:d}})}),f.support.style||(f.attrHooks.style={get:function(a){return a.style.cssText.toLowerCase()||b},set:function(a,b){return a.style.cssText=""+b}}),f.support.optSelected||(f.propHooks.selected=f.extend(f.propHooks.selected,{get:function(a){var b=a.parentNode;b&&(b.selectedIndex,b.parentNode&&b.parentNode.selectedIndex);return null}})),f.support.enctype||(f.propFix.enctype="encoding"),f.support.checkOn||f.each(["radio","checkbox"],function(){f.valHooks[this]={get:function(a){return a.getAttribute("value")===null?"on":a.value}}}),f.each(["radio","checkbox"],function(){f.valHooks[this]=f.extend(f.valHooks[this],{set:function(a,b){if(f.isArray(b))return a.checked=f.inArray(f(a).val(),b)>=0}})});var z=/^(?:textarea|input|select)$/i,A=/^([^\.]*)?(?:\.(.+))?$/,B=/(?:^|\s)hover(\.\S+)?\b/,C=/^key/,D=/^(?:mouse|contextmenu)|click/,E=/^(?:focusinfocus|focusoutblur)$/,F=/^(\w*)(?:#([\w\-]+))?(?:\.([\w\-]+))?$/,G=function(
                    a){var b=F.exec(a);b&&(b[1]=(b[1]||"").toLowerCase(),b[3]=b[3]&&new RegExp("(?:^|\\s)"+b[3]+"(?:\\s|$)"));return b},H=function(a,b){var c=a.attributes||{};return(!b[1]||a.nodeName.toLowerCase()===b[1])&&(!b[2]||(c.id||{}).value===b[2])&&(!b[3]||b[3].test((c["class"]||{}).value))},I=function(a){return f.event.special.hover?a:a.replace(B,"mouseenter$1 mouseleave$1")};f.event={add:function(a,c,d,e,g){var h,i,j,k,l,m,n,o,p,q,r,s;if(!(a.nodeType===3||a.nodeType===8||!c||!d||!(h=f._data(a)))){d.handler&&(p=d,d=p.handler,g=p.selector),d.guid||(d.guid=f.guid++),j=h.events,j||(h.events=j={}),i=h.handle,i||(h.handle=i=function(a){return typeof f!="undefined"&&(!a||f.event.triggered!==a.type)?f.event.dispatch.apply(i.elem,arguments):b},i.elem=a),c=f.trim(I(c)).split(" ");for(k=0;k<c.length;k++){l=A.exec(c[k])||[],m=l[1],n=(l[2]||"").split(".").sort(),s=f.event.special[m]||{},m=(g?s.delegateType:s.bindType)||m,s=f.event.special[m]||{},o=f.extend({type:m,origType:l[1],data:e,handler:d,guid:d.guid,selector:g,quick:g&&G(g),namespace:n.join(".")},p),r=j[m];if(!r){r=j[m]=[],r.delegateCount=0;if(!s.setup||s.setup.call(a,e,n,i)===!1)a.addEventListener?a.addEventListener(m,i,!1):a.attachEvent&&a.attachEvent("on"+m,i)}s.add&&(s.add.call(a,o),o.handler.guid||(o.handler.guid=d.guid)),g?r.splice(r.delegateCount++,0,o):r.push(o),f.event.global[m]=!0}a=null}},global:{},remove:function(a,b,c,d,e){var g=f.hasData(a)&&f._data(a),h,i,j,k,l,m,n,o,p,q,r,s;if(!!g&&!!(o=g.events)){b=f.trim(I(b||"")).split(" ");for(h=0;h<b.length;h++){i=A.exec(b[h])||[],j=k=i[1],l=i[2];if(!j){for(j in o)f.event.remove(a,j+b[h],c,d,!0);continue}p=f.event.special[j]||{},j=(d?p.delegateType:p.bindType)||j,r=o[j]||[],m=r.length,l=l?new RegExp("(^|\\.)"+l.split(".").sort().join("\\.(?:.*\\.)?")+"(\\.|$)"):null;for(n=0;n<r.length;n++)s=r[n],(e||k===s.origType)&&(!c||c.guid===s.guid)&&(!l||l.test(s.namespace))&&(!d||d===s.selector||d==="**"&&s.selector)&&(r.splice(n--,1),s.selector&&r.delegateCount--,p.remove&&p.remove.call(a,s));r.length===0&&m!==r.length&&((!p.teardown||p.teardown.call(a,l)===!1)&&f.removeEvent(a,j,g.handle),delete o[j])}f.isEmptyObject(o)&&(q=g.handle,q&&(q.elem=null),f.removeData(a,["events","handle"],!0))}},customEvent:{getData:!0,setData:!0,changeData:!0},trigger:function(c,d,e,g){if(!e||e.nodeType!==3&&e.nodeType!==8){var h=c.type||c,i=[],j,k,l,m,n,o,p,q,r,s;if(E.test(h+f.event.triggered))return;h.indexOf("!")>=0&&(h=h.slice(0,-1),k=!0),h.indexOf(".")>=0&&(i=h.split("."),h=i.shift(),i.sort());if((!e||f.event.customEvent[h])&&!f.event.global[h])return;c=typeof c=="object"?c[f.expando]?c:new f.Event(h,c):new f.Event(h),c.type=h,c.isTrigger=!0,c.exclusive=k,c.namespace=i.join("."),c.namespace_re=c.namespace?new RegExp("(^|\\.)"+i.join("\\.(?:.*\\.)?")+"(\\.|$)"):null,o=h.indexOf(":")<0?"on"+h:"";if(!e){j=f.cache;for(l in j)j[l].events&&j[l].events[h]&&f.event.trigger(c,d,j[l].handle.elem,!0);return}c.result=b,c.target||(c.target=e),d=d!=null?f.makeArray(d):[],d.unshift(c),p=f.event.special[h]||{};if(p.trigger&&p.trigger.apply(e,d)===!1)return;r=[[e,p.bindType||h]];if(!g&&!p.noBubble&&!f.isWindow(e)){s=p.delegateType||h,m=E.test(s+h)?e:e.parentNode,n=null;for(;m;m=m.parentNode)r.push([m,s]),n=m;n&&n===e.ownerDocument&&r.push([n.defaultView||n.parentWindow||a,s])}for(l=0;l<r.length&&!c.isPropagationStopped();l++)m=r[l][0],c.type=r[l][1],q=(f._data(m,"events")||{})[c.type]&&f._data(m,"handle"),q&&q.apply(m,d),q=o&&m[o],q&&f.acceptData(m)&&q.apply(m,d)===!1&&c.preventDefault();c.type=h,!g&&!c.isDefaultPrevented()&&(!p._default||p._default.apply(e.ownerDocument,d)===!1)&&(h!=="click"||!f.nodeName(e,"a"))&&f.acceptData(e)&&o&&e[h]&&(h!=="focus"&&h!=="blur"||c.target.offsetWidth!==0)&&!f.isWindow(e)&&(n=e[o],n&&(e[o]=null),f.event.triggered=h,e[h](),f.event.triggered=b,n&&(e[o]=n));return c.result}},dispatch:function(c){c=f.event.fix(c||a.event);var d=(f._data(this,"events")||{})[c.type]||[],e=d.delegateCount,g=[].slice.call(arguments,0),h=!c.exclusive&&!c.namespace,i=f.event.special[c.type]||{},j=[],k,l,m,n,o,p,q,r,s,t,u;g[0]=c,c.delegateTarget=this;if(!i.preDispatch||i.preDispatch.call(this,c)!==!1){if(e&&(!c.button||c.type!=="click")){n=f(this),n.context=this.ownerDocument||this;for(m=c.target;m!=this;m=m.parentNode||this)if(m.disabled!==!0){p={},r=[],n[0]=m;for(k=0;k<e;k++)s=d[k],t=s.selector,p[t]===b&&(p[t]=s.quick?H(m,s.quick):n.is(t)),p[t]&&r.push(s);r.length&&j.push({elem:m,matches:r})}}d.length>e&&j.push({elem:this,matches:d.slice(e)});for(k=0;k<j.length&&!c.isPropagationStopped();k++){q=j[k],c.currentTarget=q.elem;for(l=0;l<q.matches.length&&!c.isImmediatePropagationStopped();l++){s=q.matches[l];if(h||!c.namespace&&!s.namespace||c.namespace_re&&c.namespace_re.test(s.namespace))c.data=s.data,c.handleObj=s,o=((f.event.special[s.origType]||{}).handle||s.handler).apply(q.elem,g),o!==b&&(c.result=o,o===!1&&(c.preventDefault(),c.stopPropagation()))}}i.postDispatch&&i.postDispatch.call(this,c);return c.result}},props:"attrChange attrName relatedNode srcElement altKey bubbles cancelable ctrlKey currentTarget eventPhase metaKey relatedTarget shiftKey target timeStamp view which".split(" "),fixHooks:{},keyHooks:{props:"char charCode key keyCode".split(" "),filter:function(a,b){a.which==null&&(a.which=b.charCode!=null?b.charCode:b.keyCode);return a}},mouseHooks:{props:"button buttons clientX clientY fromElement offsetX offsetY pageX pageY screenX screenY toElement".split(" "),filter:function(a,d){var e,f,g,h=d.button,i=d.fromElement;a.pageX==null&&d.clientX!=null&&(e=a.target.ownerDocument||c,f=e.documentElement,g=e.body,a.pageX=d.clientX+(f&&f.scrollLeft||g&&g.scrollLeft||0)-(f&&f.clientLeft||g&&g.clientLeft||0),a.pageY=d.clientY+(f&&f.scrollTop||g&&g.scrollTop||0)-(f&&f.clientTop||g&&g.clientTop||0)),!a.relatedTarget&&i&&(a.relatedTarget=i===a.target?d.toElement:i),!a.which&&h!==b&&(a.which=h&1?1:h&2?3:h&4?2:0);return a}},fix:function(a){if(a[f.expando])return a;var d,e,g=a,h=f.event.fixHooks[a.type]||{},i=h.props?this.props.concat(h.props):this.props;a=f.Event(g);for(d=i.length;d;)e=i[--d],a[e]=g[e];a.target||(a.target=g.srcElement||c),a.target.nodeType===3&&(a.target=a.target.parentNode),a.metaKey===b&&(a.metaKey=a.ctrlKey);return h.filter?h.filter(a,g):a},special:{ready:{setup:f.bindReady},load:{noBubble:!0},focus:{delegateType:"focusin"},blur:{delegateType:"focusout"},beforeunload:{setup:function(a,b,c){f.isWindow(this)&&(this.onbeforeunload=c)},teardown:function(a,b){this.onbeforeunload===b&&(this.onbeforeunload=null)}}},simulate:function(a,b,c,d){var e=f.extend(new f.Event,c,{type:a,isSimulated:!0,originalEvent:{}});d?f.event.trigger(e,null,b):f.event.dispatch.call(b,e),e.isDefaultPrevented()&&c.preventDefault()}},f.event.handle=f.event.dispatch,f.removeEvent=c.removeEventListener?function(a,b,c){a.removeEventListener&&a.removeEventListener(b,c,!1)}:function(a,b,c){a.detachEvent&&a.detachEvent("on"+b,c)},f.Event=function(a,b){if(!(this instanceof f.Event))return new f.Event(a,b);a&&a.type?(this.originalEvent=a,this.type=a.type,this.isDefaultPrevented=a.defaultPrevented||a.returnValue===!1||a.getPreventDefault&&a.getPreventDefault()?K:J):this.type=a,b&&f.extend(this,b),this.timeStamp=a&&a.timeStamp||f.now(),this[f.expando]=!0},f.Event.prototype={preventDefault:function(){this.isDefaultPrevented=K;var a=this.originalEvent;!a||(a.preventDefault?a.preventDefault():a.returnValue=!1)},stopPropagation:function(){this.isPropagationStopped=K;var a=this.originalEvent;!a||(a.stopPropagation&&a.stopPropagation(),a.cancelBubble=!0)},stopImmediatePropagation:function(){this.isImmediatePropagationStopped=K,this.stopPropagation()},isDefaultPrevented:J,isPropagationStopped:J,isImmediatePropagationStopped:J},f.each({mouseenter:"mouseover",mouseleave:"mouseout"},function(a,b){f.event.special[a]={delegateType:b,bindType:b,handle:function(a){var c=this,d=a.relatedTarget,e=a.handleObj,g=e.selector,h;if(!d||d!==c&&!f.contains(c,d))a.type=e.origType,h=e.handler.apply(this,arguments),a.type=b;return h}}}),f.support.submitBubbles||(f.event.special.submit={setup:function(){if(f.nodeName(this,"form"))return!1;f.event.add(this,"click._submit keypress._submit",function(a){var c=a.target,d=f.nodeName(c,"input")||f.nodeName(c,"button")?c.form:b;d&&!d._submit_attached&&(f.event.add(d,"submit._submit",function(a){a._submit_bubble=!0}),d._submit_attached=!0)})},postDispatch:function(a){a._submit_bubble&&(delete a._submit_bubble,this.parentNode&&!a.isTrigger&&f.event.simulate("submit",this.parentNode,a,!0))},teardown:function(){if(f.nodeName(this,"form"))return!1;f.event.remove(this,"._submit")}}),f.support.changeBubbles||(f.event.special.change={setup:function(){if(z.test(this.nodeName)){if(this.type==="checkbox"||this.type==="radio")f.event.add(this,"propertychange._change",function(a){a.originalEvent.propertyName==="checked"&&(this._just_changed=!0)}),f.event.add(this,"click._change",function(a){this._just_changed&&!a.isTrigger&&(this._just_changed=!1,f.event.simulate("change",this,a,!0))});return!1}f.event.add(this,"beforeactivate._change",function(a){var b=a.target;z.test(b.nodeName)&&!b._change_attached&&(f.event.add(b,"change._change",function(a){this.parentNode&&!a.isSimulated&&!a.isTrigger&&f.event.simulate("change",this.parentNode,a,!0)}),b._change_attached=!0)})},handle:function(a){var b=a.target;if(this!==b||a.isSimulated||a.isTrigger||b.type!=="radio"&&b.type!=="checkbox")return a.handleObj.handler.apply(this,arguments)},teardown:function(){f.event.remove(this,"._change");return z.test(this.nodeName)}}),f.support.focusinBubbles||f.each({focus:"focusin",blur:"focusout"},function(a,b){var d=0,e=function(a){f.event.simulate(b,a.target,f.event.fix(a),!0)};f.event.special[b]={setup:function(){d++===0&&c.addEventListener(a,e,!0)},teardown:function(){--d===0&&c.removeEventListener(a,e,!0)}}}),f.fn.extend({on:function(a,c,d,e,g){var h,i;if(typeof a=="object"){typeof c!="string"&&(d=d||c,c=b);for(i in a)this.on(i,c,d,a[i],g);return this}d==null&&e==null?(e=c,d=c=b):e==null&&(typeof c=="string"?(e=d,d=b):(e=d,d=c,c=b));if(e===!1)e=J;else if(!e)return this;g===1&&(h=e,e=function(a){f().off(a);return h.apply(this,arguments)},e.guid=h.guid||(h.guid=f.guid++));return this.each(function(){f.event.add(this,a,e,d,c)})},one:function(a,b,c,d){return this.on(a,b,c,d,1)},off:function(a,c,d){if(a&&a.preventDefault&&a.handleObj){var e=a.handleObj;f(a.delegateTarget).off(e.namespace?e.origType+"."+e.namespace:e.origType,e.selector,e.handler);return this}if(typeof a=="object"){for(var g in a)this.off(g,c,a[g]);return this}if(c===!1||typeof c=="function")d=c,c=b;d===!1&&(d=J);return this.each(function(){f.event.remove(this,a,d,c)})},bind:function(a,b,c){return this.on(a,null,b,c)},unbind:function(a,b){return this.off(a,null,b)},live:function(a,b,c){f(this.context).on(a,this.selector,b,c);return this},die:function(a,b){f(this.context).off(a,this.selector||"**",b);return this},delegate:function(a,b,c,d){return this.on(b,a,c,d)},undelegate:function(a,b,c){return arguments.length==1?this.off(a,"**"):this.off(b,a,c)},trigger:function(a,b){return this.each(function(){f.event.trigger(a,b,this)})},triggerHandler:function(a,b){if(this[0])return f.event.trigger(a,b,this[0],!0)},toggle:function(a){var b=arguments,c=a.guid||f.guid++,d=0,e=function(c){var e=(f._data(this,"lastToggle"+a.guid)||0)%d;f._data(this,"lastToggle"+a.guid,e+1),c.preventDefault();return b[e].apply(this,arguments)||!1};e.guid=c;while(d<b.length)b[d++].guid=c;return this.click(e)},hover:function(a,b){return this.mouseenter(a).mouseleave(b||a)}}),f.each("blur focus focusin focusout load resize scroll unload click dblclick mousedown mouseup mousemove mouseover mouseout mouseenter mouseleave change select submit keydown keypress keyup error contextmenu".split(" "),function(a,b){f.fn[b]=function(a,c){c==null&&(c=a,a=null);return arguments.length>0?this.on(b,null,a,c):this.trigger(b)},f.attrFn&&(f.attrFn[b]=!0),C.test(b)&&(f.event.fixHooks[b]=f.event.keyHooks),D.test(b)&&(f.event.fixHooks[b]=f.event.mouseHooks)}),function(){function x(a,b,c,e,f,g){for(var h=0,i=e.length;h<i;h++){var j=e[h];if(j){var k=!1;j=j[a];while(j){if(j[d]===c){k=e[j.sizset];break}if(j.nodeType===1){g||(j[d]=c,j.sizset=h);if(typeof b!="string"){if(j===b){k=!0;break}}else if(m.filter(b,[j]).length>0){k=j;break}}j=j[a]}e[h]=k}}}function w(a,b,c,e,f,g){for(var h=0,i=e.length;h<i;h++){var j=e[h];if(j){var k=!1;j=j[a];while(j){if(j[d]===c){k=e[j.sizset];break}j.nodeType===1&&!g&&(j[d]=c,j.sizset=h);if(j.nodeName.toLowerCase()===b){k=j;break}j=j[a]}e[h]=k}}}var a=/((?:\((?:\([^()]+\)|[^()]+)+\)|\[(?:\[[^\[\]]*\]|['"][^'"]*['"]|[^\[\]'"]+)+\]|\\.|[^ >+~,(\[\\]+)+|[>+~])(\s*,\s*)?((?:.|\r|\n)*)/g,d="sizcache"+(Math.random()+"").replace(".",""),e=0,g=Object.prototype.toString,h=!1,i=!0,j=/\\/g,k=/\r\n/g,l=/\W/;[0,0].sort(function(){i=!1;return 0});var m=function(b,d,e,f){e=e||[],d=d||c;var h=d;if(d.nodeType!==1&&d.nodeType!==9)return[];if(!b||typeof b!="string")return e;var i,j,k,l,n,q,r,t,u=!0,v=m.isXML(d),w=[],x=b;do{a.exec(""),i=a.exec(x);if(i){x=i[3],w.push(i[1]);if(i[2]){l=i[3];break}}}while(i);if(w.length>1&&p.exec(b))if(w.length===2&&o.relative[w[0]])j=y(w[0]+w[1],d,f);else{j=o.relative[w[0]]?[d]:m(w.shift(),d);while(w.length)b=w.shift(),o.relative[b]&&(b+=w.shift()),j=y(b,j,f)}else{!f&&w.length>1&&d.nodeType===9&&!v&&o.match.ID.test(w[0])&&!o.match.ID.test(w[w.length-1])&&(n=m.find(w.shift(),d,v),d=n.expr?m.filter(n.expr,n.set)[0]:n.set[0]);if(d){n=f?{expr:w.pop(),set:s(f)}:m.find(w.pop(),w.length===1&&(w[0]==="~"||w[0]==="+")&&d.parentNode?d.parentNode:d,v),j=n.expr?m.filter(n.expr,n.set):n.set,w.length>0?k=s(j):u=!1;while(w.length)q=w.pop(),r=q,o.relative[q]?r=w.pop():q="",r==null&&(r=d),o.relative[q](k,r,v)}else k=w=[]}k||(k=j),k||m.error(q||b);if(g.call(k)==="[object Array]")if(!u)e.push.apply(e,k);else if(d&&d.nodeType===1)for(t=0;k[t]!=null;t++)k[t]&&(k[t]===!0||k[t].nodeType===1&&m.contains(d,k[t]))&&e.push(j[t]);else for(t=0;k[t]!=null;t++)k[t]&&k[t].nodeType===1&&e.push(j[t]);else s(k,e);l&&(m(l,h,e,f),m.uniqueSort(e));return e};m.uniqueSort=function(a){if(u){h=i,a.sort(u);if(h)for(var b=1;b<a.length;b++)a[b]===a[b-1]&&a.splice(b--,1)}return a},m.matches=function(a,b){return m(a,null,null,b)},m.matchesSelector=function(a,b){return m(b,null,null,[a]).length>0},m.find=function(a,b,c){var d,e,f,g,h,i;if(!a)return[];for(e=0,f=o.order.length;e<f;e++){h=o.order[e];if(g=o.leftMatch[h].exec(a)){i=g[1],g.splice(1,1);if(i.substr(i.length-1)!=="\\"){g[1]=(g[1]||"").replace(j,""),d=o.find[h](g,b,c);if(d!=null){a=a.replace(o.match[h],"");break}}}}d||(d=typeof b.getElementsByTagName!="undefined"?b.getElementsByTagName("*"):[]);return{set:d,expr:a}},m.filter=function(a,c,d,e){var f,g,h,i,j,k,l,n,p,q=a,r=[],s=c,t=c&&c[0]&&m.isXML(c[0]);while(a&&c.length){for(h in o.filter)if((f=o.leftMatch[h].exec(a))!=null&&f[2]){k=o.filter[h],l=f[1],g=!1,f.splice(1,1);if(l.substr(l.length-1)==="\\")continue;s===r&&(r=[]);if(o.preFilter[h]){f=o.preFilter[h](f,s,d,r,e,t);if(!f)g=i=!0;else if(f===!0)continue}if(f)for(n=0;(j=s[n])!=null;n++)j&&(i=k(j,f,n,s),p=e^i,d&&i!=null?p?g=!0:s[n]=!1:p&&(r.push(j),g=!0));if(i!==b){d||(s=r),a=a.replace(o.match[h],"");if(!g)return[];break}}if(a===q)if(g==null)m.error(a);else break;q=a}return s},m.error=function(a){throw new Error("Syntax error, unrecognized expression: "+a)};var n=m.getText=function(a){var b,c,d=a.nodeType,e="";if(d){if(d===1||d===9||d===11){if(typeof a.textContent=="string")return a.textContent;if(typeof a.innerText=="string")return a.innerText.replace(k,"");for(a=a.firstChild;a;a=a.nextSibling)e+=n(a)}else if(d===3||d===4)return a.nodeValue}else for(b=0;c=a[b];b++)c.nodeType!==8&&(e+=n(c));return e},o=m.selectors={order:["ID","NAME","TAG"],match:{ID:/#((?:[\w\u00c0-\uFFFF\-]|\\.)+)/,CLASS:/\.((?:[\w\u00c0-\uFFFF\-]|\\.)+)/,NAME:/\[name=['"]*((?:[\w\u00c0-\uFFFF\-]|\\.)+)['"]*\]/,ATTR:/\[\s*((?:[\w\u00c0-\uFFFF\-]|\\.)+)\s*(?:(\S?=)\s*(?:(['"])(.*?)\3|(#?(?:[\w\u00c0-\uFFFF\-]|\\.)*)|)|)\s*\]/,TAG:/^((?:[\w\u00c0-\uFFFF\*\-]|\\.)+)/,CHILD:/:(only|nth|last|first)-child(?:\(\s*(even|odd|(?:[+\-]?\d+|(?:[+\-]?\d*)?n\s*(?:[+\-]\s*\d+)?))\s*\))?/,POS:/:(nth|eq|gt|lt|first|last|even|odd)(?:\((\d*)\))?(?=[^\-]|$)/,PSEUDO:/:((?:[\w\u00c0-\uFFFF\-]|\\.)+)(?:\((['"]?)((?:\([^\)]+\)|[^\(\)]*)+)\2\))?/},leftMatch:{},attrMap:{"class":"className","for":"htmlFor"},attrHandle:{href:function(a){return a.getAttribute("href")},type:function(a){return a.getAttribute("type")}},relative:{"+":function(a,b){var c=typeof b=="string",d=c&&!l.test(b),e=c&&!d;d&&(b=b.toLowerCase());for(var f=0,g=a.length,h;f<g;f++)if(h=a[f]){while((h=h.previousSibling)&&h.nodeType!==1);a[f]=e||h&&h.nodeName.toLowerCase()===b?h||!1:h===b}e&&m.filter(b,a,!0)},">":function(a,b){var c,d=typeof b=="string",e=0,f=a.length;if(d&&!l.test(b)){b=b.toLowerCase();for(;e<f;e++){c=a[e];if(c){var g=c.parentNode;a[e]=g.nodeName.toLowerCase()===b?g:!1}}}else{for(;e<f;e++)c=a[e],c&&(a[e]=d?c.parentNode:c.parentNode===b);d&&m.filter(b,a,!0)}},"":function(a,b,c){var d,f=e++,g=x;typeof b=="string"&&!l.test(b)&&(b=b.toLowerCase(),d=b,g=w),g("parentNode",b,f,a,d,c)},"~":function(a,b,c){var d,f=e++,g=x;typeof b=="string"&&!l.test(b)&&(b=b.toLowerCase(),d=b,g=w),g("previousSibling",b,f,a,d,c)}},find:{ID:function(a,b,c){if(typeof b.getElementById!="undefined"&&!c){var d=b.getElementById(a[1]);return d&&d.parentNode?[d]:[]}},NAME:function(a,b){if(typeof b.getElementsByName!="undefined"){var c=[],d=b.getElementsByName(a[1]);for(var e=0,f=d.length;e<f;e++)d[e].getAttribute("name")===a[1]&&c.push(d[e]);return c.length===0?null:c}},TAG:function(a,b){if(typeof b.getElementsByTagName!="undefined")return b.getElementsByTagName(a[1])}},preFilter:{CLASS:function(a,b,c,d,e,f){a=" "+a[1].replace(j,"")+" ";if(f)return a;for(var g=0,h;(h=b[g])!=null;g++)h&&(e^(h.className&&(" "+h.className+" ").replace(/[\t\n\r]/g," ").indexOf(a)>=0)?c||d.push(h):c&&(b[g]=!1));return!1},ID:function(a){return a[1].replace(j,"")},TAG:function(a,b){return a[1].replace(j,"").toLowerCase()},CHILD:function(a){if(a[1]==="nth"){a[2]||m.error(a[0]),a[2]=a[2].replace(/^\+|\s*/g,"");var b=/(-?)(\d*)(?:n([+\-]?\d*))?/.exec(a[2]==="even"&&"2n"||a[2]==="odd"&&"2n+1"||!/\D/.test(a[2])&&"0n+"+a[2]||a[2]);a[2]=b[1]+(b[2]||1)-0,a[3]=b[3]-0}else a[2]&&m.error(a[0]);a[0]=e++;return a},ATTR:function(a,b,c,d,e,f){var g=a[1]=a[1].replace(j,"");!f&&o.attrMap[g]&&(a[1]=o.attrMap[g]),a[4]=(a[4]||a[5]||"").replace(j,""),a[2]==="~="&&(a[4]=" "+a[4]+" ");return a},PSEUDO:function(b,c,d,e,f){if(b[1]==="not")if((a.exec(b[3])||"").length>1||/^\w/.test(b[3]))b[3]=m(b[3],null,null,c);else{var g=m.filter(b[3],c,d,!0^f);d||e.push.apply(e,g);return!1}else if(o.match.POS.test(b[0])||o.match.CHILD.test(b[0]))return!0;return b},POS:function(a){a.unshift(!0);return a}},filters:{enabled:function(a){return a.disabled===!1&&a.type!=="hidden"},disabled:function(a){return a.disabled===!0},checked:function(a){return a.checked===!0},selected:function(a){a.parentNode&&a.parentNode.selectedIndex;return a.selected===!0},parent:function(a){return!!a.firstChild},empty:function(a){return!a.firstChild},has:function(a,b,c){return!!m(c[3],a).length},header:function(a){return/h\d/i.test(a.nodeName)},text:function(a){var b=a.getAttribute("type"),c=a.type;return a.nodeName.toLowerCase()==="input"&&"text"===c&&(b===c||b===null)},radio:function(a){return a.nodeName.toLowerCase()==="input"&&"radio"===a.type},checkbox:function(a){return a.nodeName.toLowerCase()==="input"&&"checkbox"===a.type},file:function(a){return a.nodeName.toLowerCase()==="input"&&"file"===a.type},password:function(a){return a.nodeName.toLowerCase()==="input"&&"password"===a.type},submit:function(a){var b=a.nodeName.toLowerCase();return(b==="input"||b==="button")&&"submit"===a.type},image:function(a){return a.nodeName.toLowerCase()==="input"&&"image"===a.type},reset:function(a){var b=a.nodeName.toLowerCase();return(b==="input"||b==="button")&&"reset"===a.type},button:function(a){var b=a.nodeName.toLowerCase();return b==="input"&&"button"===a.type||b==="button"},input:function(a){return/input|select|textarea|button/i.test(a.nodeName)},focus:function(a){return a===a.ownerDocument.activeElement}},setFilters:{first:function(a,b){return b===0},last:function(a,b,c,d){return b===d.length-1},even:function(a,b){return b%2===0},odd:function(a,b){return b%2===1},lt:function(a,b,c){return b<c[3]-0},gt:function(a,b,c){return b>c[3]-0},nth:function(a,b,c){return c[3]-0===b},eq:function(a,b,c){return c[3]-0===b}},filter:{PSEUDO:function(a,b,c,d){var e=b[1],f=o.filters[e];if(f)return f(a,c,b,d);if(e==="contains")return(a.textContent||a.innerText||n([a])||"").indexOf(b[3])>=0;if(e==="not"){var g=b[3];for(var h=0,i=g.length;h<i;h++)if(g[h]===a)return!1;return!0}m.error(e)},CHILD:function(a,b){var c,e,f,g,h,i,j,k=b[1],l=a;switch(k){case"only":case"first":while(l=l.previousSibling)if(l.nodeType===1)return!1;if(k==="first")return!0;l=a;case"last":while(l=l.nextSibling)if(l.nodeType===1)return!1;return!0;case"nth":c=b[2],e=b[3];if(c===1&&e===0)return!0;f=b[0],g=a.parentNode;if(g&&(g[d]!==f||!a.nodeIndex)){i=0;for(l=g.firstChild;l;l=l.nextSibling)l.nodeType===1&&(l.nodeIndex=++i);g[d]=f}j=a.nodeIndex-e;return c===0?j===0:j%c===0&&j/c>=0}},ID:function(a,b){return a.nodeType===1&&a.getAttribute("id")===b},TAG:function(a,b){return b==="*"&&a.nodeType===1||!!a.nodeName&&a.nodeName.toLowerCase()===b},CLASS:function(a,b){return(" "+(a.className||a.getAttribute("class"))+" ").indexOf(b)>-1},ATTR:function(a,b){var c=b[1],d=m.attr?m.attr(a,c):o.attrHandle[c]?o.attrHandle[c](a):a[c]!=null?a[c]:a.getAttribute(c),e=d+"",f=b[2],g=b[4];return d==null?f==="!=":!f&&m.attr?d!=null:f==="="?e===g:f==="*="?e.indexOf(g)>=0:f==="~="?(" "+e+" ").indexOf(g)>=0:g?f==="!="?e!==g:f==="^="?e.indexOf(g)===0:f==="$="?e.substr(e.length-g.length)===g:f==="|="?e===g||e.substr(0,g.length+1)===g+"-":!1:e&&d!==!1},POS:function(a,b,c,d){var e=b[2],f=o.setFilters[e];if(f)return f(a,c,b,d)}}},p=o.match.POS,q=function(a,b){return"\\"+(b-0+1)};for(var r in o.match)o.match[r]=new RegExp(o.match[r].source+/(?![^\[]*\])(?![^\(]*\))/.source),o.leftMatch[r]=new RegExp(/(^(?:.|\r|\n)*?)/.source+o.match[r].source.replace(/\\(\d+)/g,q));o.match.globalPOS=p;var s=function(a,b){a=Array.prototype.slice.call(a,0);if(b){b.push.apply(b,a);return b}return a};try{Array.prototype.slice.call(c.documentElement.childNodes,0)[0].nodeType}catch(t){s=function(a,b){var c=0,d=b||[];if(g.call(a)==="[object Array]")Array.prototype.push.apply(d,a);else if(typeof a.length=="number")for(var e=a.length;c<e;c++)d.push(a[c]);else for(;a[c];c++)d.push(a[c]);return d}}var u,v;c.documentElement.compareDocumentPosition?u=function(a,b){if(a===b){h=!0;return 0}if(!a.compareDocumentPosition||!b.compareDocumentPosition)return a.compareDocumentPosition?-1:1;return a.compareDocumentPosition(b)&4?-1:1}:(u=function(a,b){if(a===b){h=!0;return 0}if(a.sourceIndex&&b.sourceIndex)return a.sourceIndex-b.sourceIndex;var c,d,e=[],f=[],g=a.parentNode,i=b.parentNode,j=g;if(g===i)return v(a,b);if(!g)return-1;if(!i)return 1;while(j)e.unshift(j),j=j.parentNode;j=i;while(j)f.unshift(j),j=j.parentNode;c=e.length,d=f.length;for(var k=0;k<c&&k<d;k++)if(e[k]!==f[k])return v(e[k],f[k]);return k===c?v(a,f[k],-1):v(e[k],b,1)},v=function(a,b,c){if(a===b)return c;var d=a.nextSibling;while(d){if(d===b)return-1;d=d.nextSibling}return 1}),function(){var a=c.createElement("div"),d="script"+(new Date).getTime(),e=c.documentElement;a.innerHTML="<a name='"+d+"'/>",e.insertBefore(a,e.firstChild),c.getElementById(d)&&(o.find.ID=function(a,c,d){if(typeof c.getElementById!="undefined"&&!d){var e=c.getElementById(a[1]);return e?e.id===a[1]||typeof e.getAttributeNode!="undefined"&&e.getAttributeNode("id").nodeValue===a[1]?[e]:b:[]}},o.filter.ID=function(a,b){var c=typeof a.getAttributeNode!="undefined"&&a.getAttributeNode("id");return a.nodeType===1&&c&&c.nodeValue===b}),e.removeChild(a),e=a=null}(),function(){var a=c.createElement("div");a.appendChild(c.createComment("")),a.getElementsByTagName("*").length>0&&(o.find.TAG=function(a,b){var c=b.getElementsByTagName(a[1]);if(a[1]==="*"){var d=[];for(var e=0;c[e];e++)c[e].nodeType===1&&d.push(c[e]);c=d}return c}),a.innerHTML="<a href='#'></a>",a.firstChild&&typeof a.firstChild.getAttribute!="undefined"&&a.firstChild.getAttribute("href")!=="#"&&(o.attrHandle.href=function(a){return a.getAttribute("href",2)}),a=null}(),c.querySelectorAll&&function(){var a=m,b=c.createElement("div"),d="__sizzle__";b.innerHTML="<p class='TEST'></p>";if(!b.querySelectorAll||b.querySelectorAll(".TEST").length!==0){m=function(b,e,f,g){e=e||c;if(!g&&!m.isXML(e)){var h=/^(\w+$)|^\.([\w\-]+$)|^#([\w\-]+$)/.exec(b);if(h&&(e.nodeType===1||e.nodeType===9)){if(h[1])return s(e.getElementsByTagName(b),f);if(h[2]&&o.find.CLASS&&e.getElementsByClassName)return s(e.getElementsByClassName(h[2]),f)}if(e.nodeType===9){if(b==="body"&&e.body)return s([e.body],f);if(h&&h[3]){var i=e.getElementById(h[3]);if(!i||!i.parentNode)return s([],f);if(i.id===h[3])return s([i],f)}try{return s(e.querySelectorAll(b),f)}catch(j){}}else if(e.nodeType===1&&e.nodeName.toLowerCase()!=="object"){var k=e,l=e.getAttribute("id"),n=l||d,p=e.parentNode,q=/^\s*[+~]/.test(b);l?n=n.replace(/'/g,"\\$&"):e.setAttribute("id",n),q&&p&&(e=e.parentNode);try{if(!q||p)return s(e.querySelectorAll("[id='"+n+"'] "+b),f)}catch(r){}finally{l||k.removeAttribute("id")}}}return a(b,e,f,g)};for(var e in a)m[e]=a[e];b=null}}(),function(){var a=c.documentElement,b=a.matchesSelector||a.mozMatchesSelector||a.webkitMatchesSelector||a.msMatchesSelector;if(b){var d=!b.call(c.createElement("div"),"div"),e=!1;try{b.call(c.documentElement,"[test!='']:sizzle")}catch(f){e=!0}m.matchesSelector=function(a,c){c=c.replace(/\=\s*([^'"\]]*)\s*\]/g,"='$1']");if(!m.isXML(a))try{if(e||!o.match.PSEUDO.test(c)&&!/!=/.test(c)){var f=b.call(a,c);if(f||!d||a.document&&a.document.nodeType!==11)return f}}catch(g){}return m(c,null,null,[a]).length>0}}}(),function(){var a=c.createElement("div");a.innerHTML="<div class='test e'></div><div class='test'></div>";if(!!a.getElementsByClassName&&a.getElementsByClassName("e").length!==0){a.lastChild.className="e";if(a.getElementsByClassName("e").length===1)return;o.order.splice(1,0,"CLASS"),o.find.CLASS=function(a,b,c){if(typeof b.getElementsByClassName!="undefined"&&!c)return b.getElementsByClassName(a[1])},a=null}}(),c.documentElement.contains?m.contains=function(a,b){return a!==b&&(a.contains?a.contains(b):!0)}:c.documentElement.compareDocumentPosition?m.contains=function(a,b){return!!(a.compareDocumentPosition(b)&16)}:m.contains=function(){return!1},m.isXML=function(a){var b=(a?a.ownerDocument||a:0).documentElement;return b?b.nodeName!=="HTML":!1};var y=function(a,b,c){var d,e=[],f="",g=b.nodeType?[b]:b;while(d=o.match.PSEUDO.exec(a))f+=d[0],a=a.replace(o.match.PSEUDO,"");a=o.relative[a]?a+"*":a;for(var h=0,i=g.length;h<i;h++)m(a,g[h],e,c);return m.filter(f,e)};m.attr=f.attr,m.selectors.attrMap={},f.find=m,f.expr=m.selectors,f.expr[":"]=f.expr.filters,f.unique=m.uniqueSort,f.text=m.getText,f.isXMLDoc=m.isXML,f.contains=m.contains}();var L=/Until$/,M=/^(?:parents|prevUntil|prevAll)/,N=/,/,O=/^.[^:#\[\.,]*$/,P=Array.prototype.slice,Q=f.expr.match.globalPOS,R={children:!0,contents:!0,next:!0,prev:!0};f.fn.extend({find:function(a){var b=this,c,d;if(typeof a!="string")return f(a).filter(function(){for(c=0,d=b.length;c<d;c++)if(f.contains(b[c],this))return!0});var e=this.pushStack("","find",a),g,h,i;for(c=0,d=this.length;c<d;c++){g=e.length,f.find(a,this[c],e);if(c>0)for(h=g;h<e.length;h++)for(i=0;i<g;i++)if(e[i]===e[h]){e.splice(h--,1);break}}return e},has:function(a){var b=f(a);return this.filter(function(){for(var a=0,c=b.length;a<c;a++)if(f.contains(this,b[a]))return!0})},not:function(a){return this.pushStack(T(this,a,!1),"not",a)},filter:function(a){return this.pushStack(T(this,a,!0),"filter",a)},is:function(a){return!!a&&(typeof a=="string"?Q.test(a)?f(a,this.context).index(this[0])>=0:f.filter(a,this).length>0:this.filter(a).length>0)},closest:function(a,b){var c=[],d,e,g=this[0];if(f.isArray(a)){var h=1;while(g&&g.ownerDocument&&g!==b){for(d=0;d<a.length;d++)f(g).is(a[d])&&c.push({selector:a[d],elem:g,level:h});g=g.parentNode,h++}return c}var i=Q.test(a)||typeof a!="string"?f(a,b||this.context):0;for(d=0,e=this.length;d<e;d++){g=this[d];while(g){if(i?i.index(g)>-1:f.find.matchesSelector(g,a)){c.push(g);break}g=g.parentNode;if(!g||!g.ownerDocument||g===b||g.nodeType===11)break}}c=c.length>1?f.unique(c):c;return this.pushStack(c,"closest",a)},index:function(a){if(!a)return this[0]&&this[0].parentNode?this.prevAll().length:-1;if(typeof a=="string")return f.inArray(this[0],f(a));return f.inArray(a.jquery?a[0]:a,this)},add:function(a,b){var c=typeof a=="string"?f(a,b):f.makeArray(a&&a.nodeType?[a]:a),d=f.merge(this.get(),c);return this.pushStack(S(c[0])||S(d[0])?d:f.unique(d))},andSelf:function(){return this.add(this.prevObject)}}),f.each({parent:function(a){var b=a.parentNode;return b&&b.nodeType!==11?b:null},parents:function(a){return f.dir(a,"parentNode")},parentsUntil:function(a,b,c){return f.dir(a,"parentNode",c)},next:function(a){return f.nth(a,2,"nextSibling")},prev:function(a){return f.nth(a,2,"previousSibling")},nextAll:function(a){return f.dir(a,"nextSibling")},prevAll:function(a){return f.dir(a,"previousSibling")},nextUntil:function(a,b,c){return f.dir(a,"nextSibling",c)},prevUntil:function(a,b,c){return f.dir(a,"previousSibling",c)},siblings:function(a){return f.sibling((a.parentNode||{}).firstChild,a)},children:function(a){return f.sibling(a.firstChild)},contents:function(a){return f.nodeName(a,"iframe")?a.contentDocument||a.contentWindow.document:f.makeArray(a.childNodes)}},function(a,b){f.fn[a]=function(c,d){var e=f.map(this,b,c);L.test(a)||(d=c),d&&typeof d=="string"&&(e=f.filter(d,e)),e=this.length>1&&!R[a]?f.unique(e):e,(this.length>1||N.test(d))&&M.test(a)&&(e=e.reverse());return this.pushStack(e,a,P.call(arguments).join(","))}}),f.extend({filter:function(a,b,c){c&&(a=":not("+a+")");return b.length===1?f.find.matchesSelector(b[0],a)?[b[0]]:[]:f.find.matches(a,b)},dir:function(a,c,d){var e=[],g=a[c];while(g&&g.nodeType!==9&&(d===b||g.nodeType!==1||!f(g).is(d)))g.nodeType===1&&e.push(g),g=g[c];return e},nth:function(a,b,c,d){b=b||1;var e=0;for(;a;a=a[c])if(a.nodeType===1&&++e===b)break;return a},sibling:function(a,b){var c=[];for(;a;a=a.nextSibling)a.nodeType===1&&a!==b&&c.push(a);return c}});var V="abbr|article|aside|audio|bdi|canvas|data|datalist|details|figcaption|figure|footer|header|hgroup|mark|meter|nav|output|progress|section|summary|time|video",W=/ jQuery\d+="(?:\d+|null)"/g,X=/^\s+/,Y=/<(?!area|br|col|embed|hr|img|input|link|meta|param)(([\w:]+)[^>]*)\/>/ig,Z=/<([\w:]+)/,$=/<tbody/i,_=/<|&#?\w+;/,ba=/<(?:script|style)/i,bb=/<(?:script|object|embed|option|style)/i,bc=new RegExp("<(?:"+V+")[\\s/>]","i"),bd=/checked\s*(?:[^=]|=\s*.checked.)/i,be=/\/(java|ecma)script/i,bf=/^\s*<!(?:\[CDATA\[|\-\-)/,bg={option:[1,"<select multiple='multiple'>","</select>"],legend:[1,"<fieldset>","</fieldset>"],thead:[1,"<table>","</table>"],tr:[2,"<table><tbody>","</tbody></table>"],td:[3,"<table><tbody><tr>","</tr></tbody></table>"],col:[2,"<table><tbody></tbody><colgroup>","</colgroup></table>"],area:[1,"<map>","</map>"],_default:[0,"",""]},bh=U(c);bg.optgroup=bg.option,bg.tbody=bg.tfoot=bg.colgroup=bg.caption=bg.thead,bg.th=bg.td,f.support.htmlSerialize||(bg._default=[1,"div<div>","</div>"]),f.fn.extend({text:function(a){return f.access(this,function(a){return a===b?f.text(this):this.empty().append((this[0]&&this[0].ownerDocument||c).createTextNode(a))},null,a,arguments.length)},wrapAll:function(a){if(f.isFunction(a))return this.each(function(b){f(this).wrapAll(a.call(this,b))});if(this[0]){var b=f(a,this[0].ownerDocument).eq(0).clone(!0);this[0].parentNode&&b.insertBefore(this[0]),b.map(function(){var a=this;while(a.firstChild&&a.firstChild.nodeType===1)a=a.firstChild;return a}).append(this)}return this},wrapInner:function(a){if(f.isFunction(a))return this.each(function(b){f(this).wrapInner(a.call(this,b))});return this.each(function(){var b=f(this),c=b.contents();c.length?c.wrapAll(a):b.append(a)})},wrap:function(a){var b=f.isFunction(a);return this.each(function(c){f(this).wrapAll(b?a.call(this,c):a)})},unwrap:function(){return this.parent().each(function(){f.nodeName(this,"body")||f(this).replaceWith(this.childNodes)}).end()},append:function(){return this.domManip(arguments,!0,function(a){this.nodeType===1&&this.appendChild(a)})},prepend:function(){return this.domManip(arguments,!0,function(a){this.nodeType===1&&this.insertBefore(a,this.firstChild)})},before:function(){if(this[0]&&this[0].parentNode)return this.domManip(arguments,!1,function(a){this.parentNode.insertBefore(a,this)});if(arguments.length){var a=f
                    .clean(arguments);a.push.apply(a,this.toArray());return this.pushStack(a,"before",arguments)}},after:function(){if(this[0]&&this[0].parentNode)return this.domManip(arguments,!1,function(a){this.parentNode.insertBefore(a,this.nextSibling)});if(arguments.length){var a=this.pushStack(this,"after",arguments);a.push.apply(a,f.clean(arguments));return a}},remove:function(a,b){for(var c=0,d;(d=this[c])!=null;c++)if(!a||f.filter(a,[d]).length)!b&&d.nodeType===1&&(f.cleanData(d.getElementsByTagName("*")),f.cleanData([d])),d.parentNode&&d.parentNode.removeChild(d);return this},empty:function(){for(var a=0,b;(b=this[a])!=null;a++){b.nodeType===1&&f.cleanData(b.getElementsByTagName("*"));while(b.firstChild)b.removeChild(b.firstChild)}return this},clone:function(a,b){a=a==null?!1:a,b=b==null?a:b;return this.map(function(){return f.clone(this,a,b)})},html:function(a){return f.access(this,function(a){var c=this[0]||{},d=0,e=this.length;if(a===b)return c.nodeType===1?c.innerHTML.replace(W,""):null;if(typeof a=="string"&&!ba.test(a)&&(f.support.leadingWhitespace||!X.test(a))&&!bg[(Z.exec(a)||["",""])[1].toLowerCase()]){a=a.replace(Y,"<$1></$2>");try{for(;d<e;d++)c=this[d]||{},c.nodeType===1&&(f.cleanData(c.getElementsByTagName("*")),c.innerHTML=a);c=0}catch(g){}}c&&this.empty().append(a)},null,a,arguments.length)},replaceWith:function(a){if(this[0]&&this[0].parentNode){if(f.isFunction(a))return this.each(function(b){var c=f(this),d=c.html();c.replaceWith(a.call(this,b,d))});typeof a!="string"&&(a=f(a).detach());return this.each(function(){var b=this.nextSibling,c=this.parentNode;f(this).remove(),b?f(b).before(a):f(c).append(a)})}return this.length?this.pushStack(f(f.isFunction(a)?a():a),"replaceWith",a):this},detach:function(a){return this.remove(a,!0)},domManip:function(a,c,d){var e,g,h,i,j=a[0],k=[];if(!f.support.checkClone&&arguments.length===3&&typeof j=="string"&&bd.test(j))return this.each(function(){f(this).domManip(a,c,d,!0)});if(f.isFunction(j))return this.each(function(e){var g=f(this);a[0]=j.call(this,e,c?g.html():b),g.domManip(a,c,d)});if(this[0]){i=j&&j.parentNode,f.support.parentNode&&i&&i.nodeType===11&&i.childNodes.length===this.length?e={fragment:i}:e=f.buildFragment(a,this,k),h=e.fragment,h.childNodes.length===1?g=h=h.firstChild:g=h.firstChild;if(g){c=c&&f.nodeName(g,"tr");for(var l=0,m=this.length,n=m-1;l<m;l++)d.call(c?bi(this[l],g):this[l],e.cacheable||m>1&&l<n?f.clone(h,!0,!0):h)}k.length&&f.each(k,function(a,b){b.src?f.ajax({type:"GET",global:!1,url:b.src,async:!1,dataType:"script"}):f.globalEval((b.text||b.textContent||b.innerHTML||"").replace(bf,"/*$0*/")),b.parentNode&&b.parentNode.removeChild(b)})}return this}}),f.buildFragment=function(a,b,d){var e,g,h,i,j=a[0];b&&b[0]&&(i=b[0].ownerDocument||b[0]),i.createDocumentFragment||(i=c),a.length===1&&typeof j=="string"&&j.length<512&&i===c&&j.charAt(0)==="<"&&!bb.test(j)&&(f.support.checkClone||!bd.test(j))&&(f.support.html5Clone||!bc.test(j))&&(g=!0,h=f.fragments[j],h&&h!==1&&(e=h)),e||(e=i.createDocumentFragment(),f.clean(a,i,e,d)),g&&(f.fragments[j]=h?e:1);return{fragment:e,cacheable:g}},f.fragments={},f.each({appendTo:"append",prependTo:"prepend",insertBefore:"before",insertAfter:"after",replaceAll:"replaceWith"},function(a,b){f.fn[a]=function(c){var d=[],e=f(c),g=this.length===1&&this[0].parentNode;if(g&&g.nodeType===11&&g.childNodes.length===1&&e.length===1){e[b](this[0]);return this}for(var h=0,i=e.length;h<i;h++){var j=(h>0?this.clone(!0):this).get();f(e[h])[b](j),d=d.concat(j)}return this.pushStack(d,a,e.selector)}}),f.extend({clone:function(a,b,c){var d,e,g,h=f.support.html5Clone||f.isXMLDoc(a)||!bc.test("<"+a.nodeName+">")?a.cloneNode(!0):bo(a);if((!f.support.noCloneEvent||!f.support.noCloneChecked)&&(a.nodeType===1||a.nodeType===11)&&!f.isXMLDoc(a)){bk(a,h),d=bl(a),e=bl(h);for(g=0;d[g];++g)e[g]&&bk(d[g],e[g])}if(b){bj(a,h);if(c){d=bl(a),e=bl(h);for(g=0;d[g];++g)bj(d[g],e[g])}}d=e=null;return h},clean:function(a,b,d,e){var g,h,i,j=[];b=b||c,typeof b.createElement=="undefined"&&(b=b.ownerDocument||b[0]&&b[0].ownerDocument||c);for(var k=0,l;(l=a[k])!=null;k++){typeof l=="number"&&(l+="");if(!l)continue;if(typeof l=="string")if(!_.test(l))l=b.createTextNode(l);else{l=l.replace(Y,"<$1></$2>");var m=(Z.exec(l)||["",""])[1].toLowerCase(),n=bg[m]||bg._default,o=n[0],p=b.createElement("div"),q=bh.childNodes,r;b===c?bh.appendChild(p):U(b).appendChild(p),p.innerHTML=n[1]+l+n[2];while(o--)p=p.lastChild;if(!f.support.tbody){var s=$.test(l),t=m==="table"&&!s?p.firstChild&&p.firstChild.childNodes:n[1]==="<table>"&&!s?p.childNodes:[];for(i=t.length-1;i>=0;--i)f.nodeName(t[i],"tbody")&&!t[i].childNodes.length&&t[i].parentNode.removeChild(t[i])}!f.support.leadingWhitespace&&X.test(l)&&p.insertBefore(b.createTextNode(X.exec(l)[0]),p.firstChild),l=p.childNodes,p&&(p.parentNode.removeChild(p),q.length>0&&(r=q[q.length-1],r&&r.parentNode&&r.parentNode.removeChild(r)))}var u;if(!f.support.appendChecked)if(l[0]&&typeof (u=l.length)=="number")for(i=0;i<u;i++)bn(l[i]);else bn(l);l.nodeType?j.push(l):j=f.merge(j,l)}if(d){g=function(a){return!a.type||be.test(a.type)};for(k=0;j[k];k++){h=j[k];if(e&&f.nodeName(h,"script")&&(!h.type||be.test(h.type)))e.push(h.parentNode?h.parentNode.removeChild(h):h);else{if(h.nodeType===1){var v=f.grep(h.getElementsByTagName("script"),g);j.splice.apply(j,[k+1,0].concat(v))}d.appendChild(h)}}}return j},cleanData:function(a){var b,c,d=f.cache,e=f.event.special,g=f.support.deleteExpando;for(var h=0,i;(i=a[h])!=null;h++){if(i.nodeName&&f.noData[i.nodeName.toLowerCase()])continue;c=i[f.expando];if(c){b=d[c];if(b&&b.events){for(var j in b.events)e[j]?f.event.remove(i,j):f.removeEvent(i,j,b.handle);b.handle&&(b.handle.elem=null)}g?delete i[f.expando]:i.removeAttribute&&i.removeAttribute(f.expando),delete d[c]}}}});var bp=/alpha\([^)]*\)/i,bq=/opacity=([^)]*)/,br=/([A-Z]|^ms)/g,bs=/^[\-+]?(?:\d*\.)?\d+$/i,bt=/^-?(?:\d*\.)?\d+(?!px)[^\d\s]+$/i,bu=/^([\-+])=([\-+.\de]+)/,bv=/^margin/,bw={position:"absolute",visibility:"hidden",display:"block"},bx=["Top","Right","Bottom","Left"],by,bz,bA;f.fn.css=function(a,c){return f.access(this,function(a,c,d){return d!==b?f.style(a,c,d):f.css(a,c)},a,c,arguments.length>1)},f.extend({cssHooks:{opacity:{get:function(a,b){if(b){var c=by(a,"opacity");return c===""?"1":c}return a.style.opacity}}},cssNumber:{fillOpacity:!0,fontWeight:!0,lineHeight:!0,opacity:!0,orphans:!0,widows:!0,zIndex:!0,zoom:!0},cssProps:{"float":f.support.cssFloat?"cssFloat":"styleFloat"},style:function(a,c,d,e){if(!!a&&a.nodeType!==3&&a.nodeType!==8&&!!a.style){var g,h,i=f.camelCase(c),j=a.style,k=f.cssHooks[i];c=f.cssProps[i]||i;if(d===b){if(k&&"get"in k&&(g=k.get(a,!1,e))!==b)return g;return j[c]}h=typeof d,h==="string"&&(g=bu.exec(d))&&(d=+(g[1]+1)*+g[2]+parseFloat(f.css(a,c)),h="number");if(d==null||h==="number"&&isNaN(d))return;h==="number"&&!f.cssNumber[i]&&(d+="px");if(!k||!("set"in k)||(d=k.set(a,d))!==b)try{j[c]=d}catch(l){}}},css:function(a,c,d){var e,g;c=f.camelCase(c),g=f.cssHooks[c],c=f.cssProps[c]||c,c==="cssFloat"&&(c="float");if(g&&"get"in g&&(e=g.get(a,!0,d))!==b)return e;if(by)return by(a,c)},swap:function(a,b,c){var d={},e,f;for(f in b)d[f]=a.style[f],a.style[f]=b[f];e=c.call(a);for(f in b)a.style[f]=d[f];return e}}),f.curCSS=f.css,c.defaultView&&c.defaultView.getComputedStyle&&(bz=function(a,b){var c,d,e,g,h=a.style;b=b.replace(br,"-$1").toLowerCase(),(d=a.ownerDocument.defaultView)&&(e=d.getComputedStyle(a,null))&&(c=e.getPropertyValue(b),c===""&&!f.contains(a.ownerDocument.documentElement,a)&&(c=f.style(a,b))),!f.support.pixelMargin&&e&&bv.test(b)&&bt.test(c)&&(g=h.width,h.width=c,c=e.width,h.width=g);return c}),c.documentElement.currentStyle&&(bA=function(a,b){var c,d,e,f=a.currentStyle&&a.currentStyle[b],g=a.style;f==null&&g&&(e=g[b])&&(f=e),bt.test(f)&&(c=g.left,d=a.runtimeStyle&&a.runtimeStyle.left,d&&(a.runtimeStyle.left=a.currentStyle.left),g.left=b==="fontSize"?"1em":f,f=g.pixelLeft+"px",g.left=c,d&&(a.runtimeStyle.left=d));return f===""?"auto":f}),by=bz||bA,f.each(["height","width"],function(a,b){f.cssHooks[b]={get:function(a,c,d){if(c)return a.offsetWidth!==0?bB(a,b,d):f.swap(a,bw,function(){return bB(a,b,d)})},set:function(a,b){return bs.test(b)?b+"px":b}}}),f.support.opacity||(f.cssHooks.opacity={get:function(a,b){return bq.test((b&&a.currentStyle?a.currentStyle.filter:a.style.filter)||"")?parseFloat(RegExp.$1)/100+"":b?"1":""},set:function(a,b){var c=a.style,d=a.currentStyle,e=f.isNumeric(b)?"alpha(opacity="+b*100+")":"",g=d&&d.filter||c.filter||"";c.zoom=1;if(b>=1&&f.trim(g.replace(bp,""))===""){c.removeAttribute("filter");if(d&&!d.filter)return}c.filter=bp.test(g)?g.replace(bp,e):g+" "+e}}),f(function(){f.support.reliableMarginRight||(f.cssHooks.marginRight={get:function(a,b){return f.swap(a,{display:"inline-block"},function(){return b?by(a,"margin-right"):a.style.marginRight})}})}),f.expr&&f.expr.filters&&(f.expr.filters.hidden=function(a){var b=a.offsetWidth,c=a.offsetHeight;return b===0&&c===0||!f.support.reliableHiddenOffsets&&(a.style&&a.style.display||f.css(a,"display"))==="none"},f.expr.filters.visible=function(a){return!f.expr.filters.hidden(a)}),f.each({margin:"",padding:"",border:"Width"},function(a,b){f.cssHooks[a+b]={expand:function(c){var d,e=typeof c=="string"?c.split(" "):[c],f={};for(d=0;d<4;d++)f[a+bx[d]+b]=e[d]||e[d-2]||e[0];return f}}});var bC=/%20/g,bD=/\[\]$/,bE=/\r?\n/g,bF=/#.*$/,bG=/^(.*?):[ \t]*([^\r\n]*)\r?$/mg,bH=/^(?:color|date|datetime|datetime-local|email|hidden|month|number|password|range|search|tel|text|time|url|week)$/i,bI=/^(?:about|app|app\-storage|.+\-extension|file|res|widget):$/,bJ=/^(?:GET|HEAD)$/,bK=/^\/\//,bL=/\?/,bM=/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi,bN=/^(?:select|textarea)/i,bO=/\s+/,bP=/([?&])_=[^&]*/,bQ=/^([\w\+\.\-]+:)(?:\/\/([^\/?#:]*)(?::(\d+))?)?/,bR=f.fn.load,bS={},bT={},bU,bV,bW=["*/"]+["*"];try{bU=e.href}catch(bX){bU=c.createElement("a"),bU.href="",bU=bU.href}bV=bQ.exec(bU.toLowerCase())||[],f.fn.extend({load:function(a,c,d){if(typeof a!="string"&&bR)return bR.apply(this,arguments);if(!this.length)return this;var e=a.indexOf(" ");if(e>=0){var g=a.slice(e,a.length);a=a.slice(0,e)}var h="GET";c&&(f.isFunction(c)?(d=c,c=b):typeof c=="object"&&(c=f.param(c,f.ajaxSettings.traditional),h="POST"));var i=this;f.ajax({url:a,type:h,dataType:"html",data:c,complete:function(a,b,c){c=a.responseText,a.isResolved()&&(a.done(function(a){c=a}),i.html(g?f("<div>").append(c.replace(bM,"")).find(g):c)),d&&i.each(d,[c,b,a])}});return this},serialize:function(){return f.param(this.serializeArray())},serializeArray:function(){return this.map(function(){return this.elements?f.makeArray(this.elements):this}).filter(function(){return this.name&&!this.disabled&&(this.checked||bN.test(this.nodeName)||bH.test(this.type))}).map(function(a,b){var c=f(this).val();return c==null?null:f.isArray(c)?f.map(c,function(a,c){return{name:b.name,value:a.replace(bE,"\r\n")}}):{name:b.name,value:c.replace(bE,"\r\n")}}).get()}}),f.each("ajaxStart ajaxStop ajaxComplete ajaxError ajaxSuccess ajaxSend".split(" "),function(a,b){f.fn[b]=function(a){return this.on(b,a)}}),f.each(["get","post"],function(a,c){f[c]=function(a,d,e,g){f.isFunction(d)&&(g=g||e,e=d,d=b);return f.ajax({type:c,url:a,data:d,success:e,dataType:g})}}),f.extend({getScript:function(a,c){return f.get(a,b,c,"script")},getJSON:function(a,b,c){return f.get(a,b,c,"json")},ajaxSetup:function(a,b){b?b$(a,f.ajaxSettings):(b=a,a=f.ajaxSettings),b$(a,b);return a},ajaxSettings:{url:bU,isLocal:bI.test(bV[1]),global:!0,type:"GET",contentType:"application/x-www-form-urlencoded; charset=UTF-8",processData:!0,async:!0,accepts:{xml:"application/xml, text/xml",html:"text/html",text:"text/plain",json:"application/json, text/javascript","*":bW},contents:{xml:/xml/,html:/html/,json:/json/},responseFields:{xml:"responseXML",text:"responseText"},converters:{"* text":a.String,"text html":!0,"text json":f.parseJSON,"text xml":f.parseXML},flatOptions:{context:!0,url:!0}},ajaxPrefilter:bY(bS),ajaxTransport:bY(bT),ajax:function(a,c){function w(a,c,l,m){if(s!==2){s=2,q&&clearTimeout(q),p=b,n=m||"",v.readyState=a>0?4:0;var o,r,u,w=c,x=l?ca(d,v,l):b,y,z;if(a>=200&&a<300||a===304){if(d.ifModified){if(y=v.getResponseHeader("Last-Modified"))f.lastModified[k]=y;if(z=v.getResponseHeader("Etag"))f.etag[k]=z}if(a===304)w="notmodified",o=!0;else try{r=cb(d,x),w="success",o=!0}catch(A){w="parsererror",u=A}}else{u=w;if(!w||a)w="error",a<0&&(a=0)}v.status=a,v.statusText=""+(c||w),o?h.resolveWith(e,[r,w,v]):h.rejectWith(e,[v,w,u]),v.statusCode(j),j=b,t&&g.trigger("ajax"+(o?"Success":"Error"),[v,d,o?r:u]),i.fireWith(e,[v,w]),t&&(g.trigger("ajaxComplete",[v,d]),--f.active||f.event.trigger("ajaxStop"))}}typeof a=="object"&&(c=a,a=b),c=c||{};var d=f.ajaxSetup({},c),e=d.context||d,g=e!==d&&(e.nodeType||e instanceof f)?f(e):f.event,h=f.Deferred(),i=f.Callbacks("once memory"),j=d.statusCode||{},k,l={},m={},n,o,p,q,r,s=0,t,u,v={readyState:0,setRequestHeader:function(a,b){if(!s){var c=a.toLowerCase();a=m[c]=m[c]||a,l[a]=b}return this},getAllResponseHeaders:function(){return s===2?n:null},getResponseHeader:function(a){var c;if(s===2){if(!o){o={};while(c=bG.exec(n))o[c[1].toLowerCase()]=c[2]}c=o[a.toLowerCase()]}return c===b?null:c},overrideMimeType:function(a){s||(d.mimeType=a);return this},abort:function(a){a=a||"abort",p&&p.abort(a),w(0,a);return this}};h.promise(v),v.success=v.done,v.error=v.fail,v.complete=i.add,v.statusCode=function(a){if(a){var b;if(s<2)for(b in a)j[b]=[j[b],a[b]];else b=a[v.status],v.then(b,b)}return this},d.url=((a||d.url)+"").replace(bF,"").replace(bK,bV[1]+"//"),d.dataTypes=f.trim(d.dataType||"*").toLowerCase().split(bO),d.crossDomain==null&&(r=bQ.exec(d.url.toLowerCase()),d.crossDomain=!(!r||r[1]==bV[1]&&r[2]==bV[2]&&(r[3]||(r[1]==="http:"?80:443))==(bV[3]||(bV[1]==="http:"?80:443)))),d.data&&d.processData&&typeof d.data!="string"&&(d.data=f.param(d.data,d.traditional)),bZ(bS,d,c,v);if(s===2)return!1;t=d.global,d.type=d.type.toUpperCase(),d.hasContent=!bJ.test(d.type),t&&f.active++===0&&f.event.trigger("ajaxStart");if(!d.hasContent){d.data&&(d.url+=(bL.test(d.url)?"&":"?")+d.data,delete d.data),k=d.url;if(d.cache===!1){var x=f.now(),y=d.url.replace(bP,"$1_="+x);d.url=y+(y===d.url?(bL.test(d.url)?"&":"?")+"_="+x:"")}}(d.data&&d.hasContent&&d.contentType!==!1||c.contentType)&&v.setRequestHeader("Content-Type",d.contentType),d.ifModified&&(k=k||d.url,f.lastModified[k]&&v.setRequestHeader("If-Modified-Since",f.lastModified[k]),f.etag[k]&&v.setRequestHeader("If-None-Match",f.etag[k])),v.setRequestHeader("Accept",d.dataTypes[0]&&d.accepts[d.dataTypes[0]]?d.accepts[d.dataTypes[0]]+(d.dataTypes[0]!=="*"?", "+bW+"; q=0.01":""):d.accepts["*"]);for(u in d.headers)v.setRequestHeader(u,d.headers[u]);if(d.beforeSend&&(d.beforeSend.call(e,v,d)===!1||s===2)){v.abort();return!1}for(u in{success:1,error:1,complete:1})v[u](d[u]);p=bZ(bT,d,c,v);if(!p)w(-1,"No Transport");else{v.readyState=1,t&&g.trigger("ajaxSend",[v,d]),d.async&&d.timeout>0&&(q=setTimeout(function(){v.abort("timeout")},d.timeout));try{s=1,p.send(l,w)}catch(z){if(s<2)w(-1,z);else throw z}}return v},param:function(a,c){var d=[],e=function(a,b){b=f.isFunction(b)?b():b,d[d.length]=encodeURIComponent(a)+"="+encodeURIComponent(b)};c===b&&(c=f.ajaxSettings.traditional);if(f.isArray(a)||a.jquery&&!f.isPlainObject(a))f.each(a,function(){e(this.name,this.value)});else for(var g in a)b_(g,a[g],c,e);return d.join("&").replace(bC,"+")}}),f.extend({active:0,lastModified:{},etag:{}});var cc=f.now(),cd=/(\=)\?(&|$)|\?\?/i;f.ajaxSetup({jsonp:"callback",jsonpCallback:function(){return f.expando+"_"+cc++}}),f.ajaxPrefilter("json jsonp",function(b,c,d){var e=typeof b.data=="string"&&/^application\/x\-www\-form\-urlencoded/.test(b.contentType);if(b.dataTypes[0]==="jsonp"||b.jsonp!==!1&&(cd.test(b.url)||e&&cd.test(b.data))){var g,h=b.jsonpCallback=f.isFunction(b.jsonpCallback)?b.jsonpCallback():b.jsonpCallback,i=a[h],j=b.url,k=b.data,l="$1"+h+"$2";b.jsonp!==!1&&(j=j.replace(cd,l),b.url===j&&(e&&(k=k.replace(cd,l)),b.data===k&&(j+=(/\?/.test(j)?"&":"?")+b.jsonp+"="+h))),b.url=j,b.data=k,a[h]=function(a){g=[a]},d.always(function(){a[h]=i,g&&f.isFunction(i)&&a[h](g[0])}),b.converters["script json"]=function(){g||f.error(h+" was not called");return g[0]},b.dataTypes[0]="json";return"script"}}),f.ajaxSetup({accepts:{script:"text/javascript, application/javascript, application/ecmascript, application/x-ecmascript"},contents:{script:/javascript|ecmascript/},converters:{"text script":function(a){f.globalEval(a);return a}}}),f.ajaxPrefilter("script",function(a){a.cache===b&&(a.cache=!1),a.crossDomain&&(a.type="GET",a.global=!1)}),f.ajaxTransport("script",function(a){if(a.crossDomain){var d,e=c.head||c.getElementsByTagName("head")[0]||c.documentElement;return{send:function(f,g){d=c.createElement("script"),d.async="async",a.scriptCharset&&(d.charset=a.scriptCharset),d.src=a.url,d.onload=d.onreadystatechange=function(a,c){if(c||!d.readyState||/loaded|complete/.test(d.readyState))d.onload=d.onreadystatechange=null,e&&d.parentNode&&e.removeChild(d),d=b,c||g(200,"success")},e.insertBefore(d,e.firstChild)},abort:function(){d&&d.onload(0,1)}}}});var ce=a.ActiveXObject?function(){for(var a in cg)cg[a](0,1)}:!1,cf=0,cg;f.ajaxSettings.xhr=a.ActiveXObject?function(){return!this.isLocal&&ch()||ci()}:ch,function(a){f.extend(f.support,{ajax:!!a,cors:!!a&&"withCredentials"in a})}(f.ajaxSettings.xhr()),f.support.ajax&&f.ajaxTransport(function(c){if(!c.crossDomain||f.support.cors){var d;return{send:function(e,g){var h=c.xhr(),i,j;c.username?h.open(c.type,c.url,c.async,c.username,c.password):h.open(c.type,c.url,c.async);if(c.xhrFields)for(j in c.xhrFields)h[j]=c.xhrFields[j];c.mimeType&&h.overrideMimeType&&h.overrideMimeType(c.mimeType),!c.crossDomain&&!e["X-Requested-With"]&&(e["X-Requested-With"]="XMLHttpRequest");try{for(j in e)h.setRequestHeader(j,e[j])}catch(k){}h.send(c.hasContent&&c.data||null),d=function(a,e){var j,k,l,m,n;try{if(d&&(e||h.readyState===4)){d=b,i&&(h.onreadystatechange=f.noop,ce&&delete cg[i]);if(e)h.readyState!==4&&h.abort();else{j=h.status,l=h.getAllResponseHeaders(),m={},n=h.responseXML,n&&n.documentElement&&(m.xml=n);try{m.text=h.responseText}catch(a){}try{k=h.statusText}catch(o){k=""}!j&&c.isLocal&&!c.crossDomain?j=m.text?200:404:j===1223&&(j=204)}}}catch(p){e||g(-1,p)}m&&g(j,k,m,l)},!c.async||h.readyState===4?d():(i=++cf,ce&&(cg||(cg={},f(a).unload(ce)),cg[i]=d),h.onreadystatechange=d)},abort:function(){d&&d(0,1)}}}});var cj={},ck,cl,cm=/^(?:toggle|show|hide)$/,cn=/^([+\-]=)?([\d+.\-]+)([a-z%]*)$/i,co,cp=[["height","marginTop","marginBottom","paddingTop","paddingBottom"],["width","marginLeft","marginRight","paddingLeft","paddingRight"],["opacity"]],cq;f.fn.extend({show:function(a,b,c){var d,e;if(a||a===0)return this.animate(ct("show",3),a,b,c);for(var g=0,h=this.length;g<h;g++)d=this[g],d.style&&(e=d.style.display,!f._data(d,"olddisplay")&&e==="none"&&(e=d.style.display=""),(e===""&&f.css(d,"display")==="none"||!f.contains(d.ownerDocument.documentElement,d))&&f._data(d,"olddisplay",cu(d.nodeName)));for(g=0;g<h;g++){d=this[g];if(d.style){e=d.style.display;if(e===""||e==="none")d.style.display=f._data(d,"olddisplay")||""}}return this},hide:function(a,b,c){if(a||a===0)return this.animate(ct("hide",3),a,b,c);var d,e,g=0,h=this.length;for(;g<h;g++)d=this[g],d.style&&(e=f.css(d,"display"),e!=="none"&&!f._data(d,"olddisplay")&&f._data(d,"olddisplay",e));for(g=0;g<h;g++)this[g].style&&(this[g].style.display="none");return this},_toggle:f.fn.toggle,toggle:function(a,b,c){var d=typeof a=="boolean";f.isFunction(a)&&f.isFunction(b)?this._toggle.apply(this,arguments):a==null||d?this.each(function(){var b=d?a:f(this).is(":hidden");f(this)[b?"show":"hide"]()}):this.animate(ct("toggle",3),a,b,c);return this},fadeTo:function(a,b,c,d){return this.filter(":hidden").css("opacity",0).show().end().animate({opacity:b},a,c,d)},animate:function(a,b,c,d){function g(){e.queue===!1&&f._mark(this);var b=f.extend({},e),c=this.nodeType===1,d=c&&f(this).is(":hidden"),g,h,i,j,k,l,m,n,o,p,q;b.animatedProperties={};for(i in a){g=f.camelCase(i),i!==g&&(a[g]=a[i],delete a[i]);if((k=f.cssHooks[g])&&"expand"in k){l=k.expand(a[g]),delete a[g];for(i in l)i in a||(a[i]=l[i])}}for(g in a){h=a[g],f.isArray(h)?(b.animatedProperties[g]=h[1],h=a[g]=h[0]):b.animatedProperties[g]=b.specialEasing&&b.specialEasing[g]||b.easing||"swing";if(h==="hide"&&d||h==="show"&&!d)return b.complete.call(this);c&&(g==="height"||g==="width")&&(b.overflow=[this.style.overflow,this.style.overflowX,this.style.overflowY],f.css(this,"display")==="inline"&&f.css(this,"float")==="none"&&(!f.support.inlineBlockNeedsLayout||cu(this.nodeName)==="inline"?this.style.display="inline-block":this.style.zoom=1))}b.overflow!=null&&(this.style.overflow="hidden");for(i in a)j=new f.fx(this,b,i),h=a[i],cm.test(h)?(q=f._data(this,"toggle"+i)||(h==="toggle"?d?"show":"hide":0),q?(f._data(this,"toggle"+i,q==="show"?"hide":"show"),j[q]()):j[h]()):(m=cn.exec(h),n=j.cur(),m?(o=parseFloat(m[2]),p=m[3]||(f.cssNumber[i]?"":"px"),p!=="px"&&(f.style(this,i,(o||1)+p),n=(o||1)/j.cur()*n,f.style(this,i,n+p)),m[1]&&(o=(m[1]==="-="?-1:1)*o+n),j.custom(n,o,p)):j.custom(n,h,""));return!0}var e=f.speed(b,c,d);if(f.isEmptyObject(a))return this.each(e.complete,[!1]);a=f.extend({},a);return e.queue===!1?this.each(g):this.queue(e.queue,g)},stop:function(a,c,d){typeof a!="string"&&(d=c,c=a,a=b),c&&a!==!1&&this.queue(a||"fx",[]);return this.each(function(){function h(a,b,c){var e=b[c];f.removeData(a,c,!0),e.stop(d)}var b,c=!1,e=f.timers,g=f._data(this);d||f._unmark(!0,this);if(a==null)for(b in g)g[b]&&g[b].stop&&b.indexOf(".run")===b.length-4&&h(this,g,b);else g[b=a+".run"]&&g[b].stop&&h(this,g,b);for(b=e.length;b--;)e[b].elem===this&&(a==null||e[b].queue===a)&&(d?e[b](!0):e[b].saveState(),c=!0,e.splice(b,1));(!d||!c)&&f.dequeue(this,a)})}}),f.each({slideDown:ct("show",1),slideUp:ct("hide",1),slideToggle:ct("toggle",1),fadeIn:{opacity:"show"},fadeOut:{opacity:"hide"},fadeToggle:{opacity:"toggle"}},function(a,b){f.fn[a]=function(a,c,d){return this.animate(b,a,c,d)}}),f.extend({speed:function(a,b,c){var d=a&&typeof a=="object"?f.extend({},a):{complete:c||!c&&b||f.isFunction(a)&&a,duration:a,easing:c&&b||b&&!f.isFunction(b)&&b};d.duration=f.fx.off?0:typeof d.duration=="number"?d.duration:d.duration in f.fx.speeds?f.fx.speeds[d.duration]:f.fx.speeds._default;if(d.queue==null||d.queue===!0)d.queue="fx";d.old=d.complete,d.complete=function(a){f.isFunction(d.old)&&d.old.call(this),d.queue?f.dequeue(this,d.queue):a!==!1&&f._unmark(this)};return d},easing:{linear:function(a){return a},swing:function(a){return-Math.cos(a*Math.PI)/2+.5}},timers:[],fx:function(a,b,c){this.options=b,this.elem=a,this.prop=c,b.orig=b.orig||{}}}),f.fx.prototype={update:function(){this.options.step&&this.options.step.call(this.elem,this.now,this),(f.fx.step[this.prop]||f.fx.step._default)(this)},cur:function(){if(this.elem[this.prop]!=null&&(!this.elem.style||this.elem.style[this.prop]==null))return this.elem[this.prop];var a,b=f.css(this.elem,this.prop);return isNaN(a=parseFloat(b))?!b||b==="auto"?0:b:a},custom:function(a,c,d){function h(a){return e.step(a)}var e=this,g=f.fx;this.startTime=cq||cr(),this.end=c,this.now=this.start=a,this.pos=this.state=0,this.unit=d||this.unit||(f.cssNumber[this.prop]?"":"px"),h.queue=this.options.queue,h.elem=this.elem,h.saveState=function(){f._data(e.elem,"fxshow"+e.prop)===b&&(e.options.hide?f._data(e.elem,"fxshow"+e.prop,e.start):e.options.show&&f._data(e.elem,"fxshow"+e.prop,e.end))},h()&&f.timers.push(h)&&!co&&(co=setInterval(g.tick,g.interval))},show:function(){var a=f._data(this.elem,"fxshow"+this.prop);this.options.orig[this.prop]=a||f.style(this.elem,this.prop),this.options.show=!0,a!==b?this.custom(this.cur(),a):this.custom(this.prop==="width"||this.prop==="height"?1:0,this.cur()),f(this.elem).show()},hide:function(){this.options.orig[this.prop]=f._data(this.elem,"fxshow"+this.prop)||f.style(this.elem,this.prop),this.options.hide=!0,this.custom(this.cur(),0)},step:function(a){var b,c,d,e=cq||cr(),g=!0,h=this.elem,i=this.options;if(a||e>=i.duration+this.startTime){this.now=this.end,this.pos=this.state=1,this.update(),i.animatedProperties[this.prop]=!0;for(b in i.animatedProperties)i.animatedProperties[b]!==!0&&(g=!1);if(g){i.overflow!=null&&!f.support.shrinkWrapBlocks&&f.each(["","X","Y"],function(a,b){h.style["overflow"+b]=i.overflow[a]}),i.hide&&f(h).hide();if(i.hide||i.show)for(b in i.animatedProperties)f.style(h,b,i.orig[b]),f.removeData(h,"fxshow"+b,!0),f.removeData(h,"toggle"+b,!0);d=i.complete,d&&(i.complete=!1,d.call(h))}return!1}i.duration==Infinity?this.now=e:(c=e-this.startTime,this.state=c/i.duration,this.pos=f.easing[i.animatedProperties[this.prop]](this.state,c,0,1,i.duration),this.now=this.start+(this.end-this.start)*this.pos),this.update();return!0}},f.extend(f.fx,{tick:function(){var a,b=f.timers,c=0;for(;c<b.length;c++)a=b[c],!a()&&b[c]===a&&b.splice(c--,1);b.length||f.fx.stop()},interval:13,stop:function(){clearInterval(co),co=null},speeds:{slow:600,fast:200,_default:400},step:{opacity:function(a){f.style(a.elem,"opacity",a.now)},_default:function(a){a.elem.style&&a.elem.style[a.prop]!=null?a.elem.style[a.prop]=a.now+a.unit:a.elem[a.prop]=a.now}}}),f.each(cp.concat.apply([],cp),function(a,b){b.indexOf("margin")&&(f.fx.step[b]=function(a){f.style(a.elem,b,Math.max(0,a.now)+a.unit)})}),f.expr&&f.expr.filters&&(f.expr.filters.animated=function(a){return f.grep(f.timers,function(b){return a===b.elem}).length});var cv,cw=/^t(?:able|d|h)$/i,cx=/^(?:body|html)$/i;"getBoundingClientRect"in c.documentElement?cv=function(a,b,c,d){try{d=a.getBoundingClientRect()}catch(e){}if(!d||!f.contains(c,a))return d?{top:d.top,left:d.left}:{top:0,left:0};var g=b.body,h=cy(b),i=c.clientTop||g.clientTop||0,j=c.clientLeft||g.clientLeft||0,k=h.pageYOffset||f.support.boxModel&&c.scrollTop||g.scrollTop,l=h.pageXOffset||f.support.boxModel&&c.scrollLeft||g.scrollLeft,m=d.top+k-i,n=d.left+l-j;return{top:m,left:n}}:cv=function(a,b,c){var d,e=a.offsetParent,g=a,h=b.body,i=b.defaultView,j=i?i.getComputedStyle(a,null):a.currentStyle,k=a.offsetTop,l=a.offsetLeft;while((a=a.parentNode)&&a!==h&&a!==c){if(f.support.fixedPosition&&j.position==="fixed")break;d=i?i.getComputedStyle(a,null):a.currentStyle,k-=a.scrollTop,l-=a.scrollLeft,a===e&&(k+=a.offsetTop,l+=a.offsetLeft,f.support.doesNotAddBorder&&(!f.support.doesAddBorderForTableAndCells||!cw.test(a.nodeName))&&(k+=parseFloat(d.borderTopWidth)||0,l+=parseFloat(d.borderLeftWidth)||0),g=e,e=a.offsetParent),f.support.subtractsBorderForOverflowNotVisible&&d.overflow!=="visible"&&(k+=parseFloat(d.borderTopWidth)||0,l+=parseFloat(d.borderLeftWidth)||0),j=d}if(j.position==="relative"||j.position==="static")k+=h.offsetTop,l+=h.offsetLeft;f.support.fixedPosition&&j.position==="fixed"&&(k+=Math.max(c.scrollTop,h.scrollTop),l+=Math.max(c.scrollLeft,h.scrollLeft));return{top:k,left:l}},f.fn.offset=function(a){if(arguments.length)return a===b?this:this.each(function(b){f.offset.setOffset(this,a,b)});var c=this[0],d=c&&c.ownerDocument;if(!d)return null;if(c===d.body)return f.offset.bodyOffset(c);return cv(c,d,d.documentElement)},f.offset={bodyOffset:function(a){var b=a.offsetTop,c=a.offsetLeft;f.support.doesNotIncludeMarginInBodyOffset&&(b+=parseFloat(f.css(a,"marginTop"))||0,c+=parseFloat(f.css(a,"marginLeft"))||0);return{top:b,left:c}},setOffset:function(a,b,c){var d=f.css(a,"position");d==="static"&&(a.style.position="relative");var e=f(a),g=e.offset(),h=f.css(a,"top"),i=f.css(a,"left"),j=(d==="absolute"||d==="fixed")&&f.inArray("auto",[h,i])>-1,k={},l={},m,n;j?(l=e.position(),m=l.top,n=l.left):(m=parseFloat(h)||0,n=parseFloat(i)||0),f.isFunction(b)&&(b=b.call(a,c,g)),b.top!=null&&(k.top=b.top-g.top+m),b.left!=null&&(k.left=b.left-g.left+n),"using"in b?b.using.call(a,k):e.css(k)}},f.fn.extend({position:function(){if(!this[0])return null;var a=this[0],b=this.offsetParent(),c=this.offset(),d=cx.test(b[0].nodeName)?{top:0,left:0}:b.offset();c.top-=parseFloat(f.css(a,"marginTop"))||0,c.left-=parseFloat(f.css(a,"marginLeft"))||0,d.top+=parseFloat(f.css(b[0],"borderTopWidth"))||0,d.left+=parseFloat(f.css(b[0],"borderLeftWidth"))||0;return{top:c.top-d.top,left:c.left-d.left}},offsetParent:function(){return this.map(function(){var a=this.offsetParent||c.body;while(a&&!cx.test(a.nodeName)&&f.css(a,"position")==="static")a=a.offsetParent;return a})}}),f.each({scrollLeft:"pageXOffset",scrollTop:"pageYOffset"},function(a,c){var d=/Y/.test(c);f.fn[a]=function(e){return f.access(this,function(a,e,g){var h=cy(a);if(g===b)return h?c in h?h[c]:f.support.boxModel&&h.document.documentElement[e]||h.document.body[e]:a[e];h?h.scrollTo(d?f(h).scrollLeft():g,d?g:f(h).scrollTop()):a[e]=g},a,e,arguments.length,null)}}),f.each({Height:"height",Width:"width"},function(a,c){var d="client"+a,e="scroll"+a,g="offset"+a;f.fn["inner"+a]=function(){var a=this[0];return a?a.style?parseFloat(f.css(a,c,"padding")):this[c]():null},f.fn["outer"+a]=function(a){var b=this[0];return b?b.style?parseFloat(f.css(b,c,a?"margin":"border")):this[c]():null},f.fn[c]=function(a){return f.access(this,function(a,c,h){var i,j,k,l;if(f.isWindow(a)){i=a.document,j=i.documentElement[d];return f.support.boxModel&&j||i.body&&i.body[d]||j}if(a.nodeType===9){i=a.documentElement;if(i[d]>=i[e])return i[d];return Math.max(a.body[e],i[e],a.body[g],i[g])}if(h===b){k=f.css(a,c),l=parseFloat(k);return f.isNumeric(l)?l:k}f(a).css(c,h)},c,a,arguments.length,null)}}),a.jQuery=a.$=f,typeof define=="function"&&define.amd&&define.amd.jQuery&&define("jquery",[],function(){return f})})(window);
                </script><?php

                if ( $javascript ) {
                    $javascript();
                }
            }
        }
         
        /**
         * This is a carbon copy of \ErrorException.
         * However that is only supported in PHP 5.1 and above,
         * so this allows PHP Error to work in PHP 5.0.
         *
         * A thin class that wraps up an error, into an exception.
         */
        class ErrorException extends Exception
        {
            public function __construct( $message, $code, $severity, $file, $line )
            {
                parent::__construct( $message, $code, null );

                $this->file = $file;
                $this->line = $line;
            }
        }

        /**
         * Code is outputted multiple times, for each file involved.
         * This allows us to wrap up a single set of code.
         */
        class FileLinesSet
        {
            private $id;
            private $lines;
            private $isShown;
            private $line;

            public function __construct( $line, $id, array $lines, $isShown ) {
                $this->id = $id;
                $this->lines = $lines;
                $this->isShown = $isShown;
                $this->line = $line;
            }

            public function getHTMLID() {
                return $this->id;
            }

            public function getLines() {
                return $this->lines;
            }

            public function isShown() {
                return $this->isShown;
            }

            public function getLine() {
                return $this->line;
            }
        }

        /**
         * jsmin.php - PHP implementation of Douglas Crockford's JSMin.
         *
         * This is pretty much a direct port of jsmin.c to PHP with just a few
         * PHP-specific performance tweaks. Also, whereas jsmin.c reads from stdin and
         * outputs to stdout, this library accepts a string as input and returns another
         * string as output.
         *
         * PHP 5 or higher is required.
         *
         * Permission is hereby granted to use this version of the library under the
         * same terms as jsmin.c, which has the following license:
         *
         * --
         * Copyright (c) 2002 Douglas Crockford (www.crockford.com)
         *
         * Permission is hereby granted, free of charge, to any person obtaining a copy of
         * this software and associated documentation files (the "Software"), to deal in
         * the Software without restriction, including without limitation the rights to
         * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
         * of the Software, and to permit persons to whom the Software is furnished to do
         * so, subject to the following conditions:
         *
         * The above copyright notice and this permission notice shall be included in all
         * copies or substantial portions of the Software.
         *
         * The Software shall be used for Good, not Evil.
         *
         * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
         * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
         * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
         * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
         * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
         * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
         * SOFTWARE.
         * --
         *
         * @package JSMin
         * @author Ryan Grove <ryan@wonko.com>
         * @copyright 2002 Douglas Crockford <douglas@crockford.com> (jsmin.c)
         * @copyright 2008 Ryan Grove <ryan@wonko.com> (PHP port)
         * @copyright 2012 Adam Goforth <aag@adamgoforth.com> (Updates)
         * @license http://opensource.org/licenses/mit-license.php MIT License
         * @version 1.1.2 (2012-05-01)
         * @link https://github.com/rgrove/jsmin-php
         */
        class JSMin
        {
            const ORD_LF = 10;
            const ORD_SPACE = 32;
            const ACTION_KEEP_A = 1;
            const ACTION_DELETE_A = 2;
            const ACTION_DELETE_A_B = 3;

            protected $a = '';
            protected $b = '';
            protected $input = '';
            protected $inputIndex = 0;
            protected $inputLength = 0;
            protected $lookAhead = null;
            protected $output = '';

            // -- Public Static Methods --------------------------------------------------

            /**
             * Minify Javascript
             *
             * @uses __construct()
             * @uses min()
             * @param string $js Javascript to be minified
             * @return string
             */
            public static function minify($js) {
                $jsmin = new JSMin($js);
                return $jsmin->min();
            }

            // -- Public Instance Methods ------------------------------------------------

            /**
             * Constructor
             *
             * @param string $input Javascript to be minified
             */
            public function __construct($input) {
                $this->input = str_replace("\r\n", "\n", $input);
                $this->inputLength = strlen($this->input);
            }

            // -- Protected Instance Methods ---------------------------------------------

            /**
             * Action -- do something! What to do is determined by the $command argument.
             *
             * action treats a string as a single character. Wow!
             * action recognizes a regular expression if it is preceded by ( or , or =.
             *
             * @uses next()
             * @uses get()
             * @throws JSMinException If parser errors are found:
             * - Unterminated string literal
             * - Unterminated regular expression set in regex literal
             * - Unterminated regular expression literal
             * @param int $command One of class constants:
             * ACTION_KEEP_A Output A. Copy B to A. Get the next B.
             * ACTION_DELETE_A Copy B to A. Get the next B. (Delete A).
             * ACTION_DELETE_A_B Get the next B. (Delete B).
             */
            protected function action($command) {
                switch($command) {
                    case self::ACTION_KEEP_A:
                        $this->output .= $this->a;

                    case self::ACTION_DELETE_A:
                        $this->a = $this->b;

                        if ($this->a === "'" || $this->a === '"') {
                            for (;;) {
                                $this->output .= $this->a;
                                $this->a = $this->get();

                                if ($this->a === $this->b) {
                                    break;
                                }

                                if (ord($this->a) <= self::ORD_LF) {
                                    throw new JSMinException('Unterminated string literal.');
                                }

                                if ($this->a === '\\') {
                                    $this->output .= $this->a;
                                    $this->a = $this->get();
                                }
                            }
                        }

                    case self::ACTION_DELETE_A_B:
                        $this->b = $this->next();

                        if ($this->b === '/' && (
                                $this->a === '(' || $this->a === ',' || $this->a === '=' ||
                                $this->a === ':' || $this->a === '[' || $this->a === '!' ||
                                $this->a === '&' || $this->a === '|' || $this->a === '?' ||
                                $this->a === '{' || $this->a === '}' || $this->a === ';' ||
                                $this->a === "\n" )) {

                            $this->output .= $this->a . $this->b;

                            for (;;) {
                                $this->a = $this->get();

                                if ($this->a === '[') {
                                    /*
        inside a regex [...] set, which MAY contain a '/' itself. Example: mootools Form.Validator near line 460:
        return Form.Validator.getValidator('IsEmpty').test(element) || (/^(?:[a-z0-9!#$%&'*+/=?^_`{|}~-]\.?){0,63}[a-z0-9!#$%&'*+/=?^_`{|}~-]@(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\])$/i).test(element.get('value'));
             */
                                    for (;;) {
                                        $this->output .= $this->a;
                                        $this->a = $this->get();

                                        if ($this->a === ']') {
                                                break;
                                        } elseif ($this->a === '\\') {
                                            $this->output .= $this->a;
                                            $this->a = $this->get();
                                        } elseif (ord($this->a) <= self::ORD_LF) {
                                            throw new JSMinException('Unterminated regular expression set in regex literal.');
                                        }
                                    }
                                } elseif ($this->a === '/') {
                                    break;
                                } elseif ($this->a === '\\') {
                                    $this->output .= $this->a;
                                    $this->a = $this->get();
                                } elseif (ord($this->a) <= self::ORD_LF) {
                                    throw new JSMinException('Unterminated regular expression literal.');
                                }

                                $this->output .= $this->a;
                            }

                            $this->b = $this->next();
                        }
                }
            }

            /**
             * Get next char. Convert ctrl char to space.
             *
             * @return string|null
             */
            protected function get() {
                $c = $this->lookAhead;
                $this->lookAhead = null;

                if ($c === null) {
                    if ($this->inputIndex < $this->inputLength) {
                        $c = substr($this->input, $this->inputIndex, 1);
                        $this->inputIndex += 1;
                    } else {
                        $c = null;
                    }
                }

                if ($c === "\r") {
                    return "\n";
                }

                if ($c === null || $c === "\n" || ord($c) >= self::ORD_SPACE) {
                    return $c;
                }

                return ' ';
            }

            /**
             * Is $c a letter, digit, underscore, dollar sign, or non-ASCII character.
             *
             * @return bool
             */
            protected function isAlphaNum($c) {
                return ord($c) > 126 || $c === '\\' || preg_match('/^[\w\$]$/', $c) === 1;
            }

            /**
             * Perform minification, return result
             *
             * @uses action()
             * @uses isAlphaNum()
             * @uses get()
             * @uses peek()
             * @return string
             */
            protected function min() {
                if (0 == strncmp($this->peek(), "\xef", 1)) {
                        $this->get();
                        $this->get();
                        $this->get();
                }

                $this->a = "\n";
                $this->action(self::ACTION_DELETE_A_B);

                while ($this->a !== null) {
                    switch ($this->a) {
                        case ' ':
                            if ($this->isAlphaNum($this->b)) {
                                $this->action(self::ACTION_KEEP_A);
                            } else {
                                $this->action(self::ACTION_DELETE_A);
                            }
                            break;

                        case "\n":
                            switch ($this->b) {
                                case '{':
                                case '[':
                                case '(':
                                case '+':
                                case '-':
                                case '!':
                                case '~':
                                    $this->action(self::ACTION_KEEP_A);
                                    break;

                                case ' ':
                                    $this->action(self::ACTION_DELETE_A_B);
                                    break;

                                default:
                                    if ($this->isAlphaNum($this->b)) {
                                        $this->action(self::ACTION_KEEP_A);
                                    }
                                    else {
                                        $this->action(self::ACTION_DELETE_A);
                                    }
                            }
                            break;

                        default:
                            switch ($this->b) {
                                case ' ':
                                    if ($this->isAlphaNum($this->a)) {
                                        $this->action(self::ACTION_KEEP_A);
                                        break;
                                    }

                                    $this->action(self::ACTION_DELETE_A_B);
                                    break;

                                case "\n":
                                    switch ($this->a) {
                                        case '}':
                                        case ']':
                                        case ')':
                                        case '+':
                                        case '-':
                                        case '"':
                                        case "'":
                                            $this->action(self::ACTION_KEEP_A);
                                            break;

                                        default:
                                            if ($this->isAlphaNum($this->a)) {
                                                $this->action(self::ACTION_KEEP_A);
                                            }
                                            else {
                                                $this->action(self::ACTION_DELETE_A_B);
                                            }
                                    }
                                    break;

                                default:
                                    $this->action(self::ACTION_KEEP_A);
                                    break;
                            }
                    }
                }

                return $this->output;
            }

            /**
             * Get the next character, skipping over comments. peek() is used to see
             * if a '/' is followed by a '/' or '*'.
             *
             * @uses get()
             * @uses peek()
             * @throws JSMinException On unterminated comment.
             * @return string
             */
            protected function next() {
                $c = $this->get();

                if ($c === '/') {
                    switch($this->peek()) {
                        case '/':
                            for (;;) {
                                $c = $this->get();

                                if (ord($c) <= self::ORD_LF) {
                                    return $c;
                                }
                            }

                        case '*':
                            $this->get();

                            for (;;) {
                                switch($this->get()) {
                                    case '*':
                                        if ($this->peek() === '/') {
                                            $this->get();
                                            return ' ';
                                        }
                                        break;

                                    case null:
                                        throw new JSMinException('Unterminated comment.');
                                }
                            }

                        default:
                            return $c;
                    }
                }

                return $c;
            }

            /**
             * Get next char. If is ctrl character, translate to a space or newline.
             *
             * @uses get()
             * @return string|null
             */
            protected function peek() {
                $this->lookAhead = $this->get();
                return $this->lookAhead;
            }
        }

        // -- Exceptions ---------------------------------------------------------------
        class JSMinException extends Exception {}

        if (
                $_php_error_is_ini_enabled &&
                $_php_error_global_handler === null &&
                @get_cfg_var('php_error.autorun')
        ) {
            reportErrors();
        }
    }
