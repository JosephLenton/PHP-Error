<?
    /**
     * @license
     * 
     * Better Error
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
     */

    /**
     * PHP Better Error Reporting
     * 
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     * 
     * WARNING! It is downright _DANGEROUS_ to use this in production, on
     * a live website. It should *ONLY* be used for development.
     * 
     * Better Errors will kill your environment at will, clear the output
     * buffers, and allows HTML injection from exception and other places.
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
     *      \better_error_reporting\reportErrors();
     * 
     * Advanced example:
     * 
     * There is more too it if you want more customized error handling. You
     * can pass in options, to customize the setup, and you get back a
     * handler you can alter at runtime.
     * 
     *      $handler = new \better_error_reporting\BetterErrorsReporter( $myOptions );
     *      $handler->turnOn();
     * 
     * There should only ever be one handler! This is an (underdstandable)
     * limitation in PHP. It's because if an exception or error is raised,
     * then there is a single point of handling it.
     * 
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     * 
     * @author Joseph Lenton | https://github.com/josephlenton
     */

    namespace better_error_reporting;

    use \better_error_reporting\ErrorToExceptionException,
        \better_error_reporting\BetterErrorsReporter;

    use \Exception,
        \InvalidArgumentException;

    use \ReflectionMethod,
        \ReflectionFunction,
        \ReflectionParameter;

    global $_better_error_reporting_global_handler;
    $_better_error_reporting_global_handler = null;

    /**
     * Turns on error reporting, and returns the handler.
     * 
     * If you just want error reporting on, then don't bother
     * catching the handler. If you're building something
     * clever, like a framework, then you might want to grab
     * and use it.
     * 
     * Note that you can only call this *once*. Repeat calls
     * will throw an exception.
     * 
     * @param options Optional, options declaring how better errors should be setup and used.
     * @return The BetterErrorsReporter used for reporting errors.
     */
    function reportErrors( $options=null ) {
        $handler = new BetterErrorsReporter( $options );
        return $handler->turnOn();
    }

    /**
     * The actual handler. There can only ever be one.
     */
    class BetterErrorsReporter
    {
        const REGEX_PHP_IDENTIFIER = '\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';

        /**
         * Matches:
         *  blah::foo()
         *  foo()
         */
        const REGEX_METHOD_OR_FUNCTION_END = '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/';
        const REGEX_METHOD_OR_FUNCTION = '/(\\{closure\\})|(\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?)\\(\\)/';

        /**
         * The number of lines to take from the file,
         * where the error is reported. This is the number
         * of lines around the line in question,
         * including that line.
         * 
         * So '9' will be the error line + 4 lines above + 4 lines below.
         */
        const NUM_FILE_LINES = 7;

        const FILE_TYPE_APPLICATION = 1;
        const FILE_TYPE_IGNORE = 2;

        /**
         * At the time of writing, scalar type hints are unsupported.
         * By scalar, I mean 'string' and 'integer'.
         * 
         * If they do get added, this is here as a trap to turn scalar
         * type hint warnings on and off.
         */
        private static $IS_SCALAR_TYPE_HINTING_SUPPORTED = false;

        private static $SCALAR_TYPES = array(
                'string', 'integer', 'float', 'boolean', 'int', 'number'
        );

        /**
         * A mapping of PHP internal symbols,
         * mapped to descriptions of them.
         */
        private static $PHP_SYMBOL_MAPPINGS = array(
                '$end'                          => 'end of file',
                'T_ABSTRACT'                    => 'abstract',
                'T_AND_EQUAL'                   => '&=',
                'T_ARRAY'                       => 'array',
                'T_ARRAY_CAST'                  => 'array cast',
                'T_AS'                          => 'as',
                'T_BOOLEAN_AND'                 => '&&',
                'T_BOOLEAN_OR'                  => '||',
                'T_BOOL_CAST'                   => 'boolean cast',
                'T_BREAK'                       => 'break',
                'T_CASE'                        => 'case',
                'T_CATCH'                       => 'catch',
                'T_CLASS'                       => 'class',
                'T_CLASS_C'                     => '__CLASS__',
                'T_CLONE'                       => 'clone',
                'T_CLOSE_TAG'                   => 'closing PHP tag',
                'T_CONCAT_EQUAL'                => '.=',
                'T_CONST'                       => 'const',
                'T_CONSTANT_ENCAPSED_STRING'    => 'string',
                'T_CONTINUE'                    => 'continue',
                'T_DEC'                         => '-- decrement',
                'T_DECLARE'                     => 'declare',
                'T_DEFAULT'                     => 'default',
                'T_DIR'                         => '__DIR__',
                'T_DIV_EQUAL'                   => '/=',
                'T_DNUMBER'                     => 'number',
                'T_DO'                          => 'do',
                'T_DOUBLE_ARROW'                => '=>',
                'T_DOUBLE_CAST'                 => 'double cast',
                'T_DOUBLE_COLON'                => '::',
                'T_ECHO'                        => 'echo',
                'T_ELSE'                        => 'else',
                'T_ELSEIF'                      => 'elseif',
                'T_EMPTY'                       => 'empty',
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
                'T_INC'                         => '++ increment',
                'T_INCLUDE'                     => 'include',
                'T_INCLUDE_ONCE'                => 'include_once',
                'T_INSTANCEOF'                  => 'instanceof',
                'T_INT_CAST'                    => 'int cast',
                'T_INTERFACE'                   => 'interface',
                'T_ISSET'                       => 'isset',
                'T_IS_EQUAL'                    => '==',
                'T_IS_GREATER_OR_EQUAL'         => '>=',
                'T_IS_IDENTICAL'                => '===',
                'T_IS_NOT_EQUAL'                => '!= or <>',
                'T_IS_NOT_IDENTICAL'            => '!==',
                'T_IS_SMALLER_OR_EQUAL'         => '<=',
                'T_LINE'                        => '__LINE__',
                'T_LIST'                        => 'list',
                'T_LNUMBER'                     => 'number',
                'T_LOGICAL_AND'                 => 'and',
                'T_LOGICAL_OR'                  => 'or',
                'T_LOGICAL_XOR'                 => 'xor',
                'T_METHOD_C'                    => '__METHOD__',
                'T_MINUS_EQUAL'                 => '-=',
                'T_MOD_EQUAL'                   => '%=',
                'T_MUL_EQUAL'                   => '*=',
                'T_NAMESPACE'                   => 'namespace',
                'T_NS_C'                        => '__NAMESPACE__',
                'T_NS_SEPARATOR'                => '/ namespace seperator',
                'T_NEW'                         => 'new',
                'T_OBJECT_CAST'                 => 'object cast',
                'T_OBJECT_OPERATOR'             => '->',
                'T_OLD_FUNCTION'                => 'old_function',
                'T_OPEN_TAG'                    => '<?php or <?',
                'T_OPEN_TAG_WITH_ECHO'          => '<?=',
                'T_OR_EQUAL'                    => '|=',
                'T_PAAMAYIM_NEKUDOTAYIM'        => '::',
                'T_PLUS_EQUAL'                  => '+=',
                'T_PRINT'                       => 'print',
                'T_PRIVATE'                     => 'private',
                'T_PUBLIC'                      => 'public',
                'T_PROTECTED'                   => 'protected',
                'T_REQUIRE'                     => 'require',
                'T_REQUIRE_ONCE'                => 'require_once',
                'T_RETURN'                      => 'return',
                'T_SL'                          => '<<',
                'T_SL_EQUAL'                    => '<<=',
                'T_SR'                          => '>>',
                'T_SR_EQUAL'                    => '>>=',
                'T_START_HEREDOC'               => '<<<',
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
                'T_XOR_EQUAL'                   => '^='
        );

        private static $syntaxMap = array(
                T_COMMENT                     => 'syntax-comment',

                'reference_ampersand'         => 'syntax-function',

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
         * Looks up a description for the symbol given,
         * and if found, it is returned.
         * 
         * If it's not found, then the symbol given is returned.
         */
        private static function phpSymbolToDescription( $symbol ) {
            if ( isset(BetterErrorsReporter::$PHP_SYMBOL_MAPPINGS[$symbol]) ) {
                return BetterErrorsReporter::$PHP_SYMBOL_MAPPINGS[$symbol];
            } else {
                return $symbol;
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
            $syntaxMap = BetterErrorsReporter::$syntaxMap;

            // @supress invalid code raises a warning
            $tokens = @token_get_all( "<?php " . $code . " ?>" );
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

                    if ( $type === T_CONSTANT_ENCAPSED_STRING ) {
                        $code = htmlspecialchars( $code );
                    }
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
                            $code = '&amp;';
                            $type = 'reference_ampersand';
                        }
                    }
                } else if ( $code === '"' || $code === "'" ) {
                    if ( $inString ) {
                        $html[]= "<span class='syntax-string'>" . join('', $stringBuff) . "$code</span>";
                        $stringBuff = null;
                        $skip = true;
                    } else {
                        $stringBuff = array();
                    }

                    $inString = !$inString;
                } else if ( $code === '->' ) {
                    $code = '-&gt;';
                }

                if ( $skip ) {
                    $skip = false;
                } else {
                    if ( $type !== null && isset($syntaxMap[$type]) ) {
                        $class = $syntaxMap[$type];

                        if ( $type === T_CONSTANT_ENCAPSED_STRING && strpos($code, "\n") !== false ) {
                            $append = "<span class='$class'>" .
                                        join(
                                                "</span>\n<span class='$class'>",
                                                explode( "\n", $code )
                                        ) .
                                    "</span>" ;
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
         *      list( $class, $function ) = BetterErrorsReporter::splitFunction( $name );
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

                return BetterErrorsReporter::newArgument(
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
            list( $className, $type, $functionName ) = BetterErrorsReporter::splitFunction( $match );

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
                        $arg = BetterErrorsReporter::newArgument( $param );

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

                    return BetterErrorsReporter::syntaxHighlightFunction( $className, $type, $functionName, $args );
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
                return $alt;
            }
        }

        private static function isFolderType( &$folders, $longest, $file ) {
            $parts = explode( '/', $file );

            $len = min( count($parts), $longest );

            for ( $i = 0; $i < $len; $i++ ) {
                if ( isset($folders[$i+1]) ) {
                    $folderParts = &$folders[ $i+1 ];

                    $success = false;
                    for ( $j = 0; $j < count($folderParts); $j++ ) {
                        if ( $folderParts[$j] === $parts[$j] ) {
                            $success = true;
                        } else {
                            $success = false;
                            break;
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

            foreach ( $folders as $i => $folder ) {
                $folder = str_replace( '\\', '/', $folder );
                $folder = preg_replace( '/(^\\/+)|(\\/+$)/', '', $folder );
                $parts = explode( '/', $folder );
                $count = count( $parts );

                $newLongest = max( $newLongest, $count );
                
                if ( isset($newFolders[$count]) ) {
                    $folds = &$newFolders[$count];
                    $folds[]= $folder;
                } else {
                    $newFolders[$count] = array( $folder );
                }
            }

            $origFolders = $newFolders;
            $longest     = $newLongest;
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
        private $documentRoot;
        private $serverName;

        private $catchClassNotFound;

        private $catchSurpressedErrors;

        private $backgroundText;

        /**
         * = Options =
         * 
         * All options are optional, and so is passing in an options item.
         * You don't have to supply any, it's up to you.
         * 
         * Includes:
         *  = Types of errors this will / won't catch =
         *  - catch_class_not_found     When true, calling the class autoloader in PHP will throw an error. This defaults to true.
         *  - catch_supressed_errors    The @ supresses errors. However if this is set to true, then they are still reported. Defaults to false.
         * 
         *  = Error reporting level =
         *  - error_reporting_on        value for when errors are on, defaults to all errors
         *  - error_reporting_off       value for when errors are off, defaults to php.ini's error_reporting.
         * 
         *  = Setup details =
         *  - document_root             When it's working out hte stack trace, this is the root folder of the application, to use as it's base.
         *                              Defaults to the servers root directory.
         * 
         *                              A relative path can be given, but lets be honest, an explicit path is the way to guarantee that you
         *                              will get the path you want. My relative might not be the same as your relative.
         * 
         *  - server_name               The name for this server, defaults to "$_SERVER['SERVER_NAME']"
         * 
         *  - ignore_folders            This is allows you to highlight non-framework code in a stack trace.
         *                              An array of folders to ignore, when working out the stack trace.
         *                              This is folder prefixes in relation to the document_root, whatever that might be.
         *                              They are only ignored if there is a file found outside of them.
         *                              If you still don't get what this does, don't worry, it's here cos I use it.
         * 
         *  - application_folders       Just like ignore, but anything found in these folders takes precedence
         *                              over anything else.
         * 
         *  - background_text           The text that appeares in the background. By default this is blank.
         *                              Why? You can replace this with the name of your framework, for extra customization spice.
         * 
         * @param options Optional, an array of values to customize this handler.
         * @throws Exception This is raised if given an options that does *not* exist (so you know that option is meaningless).
         * @throws Exception 
         */
        public function __construct( $options=null ) {
            // there can only be one to rule them all
            global $_better_error_reporting_global_handler;
            if ( $_better_error_reporting_global_handler !== null ) {
                throw new Exception( "there can only ever be one BetterErrorsReporter" );
            }
            $_better_error_reporting_global_handler = $this;

            $this->cachedFiles = array();

            $this->isShutdownRegistered = false;
            $this->isOn = false;

            /*
             * Deal with the options.
             * 
             * They are removed one by one, and any left, will raise an error.
             */

            $ignoreFolders = BetterErrorsReporter::optionsPop( $options, 'ignore_folders' , null );
            if ( $ignoreFolders !== null ) {
                $this->setFolders( $this->ignoreFolders, $this->ignoreFoldersLongest, $ignoreFolders );
            }

            $appFolders = BetterErrorsReporter::optionsPop( $options, 'application_folders' , null );
            if ( $appFolders !== null ) {
                $this->setFolders( $this->applicationFolders, $this->applicationFoldersLongest, $appFolders );
            }

            $this->defaultErrorReportingOn  = BetterErrorsReporter::optionsPop( $options, 'error_reporting_on' , -1 );
            $this->defaultErrorReportingOff = BetterErrorsReporter::optionsPop( $options, 'error_reporting_off', error_reporting() );

            /*
             * Relative paths might be given for document root,
             * so we make it explicit.
             */
            $this->documentRoot             = BetterErrorsReporter::optionsPop( $options, 'document_root', $_SERVER['DOCUMENT_ROOT'] );
            $dir = null;
            $dir = @realpath( $this->documentRoot );
            if ( $dir === null || $dir === false ) {
                throw new Exception("Document root not found: " . $this->documentRoot);
            } else {
                $this->documentRoot =  str_replace( '\\', '/', $dir );
            }

            $this->serverName               = BetterErrorsReporter::optionsPop( $options, 'error_reporting_off', $_SERVER['SERVER_NAME'] );
            $this->catchClassNotFound       = BetterErrorsReporter::optionsPop( $options, 'catch_class_not_found' , true );
            $this->catchSurpressedErrors    = BetterErrorsReporter::optionsPop( $options, 'catch_supressed_errors', false );
            $this->backgroundText           = BetterErrorsReporter::optionsPop( $options, 'background_text', 'CI' );

            if ( $options ) {
                foreach ( $options as $key => $val ) {
                    throw new InvalidArgumentException( "Unknown option given $key" );
                }
            }
        }

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
         */
        public function turnOn() {
            $this->isOn = true;
            $this->attachErrorHandles();

            return $this;
        }

        /**
         * Turns error reporting off.
         * 
         * This will use the 'php.ini' setting for the error_reporting level,
         * or one you have passed in if you used the 'error_reporting_off'
         * option when creating this.
         */
        public function turnOff() {
            $this->isOn = false;

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
         */
        public function withoutErrors( $callback ) {
            if ( ! is_callable($callback) ) {
                throw new Exception( "non callable callback given" );
            }

            $this->turnOff();
            $callback();
            $this->turnOn();

            return $this;
        }

        private function isApplicationFolder( $file ) {
            return BetterErrorsReporter::isFolderType(
                    $this->applicationFolders,
                    $this->applicationFoldersLongest,
                    $file
            );
        }

        private function isIgnoreFolder( $file ) {
            return BetterErrorsReporter::isFolderType(
                    $this->ignoreFolders,
                    $this->ignoreFoldersLongest,
                    $file
            );
        }

        private function getFolderType( $root, $file=null ) {
            if ( func_num_args() === 1 ) {
                $file = $root;
            } else {
                $file = $this->removeRootPath( $root, $file );
            }

            if ( $this->isApplicationFolder($file) ) {
                return BetterErrorsReporter::FILE_TYPE_APPLICATION;
            } else if ( $this->isIgnoreFolder($file) ) {
                return BetterErrorsReporter::FILE_TYPE_IGNORE;
            } else {
                return false;
            }
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
                                    $contents
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
                    $numLines = BetterErrorsReporter::NUM_FILE_LINES;

                    /*
                     * This ensures we attempt to always get NUM_FILE_LINES
                     * number of lines, if we are at the top of the file,
                     * for consistency.
                     */
                    $searchDown = (int)( $numLines/2 );
                    $minLine = max( 0, $errLine-$searchDown );
                    $maxLine = min( $minLine+$numLines, count($lines) );

                    $fileLines = array_splice( $lines, $minLine, $maxLine-$minLine );

                    $fileLines = join( "\n", $fileLines );
                    $fileLines = BetterErrorsReporter::syntaxHighlight( $fileLines );
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
                        list( $className, $type, $functionName ) = BetterErrorsReporter::splitFunction( $matches[0] );

                        if ( $stackTrace && isset($stackTrace[1]) && $stackTrace[1]['args'] ) {
                            $numArgs = count( $stackTrace[1]['args'] );

                            for ( $i = 0; $i < $numArgs; $i++ ) {
                                $args[]= BetterErrorsReporter::newArgument( "_" );
                            }
                        }

                        $message = preg_replace(
                                '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((->|::)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/',
                                BetterErrorsReporter::syntaxHighlightFunction( $className, $type, $functionName, $args ),
                                $message
                        );
                    }
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
                                "'<span class='syntax-class'>$className</span>'",
                                $message
                        );
                    }
                }
            } else if ( $code === 2 ) {
                if ( strpos($message, "Missing argument ") === 0 ) {
                    $message = preg_replace( '/, called in .*$/', '', $message );

                    $matches = array();
                    preg_match( BetterErrorsReporter::REGEX_METHOD_OR_FUNCTION_END, $message, $matches );

                    if ( $matches ) {
                        $argumentMathces = array();
                        preg_match( '/^Missing argument ([0-9]+)/', $message, $argumentMathces );
                        $highlightArg = count($argumentMathces) === 2 ?
                                (((int) $argumentMathces[1])-1) :
                                null ;

                        $altInfo = BetterErrorsReporter::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg, $numHighlighted );

                        if ( $numHighlighted > 0 ) {
                            $message = preg_replace( '/^Missing argument ([0-9]+)/', 'Missing arguments ', $message );
                        }

                        if ( $altInfo ) {
                            $message = preg_replace( BetterErrorsReporter::REGEX_METHOD_OR_FUNCTION_END, $altInfo, $message );

                            list( $srcErrFile, $srcErrLine, $stackSearchI ) = $skipStackFirst( $stackTrace );
                        }
                    }
                }
            /*
             * Unexpected symbol errors.
             * For example 'unexpected T_OBJECT_OPERATOR'.
             * 
             * This swaps the 'T_WHATEVER' for the symbolic representation.
             */
            } else if ( $code === 4 ) {
                $matches = array();
                $num = preg_match( '/\bunexpected ([A-Z_]+|\\$end)\b/', $message, $matches );

                if ( $num > 0 ) {
                    $match = $matches[0];
                    $newSymbol = BetterErrorsReporter::phpSymbolToDescription( str_replace('unexpected ', '', $match) );

                    $message = str_replace( $match, "unexpected '$newSymbol'", $message );
                }

                $matches = array();
                $num = preg_match( '/, expecting ([A-Z_]+|\\$end)\b/', $message, $matches );

                if ( $num > 0 ) {
                    $match = $matches[0];
                    $newSymbol = BetterErrorsReporter::phpSymbolToDescription( str_replace(', expecting ', '', $match) );

                    $message = str_replace( $match, ", expecting '$newSymbol'", $message );
                }
            /**
             * Undefined Variable, add syntax highlighting and make variable from 'foo' too '$foo'.
             */
            } else if ( $code === 8 ) {
                if (
                    strpos($message, "Undefined variable") !== false
                ) {
                    $matches = array();
                    preg_match( '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $message, $matches );

                    if ( count($matches) > 0 ) {
                        $message = preg_replace(
                                '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/',
                                '<span class="syntax-variable">$' . $matches[0] . '</span>',
                                $message
                        );
                    }
                }
            /**
             * Invalid type given.
             */
            } else if ( $code === 4096 ) {
                if ( strpos($message, 'must be an ') ) {
                    $message = preg_replace( '/, called in .*$/', '', $message );

                    $matches = array();
                    preg_match( BetterErrorsReporter::REGEX_METHOD_OR_FUNCTION, $message, $matches );

                    if ( $matches ) {
                        $argumentMathces = array();
                        preg_match( '/^Argument ([0-9]+)/', $message, $argumentMathces );
                        $highlightArg = count($argumentMathces) === 2 ?
                                (((int) $argumentMathces[1])-1) :
                                null ;

                        $fun = BetterErrorsReporter::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg );

                        if ( $fun ) {
                            $message = str_replace( 'passed to ', 'calling ', $message );
                            $message = preg_replace( BetterErrorsReporter::REGEX_METHOD_OR_FUNCTION, $fun, $message );
                            $prioritizeCaller = true;

                            /*
                             * scalars not supported.
                             */
                            $scalarType = null;
                            if ( ! BetterErrorsReporter::$IS_SCALAR_TYPE_HINTING_SUPPORTED ) {
                                foreach ( BetterErrorsReporter::$SCALAR_TYPES as $scalar ) {
                                    if ( stripos($message, "must be an instance of $scalar,") !== false ) {
                                        $scalarType = $scalar;
                                        break;
                                    }
                                }
                            }

                            if ( $scalarType !== null ) {
                                $message = preg_replace( '/^Argument [0-9]+ /', 'Incorrect type hint ', $message );
                                $message = preg_replace(
                                        '/ must be an instance of ' . BetterErrorsReporter::REGEX_PHP_IDENTIFIER . '\b.*$/',
                                        ", ${scalarType} not supported",
                                        $message
                                );

                                $prioritizeCaller = false;
                            } else {
                                $message = preg_replace( '/ must be an (instance of )?' . BetterErrorsReporter::REGEX_PHP_IDENTIFIER . '\b/', '', $message );

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
                if (
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

                if ( $stackTrace ) {
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
                            $type = $this->getFolderType( $root, $trace['file'] );

                            if ( $type !== BetterErrorsReporter::FILE_TYPE_IGNORE ) {
                                if ( $type === BetterErrorsReporter::FILE_TYPE_APPLICATION ) {
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
                $lineLen     = 0;
                $functionLen = 0;
                $fileLen     = 0;

                $identifyType = function( $arg, $recurse, $identifyType ) {
                    if ( ! is_array($arg) && !is_object($arg) ) {
                        if ( is_string($arg) ) {
                            return "<span class='syntax-string'>\"$arg\"</span>";
                        } else {
                            return "<span class='syntax-literal'>" . var_export( $arg, true ) . '</span>';
                        }
                    } else if ( is_array($arg) ) {
                        if ( count($arg) === 0 ) {
                            return "[]";
                        } else if ( $recurse ) {
                            $argArr = array();

                            foreach ($arg as $i => $ag) {
                                $argArr[]= $identifyType( $ag, false, $identifyType );
                            }

                            return "[" . join(', ', $argArr) . "]";
                        } else {
                            return "[...]";
                        }
                    } else if ( get_class($arg) === 'Closure' ) {
                        return '<span class="syntax-variable">$Closure</span>()';
                    } else {
                        return '<span class="syntax-variable">$' . get_class( $arg ) . '</span>';
                    }
                };

                // parse the stack trace, and remove the long urls
                foreach ( $stackTrace as $i => $trace ) {
                    if ( $trace ) {
                        $trace['info'] = '';

                        if ( isset($trace['line'] ) ) {
                            $lineLen = max( $lineLen, strlen($trace['line']) );
                        } else {
                            $trace['line'] = '';
                        }

                        $info = '';

                        if ( $i === 0 ) {
                            if ( $altInfo !== null ) {
                                $info = $altInfo;
                            } else if ( isset($trace['info']) && $trace['info'] !== '' ) {
                                $info = BetterErrorsReporter::syntaxHighlight( $trace['info'] );
                            } else { 
                                $contents = $this->getFileContents( $trace['file'] );

                                if ( $contents ) {
                                    $info = BetterErrorsReporter::syntaxHighlight(
                                            trim( $contents[$trace['line']-1] )
                                    );
                                }
                            }
                        } else {
                            $args = array();
                            if ( isset($trace['args']) ) {
                                foreach ( $trace['args'] as $arg ) {
                                    $args[]= $identifyType( $arg, true, $identifyType );
                                }
                            }

                            $info = BetterErrorsReporter::syntaxHighlightFunction(
                                    isset($trace['class'])      ? $trace['class']       : null,
                                    isset($trace['type'])       ? $trace['type']        : null,
                                    isset($trace['function'])   ? $trace['function']    : null,
                                    $args
                            );
                        }

                        $trace['info'] = $info;

                        if ( isset($trace['file']) ) {
                            $file = $this->removeRootPath( $root, $trace['file'] );
                            $klass = '';

                            if ( strpos($file, '/') === false ) {
                                $klass = 'file-root';
                            } else {
                                $type = $this->getFolderType( $file );

                                if ( $type === BetterErrorsReporter::FILE_TYPE_IGNORE ) {
                                    $klass = 'file-ignore';
                                } else if ( $type === BetterErrorsReporter::FILE_TYPE_APPLICATION ) {
                                    $klass = 'file-app';
                                } else {
                                    $klass = 'file-common';
                                }
                            }

                            $trace['html_file'] = "<span class='$klass'>$file</span>";
                        } else {
                            $file = '[internal function]';
                            $trace['html_file'] = "<span class='file-internal'>$file</span>";
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
                        $line     = str_pad( $trace['line']     , $lineLen, ' ', STR_PAD_LEFT  );
                        $file     = $trace['html_file'] . str_pad( '', $fileLen-strlen($trace['file']), ' ', STR_PAD_LEFT );

                        $info = $trace['info'];
                        $info = str_replace( "\n", '\n', $info );
                        $info = str_replace( "\r", '\r', $info );

                        $stackStr = ( $info !== '' ) ?
                                "$line    $file    $info" :
                                "$line    $file" ;

                        if ( $highlightI === $i ) {
                            $stackStr = "<div class='highlight'>$stackStr</div>";
                        } else if ( $highlightI > $i ) {
                            $stackStr = "<div class='pre-highlight'>$stackStr</div>";
                        } else {
                            $stackStr = "<div>$stackStr</div>";
                        }

                        $stackTrace[$i] = $stackStr;
                    }
                }

                return join( "", $stackTrace );
            } else {
                return null;
            }
        }

        /**
         * The entry point for handling an error.
         */
        public function reportError( $code, $message, $errLine, $errFile, $stackTrace=null, $ex=null ) {
            $root = $this->documentRoot;

            list( $message, $srcErrFile, $srcErrLine, $altInfo ) =
                    $this->improveErrorMessage( $ex, $code, $message, $errLine, $errFile, $root, $stackTrace );
            $fileLines  = $this->readCodeFile( $srcErrFile, $srcErrLine );

            $errFile = $srcErrFile;
            $errLine = $srcErrLine;

            $errFile    = $this->removeRootPath( $root, $errFile );
            $stackTrace = $this->parseStackTrace( $code, $message, $errLine, $errFile, $stackTrace, $root, $altInfo );

            $this->displayError( $message, $srcErrLine, $errFile, $stackTrace, $fileLines );
        }

        /*
         * Now the actual hooking into PHP's error reporting.
         * 
         * We enable _ALL_ errors, and make them all exceptions.
         * We also need to hook into the shutdown function so
         * we can catch fatal and compile time errors.
         */
        private function attachErrorHandles() {
            if ( $this->isOff() ) {
                error_reporting( $this->defaultErrorReportingOff );
            } else {
                $self = $this;

                // all errors \o/ !
                error_reporting( $this->defaultErrorReportingOn );

                set_error_handler(
                        function( $code, $message, $file, $line, $context ) use ( $self ) {
                            /*
                             * DO NOT! log the error.
                             * 
                             * Either it's thrown as an exception, and so logged by the exception handler,
                             * or we return false, and it's logged by PHP.
                             */
                            if ( $self->isOn() ) {
                                /*
                                 * When using an @, the error reporting drops to 0.
                                 */
                                if ( error_reporting() !== 0 || $this->catchSurpressedErrors ) {
                                    throw new ErrorToExceptionException( $code, $message, $file, $line, $context );
                                }
                            } else {
                                return false;
                            }
                        },
                        $this->defaultErrorReportingOn 
                );

                set_exception_handler( function($ex) use ( $self ) {
                    if ( $self->isOn() ) {
                        $file = $ex->getFile();
                        $line = $ex->getLine();
                        $message = $ex->getMessage();

                        $trace = $ex->getTraceAsString();
                        $parts = explode( "\n", $trace );
                        $trace = "        " . join( "\n        ", $parts );

                        error_log( "$message \n           $file, $line \n$trace" );

                        $self->reportError( $ex->getCode(), $message, $line, $file, $ex->getTrace(), $ex );
                    } else {
                        return false;
                    }
                });

                if ( ! $self->isShutdownRegistered ) {
                    if ( $self->catchClassNotFound ) {
                        spl_autoload_register( function($className) use ( $self ) {
                            if ( $self->isOn() ) {
                                throw new ErrorToExceptionException( E_ERROR, "Class '$className' not found", __FILE__, __LINE__ );
                            }
                        } );
                    }

                    register_shutdown_function( function() use ( $self ) {
                        if ( $self->isOn() ) {
                            $error = error_get_last();

                            // fatal and syntax errors
                            if (
                                    $error && (
                                            $error['type'] === 1 ||
                                            $error['type'] === 4
                                    )
                            ) {
                                $self->reportError( $error['type'], $error['message'], $error['line'], $error['file'] );
                            }
                        }
                    });

                    $self->isShutdownRegistered = true;
                }
            }
        }

        /**
         * The actual display logic.
         * This outputs the error details in HTML.
         */
        private function displayError( $message, $errLine, $errFile, $stackTrace, &$fileLines ) {
            $documentRoot   = $this->documentRoot;
            $serverName     = $this->serverName;
            $backgroundText = $this->backgroundText;
            
            \better_error_reporting\displayHTML(
                    // pre, in the head
                    function() use( $message, $errFile, $errLine ) {
                            echo "<!--\n" .
                                    "$message\n" .
                                    "$errFile, $errLine\n" .
                                "-->";
                    },

                    // the content
                    function() use (
                            $backgroundText, $serverName, $documentRoot,
                            $message, $errLine, $errFile, $stackTrace, &$fileLines
                    ) {
                        if ( $backgroundText ) { ?>
                            <div id="error-wrap">
                                <div id="error-back"><?= $backgroundText ?></div>
                            </div>
                        <? } ?>
                        <h2 id="error-file-root"><?= $serverName ?> | <?= $documentRoot ?></h2>
                        <h1 id="error-title"><?= $message ?></h1>
                        <h2 id="error-file" class="<?= $fileLines ? 'has_code' : '' ?>"><?= $errFile ?>, <?= $errLine ?></h2>
                        <? if ( $fileLines ) { ?>
                            <ul id="error-file-lines">
                                <? foreach ( $fileLines as $lineNum => $line ) { ?>
                                    <li class="error-file-line <?= ($lineNum === $errLine) ? 'highlight' : '' ?>"><?= $line ?></li>
                                <? } ?>
                            </ul>
                        <? } ?>
                        <? if ( $stackTrace !== null ) { ?>
                            <div id="error-stack-trace"><?= $stackTrace ?></div>
                        <? }
                    }
            );
        }
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
    function displayHTML( \Closure $head, $body=null ) {
        if ( func_num_args() === 1 ) {
            $body = $head;
            $head = null;
        }

        // clean out anything displayed already
        try {
            ob_end_clean();
            ob_start("ob_gzhandler");
        } catch ( Exception $ex ) { /* do nothing */ }

        echo '<!DOCTYPE html>';

        if ( $head !== null ) {
            $head();
        }

        ?><style>
            html, body { margin: 0; padding: 0; }
            body {
                width: 100%;
                height: 100%;
                padding: 16px 32px;
                -moz-box-sizing: border-box;
                box-sizing: border-box;

                background: #111;
                color: #f0f0f0;
            }

            ::-moz-selection{background: #662039 !important; color: #fff !important; text-shadow: none;}
            ::selection {background: #662039 !important; color: #fff !important; text-shadow: none;} 

            a,
            a:visited,
            a:hover,
            a:active {
                color: #9ae;
                text-decoration: undefine;
            }
            a:hover {
                color: #aff;
            }

            h1 {
                font: 32px consolas;
                margin-bottom: 0;
            }
            h2 {
                font: 24px consolas;
                margin-top: 0;
            }
            #error-stack-trace {
                font: 18px consolas;
                line-height: 28px;
                white-space: pre;

                cursor: pointer;
            }
            #error-file.has_code {
                margin: 36px 0 -6px 128px;
            }
            #error-file-root {
                color: #555;
            }
            #error-file-lines {
                margin-left  : 128px;
                margin-bottom: 38px;
                padding: 0 18px 9px 0;
                display: inline-block;
            }
                .error-file-line {
                    font: 16px consolas;
                    color: #ddd;
                    white-space: pre;
                    list-style-type: none;
                    /* needed for empty lines */
                    min-height: 20px;
                }
                .pre-highlight,
                .highlight {
                    width: 100%;
                    color: #eee;
                }
                .pre-highlight {
                    opacity: 0.3;
                    color: #999 !important;
                }
                    .pre-highlight span {
                        color: #999 !important;
                        border: none !important;
                    }
                .highlight {
                    background: #391818;
                    box-shadow: 0 0 6px #301010;
                    border-radius: 2px;
                    padding-bottom: 1px;
                }
            .syntax-class {
                color: #C07041;
            }
            .syntax-string {
                color: #7C9D5D;
            }
            .syntax-literal {
                color: #cF5d33;
            }
            .syntax-variable-not-important {
                opacity: 0.5;
            }
            .syntax-higlight-variable {
                color: #f00;
                border-bottom: 3px dashed #c33;
            }
            .syntax-variable {
                color: #798aA0;
            }
            .syntax-keyword {
                color: #C07041;
            }
            .syntax-function {
                color: #F9EE98;
            }
            .syntax-comment {
                color: #5a5a5a;
            }

            .file-internal {
                color: #555;
            }
            .file-common {
                color: #eb4;
            }
            .file-ignore {
                color: #585;
            }
            .file-app {
                color: #66c6d5;
            }
            .file-root {
                color: #b69;
            }

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
                font: 240px consolas;
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
        </style><?

        $body();
    }

    /**
     * A thin class that wraps up an error, into an exception.
     */
    class ErrorToExceptionException extends Exception
    {
        public function __construct( $code, $message, $file, $line )
        {
            parent::__construct( $message, $code );

            $this->file = $file;
            $this->line = $line;
        }
    }
