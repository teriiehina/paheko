<?php
/*
    Copyright (c) 2001-2024 BohwaZ <http://bohwaz.net/>
    All rights reserved.

    This is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with This.  If not, see <https://www.gnu.org/licenses/>.
*/
namespace KD2;

use KD2\Translate;

/**
 * ### Brindille is a simple, lightweight, but powerful template engine
 *
 * This is inspired mainly by Smarty, Moustache, Handlebars, Twig, and Blade.
 *
 * This is designed to be used mainly a HTML-based programming language.
 *
 * This does not allow execution of raw PHP code, but instead you have to
 * place your code inside double brackets. A piece of code surrounded by
 * double brackets is called a 'tag'.
 *
 * A tag can either be used to:
 * - display a variable, in that case the tag will begin with a `$` => `{{$form.name}}`
 * - run a function, the tag will begin with a colon: `:` => `{{:include file="header.html"}}`
 * - execute a loop (called section), the tag will begin with a hash => `{{#articles}}...{{/articles}}`
 *   (sections use generators under the hood)
 * - execute a condition, using the `if`, `elseif` and `else` words: `{{if $form.id == 42}}...{{else}}...{{/if}}`
 * - any other use you might like, as the language can be extended during compilation
 *
 * Brindille relies on those types of callbacks to extend its core features:
 * - modifiers: they are used to modify a variable or a value => `{{$name|replace:"Mr":"M."}}...{{"me\nyou"|nl2br}}`
 * - functions: used when a function call (colon) is used
 * - sections: used when a section call (hash) is used
 * - blocks: used when none of the other prefixes are present. This is used to extend the language at compile time.
 *
 * Brindille features some security features:
 * - no execution of PHP code is allowed
 * - compiled files used for cache cannot be executed outside of the Brindille object
 * - variables are automatically escaped, unless the `|raw` modifier is used
 * - by default, zero modifiers, functions or sections are available
 * - you can use ->registerDefaults() to implement a basic set of modifiers,
 *   the assign function and the foreach section
 *
 * Most of Brindille internal methods are public. This is by design, so that any modifier,
 * function, section or block callback may extend the engine easily.
 *
 * #### Other template tags
 *
 * Comments begin with `{{*` and end with `*}}`.
 * Literal blocks, where no tags should be parsed, begin with `{{literal}}` and end with `{{/literal}}`.
 *
 * #### Variables levels
 *
 * In Brindille, variables are set in a specific level (or context). This is mostly used in sections.
 * A section may create new variables, that will only exist inside the section.
 *
 * This means that if a section creates a `$url` variable, this will not overwrite an existing
 * `$url` variable. This is the same as using `let` in javascript.
 *
 * ```
 * {{:assign name="Ada"}}
 * {{:assign var="array" 0="James"}}
 * {{#foreach from=$array item="name"}}
 *   {{$name}}
 * {{/foreach}}
 * {{$name}}
 * ```
 *
 * This will display: `James Ada`.
 *
 * All variables registered with the `assign` function will be registered in root level (zero).
 *
 * #### What's missing
 *
 * - parenthesis in if/elseif statements: this is not likely to be added
 *
 * @author bohwaz <https://bohwaz.net/>
 */
class Brindille
{
	// Tag types
	const NONE = 0;
	const LITERAL = 1;
	const SECTION = 10;
	const IF = 11;
	const ELSE = 12;

	// Tokenizer types
	const T_VAR = 'var';
	const T_PARAMS = 'params';
	const T_SPACE = 'space';
	const T_SCALAR = 'scalar';
	const T_OPERATOR = 'operator';
	const T_ANDOR = 'andor';
	//const T_OPEN_PARENTHESIS = 'open';
	//const T_CLOSE_PARENTHESIS = 'close';

	/**
	 * Regexp for allowed variable names (basically the same as PHP, without the unicode)
	 */
	const RE_VALID_VARIABLE_NAME = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

	/**
	 * Regexp for literal expressions:
	 * $var.subvar , "quoted string even with \" escape quotes", 'even single quotes'
	 */
	const RE_LITERAL = '\$[\w.]+|"(?:.*?(?<!\\\\))"|\'(?:.*?(?<!\\\\))\'';

	/**
	 * Scalar expressions: null, true, false, integers, floats
	 */
	const RE_SCALAR = 'null|true|false|-?\d+|-?\d+\.\d+';

	/**
	 * Modifier arguments. The separator between modifier arguments is the colon.
	 * :"string"
	 * :$variable.subvar
	 * :42
	 * :false
	 * :null
	 */
	const RE_MODIFIER_ARGUMENTS = '(?::(?:' . self::RE_LITERAL . '|' . self::RE_SCALAR . '))*';

	/**
	 * Modifier syntax
	 * |mod_name:arg1:arg2
	 */
	const RE_MODIFIER = '\|\w+' . self::RE_MODIFIER_ARGUMENTS;

	/**
	 * Variable syntax
	 * $var_name|modifier
	 * "string literal"|modifier:arg1,arg2
	 */
	const RE_VARIABLE = '(?:' . self::RE_LITERAL . ')(?:' . self::RE_MODIFIER . ')*';

	/**
	 * Tag parameters
	 */
	const RE_PARAMETERS = '[:\w]+=(?:' . self::RE_VARIABLE . '|' . self::RE_SCALAR . ')';

	/**
	 * Space
	 */
	const RE_SPACE = '\s+';

	// Tokens allowed in an if statement
	const TOK_IF_BLOCK = [
		self::T_OPERATOR => '(?:>=|<=|===|!==|==|!=|>|<|!)',
		self::T_ANDOR => '(?:&&|\|\|)',
		self::T_VAR => self::RE_VARIABLE,
		self::T_SCALAR => self::RE_SCALAR,
		self::T_SPACE => self::RE_SPACE,
	];

	const TOK_VAR_BLOCK = [
		self::T_VAR => self::RE_VARIABLE,
		self::T_PARAMS => self::RE_PARAMETERS,
		self::T_SPACE => self::RE_SPACE,
	];

	const PARSE_PATTERN = '%
		# start of block
		\{\{
		# ignore spaces at start of block
		\s*
		# capture block type/name
		(if|else\s?if|else|endif|literal|/literal|
		# sections, variables, functions, MUST have a valid name
		[:$#/]([\w._]+)|
		# quoted strings can be chained to modifiers as well
		[\'"]
		# end of capture group
		)
		# Arguments etc.
		((?!\}\}).*?)?
		# end of block
		\}\}
		# regexp modifiers
		%sx';

	/**
	 * Current stack of sections/if/elseif sections
	 */
	public array $_stack = [];

	/**
	 * List of registered sections
	*/
	protected array $_sections = [];

	/**
	 * List of registered modifiers
	 */
	protected array $_modifiers = ['escape' => null];

	/**
	 * List of registered modifiers where first parameter is the Brindille object
	 */
	protected array $_modifiers_with_instance = [];

	/**
	 * List of registered functions
	 */
	protected array $_functions = [];

	/**
	 * List of registered compile blocks
	 */
	protected array $_blocks = [];

	/**
	 * Variables stack
	 * There's always a root level (zero)
	 */
	public array $_variables = [0 => []];

	/**
	 * Register default modifiers:
	 * - escape
	 * - args
	 * - nl2br
	 * - strip_tags
	 * - count
	 * - cat
	 * - date_format
	 *
	 * And the 'foreach' section, as well as the 'assign' function.
	 */
	public function registerDefaults()
	{
		$this->registerFunction('assign', [self::class, '__assign']);

		// This is because PHP 8.1 sucks (string functions no longer accept NULL)
		// so we need to force NULLs as strings
		$this->registerModifier('escape', function ($str) {
			if (is_scalar($str) || is_null($str)) {
				return htmlspecialchars((string)$str);
			}
			else {
				return '<span style="color: #000; background: yellow; padding: 5px; white-space: pre-wrap; display: inline-block; font-family: monospace;">Error: cannot escape this value!<br />'
					. htmlspecialchars(print_r($str, true)) . '</span>';
			}
		});

		$this->registerModifier('args', 'sprintf');
		$this->registerModifier('nl2br', 'nl2br');
		$this->registerModifier('strip_tags', 'strip_tags');
		$this->registerModifier('count', function ($var) {
			if (is_countable($var)) {
				return count($var);
			}

			return null;
		});
		$this->registerModifier('cat', function() { return implode('', func_get_args()); });

		$this->registerModifier('date_format', function ($date, $format = '%d/%m/%Y %H:%M') {
			return Translate::strftime($format, $date);
		});

		$this->registerSection('foreach', [self::class, '__foreach']);
	}

	/**
	 * Assign a variable to the template
	 * @param  string $key The name of the variable
	 * @param  mixed $value The value of the variable
	 * @param  int|null $level Which level the variable should be assigned to
	 * @param  bool $throw_on_invalid_name Set to NULL to throw an error if the variable name is invalid
	 * @return void
	 */
	public function assign(string $key, $value, ?int $level = null, bool $throw_on_invalid_name = true): void
	{
		if (!preg_match(self::RE_VALID_VARIABLE_NAME, $key)) {
			if ($throw_on_invalid_name) {
				throw new \InvalidArgumentException('Invalid variable name: ' . $key);
			}

			// For assign from a section, don't throw an error, just ignore

			return;
		}

		if (!count($this->_variables)) {
			$this->_variables = [0 => []];
		}

		if (null === $level) {
			$level = count($this->_variables)-1;
		}

		if (count($this->_variables) > 100) {
			throw new \Exception('Recursive limit reached');
		}

		$this->_variables[$level][$key] = $value;
	}

	public function assignArray(array $array, ?int $level = null, bool $throw_on_invalid_name = true): void
	{
		foreach ($array as $key => $value) {
			$this->assign($key, $value, $level, $throw_on_invalid_name);
		}
	}

	public function checkModifierExists(string $name): bool
	{
		return array_key_exists($name, $this->_modifiers)
			|| array_key_exists($name, $this->_modifiers_with_instance);
	}

	/**
	 * Register a modifier function
	 *
	 * By default, callbacks are called with the passed parameters exactly.
	 * This means you can use PHP functions directly:
	 * `->registerModifier('nl2br', 'nl2br')` will mean that `{{$text|escape|nl2br}}`
	 * will call `nl2br($text)`.
	 * But in some cases you may want to access the template context.
	 * In that case, set the third argument to TRUE, and your modifier will have the
	 * current Brindille object as the first parameter, the line number as the second parameter,
	 * and the passed parameters following:
	 * `->registerModifier('nl2br', [Utils::class, 'nl2br'])` will mean that `{{$text|escape|nl2br:true}}`
	 * will call `Utils::nl2br(Brindille $tpl, int $line, $text, true)`.
	 *
	 * @param  string $name Name of the modifier
	 * @param  callable $callback A valid callback
	 * @param  bool $pass_instance_as_first_argument Set to TRUE if you want to have access
	 * to the template context from within the modifier
	 */
	public function registerModifier(string $name, callable $callback, bool $pass_instance_as_first_argument = false): void
	{
		unset($this->_modifiers_with_instance[$name], $this->_modifiers[$name]);

		if ($pass_instance_as_first_argument) {
			$this->_modifiers_with_instance[$name] = $callback;
		}
		else {
			$this->_modifiers[$name] = $callback;
		}
	}

	public function registerSection(string $name, callable $callback): void
	{
		$this->_sections[$name] = $callback;
	}

	public function registerFunction(string $name, callable $callback): void
	{
		$this->_functions[$name] = $callback;
	}

	public function registerCompileBlock(string $name, callable $callback): void
	{
		$this->_blocks[$name] = $callback;
	}

	/**
	 * Compile, execute, capture and return a string of Brindille code
	 */
	public function render(string $tpl_code): string
	{
		$code = $this->compile($tpl_code);

		try {
			ob_start();

			eval('?>' . $code);

			return ob_get_clean();
		}
		catch (\Throwable $e) {
			$lines = explode("\n", $code);
			$code = $lines[$e->getLine()-1] ?? $code;
			throw new Brindille_Exception(sprintf("[%s] Line %d: %s\n%s", get_class($e), $e->getLine(), $e->getMessage(), $code), 0, $e);
		}
	}

	/**
	 * Render some Brindille code, using a local compile file cache.
	 *
	 * When the code supplied by $source_callback will be compiled the first time,
	 * it will be stored as PHP code in $compiled_path.
	 *
	 * Next calls will avoid re-compiling the template, unless the cache has been
	 * generated before the timestamp passed in $expiry.
	 *
	 * This will display the template contents directly! Use ob_start() / ob_get_clean()
	 * to retrieve the template contents in a string.
	 *
	 * @param  callable $source_callback Callback function that will return
	 * the Brindille code as a string
	 * @param  string   $compiled_path   Path to the compiled cache file,
	 * parent directories will be created
	 * @param  int|null $expiry          Expiration (UNIX timestamp in seconds)
	 * of the compiled cache file
	 * @return mixed Will return whatever the executed template code returned.
	 * This will be 1 by default, see https://www.php.net/manual/en/function.include.php
	 */
	public function displayUsingCache(callable $source_callback, string $compiled_path, ?int $expiry = null)
	{
		// Create parent directory if required, with correct permissions
		$root = dirname($compiled_path);
		$parent = $root;

		while (!is_dir($parent)) {
			if (file_exists($parent)) {
				throw new \LogicException('Parent directory exists and is not a directory: ' . $parent);
			}

			$parent = dirname($parent);
		}

		if ($root !== $parent) {
			@mkdir($root, fileperms($parent), true);
			$exists = false;
		}
		else {
			$exists = file_exists($compiled_path);
		}

		if ($exists && $expiry && filemtime($compiled_path) < $expiry) {
			$exists = false;
		}

		if ($exists) {
			return include($compiled_path);
		}

		// Store compiled file in temporary file, we will rename it when execution is OK
		$tmp_path = $compiled_path . '.tmp';

		try {
			// Stop execution if not in the context of Brindille
			// this is to avoid potential execution of template code outside of Brindille
			$prefix = '<?php if (!isset($this) || !is_object($this) || (!($this instanceof \KD2\Brindille) && !is_subclass_of($this, \'\KD2\Brindille\', true))) { die("Wrong call context."); } ?>';

			// Call source callback to return the Brindille source code
			$source = call_user_func($source_callback);

			// Compile Brindille into PHP code
			$code = $this->compile($source);
			$code = $prefix . $code;

			// Save code to temporary cache
			file_put_contents($tmp_path, $code);

			// Execute compiled code
			$return = include($tmp_path);

			// Rename to final compiled cache file
			@rename($tmp_path, $compiled_path);
		}
		finally {
			@unlink($tmp_path);
		}

		return $return;
	}

	/**
	 * Compile the source code passed as the string, to a PHP code string.
	 * The resulting compiled string may generate syntax errors.
	 */
	public function compile(string $code): string
	{
		$this->_stack = [];

		// Remove PHP tags
		$code = strtr($code, [
			'<?' => '<?=\'<?\'?>',
			'?>' => '<?=\'?>\'?>'
		]);

		// Remove PHP tags that can be cut by Brindille code, eg.
		// <{{literal}}? or <{{**lol**}}?php
		$code = preg_replace('!<(\{\{.*?\}\})\?!s', '<?=\'<\'?>$1<?=\'?\'?>', $code);
		$code = preg_replace('!\?(\{\{.*?\}\})>!s', '<?=\'?\'?>$1<?=\'>\'?>', $code);

		$keep_whitespaces = false !== strpos($code, '{{**keep_whitespaces**}}');

		// Remove comments, but do not affect the number of lines
		$code = preg_replace_callback('/\{\{\*(?:(?!\*\}\}).*?)\*\}\}/s', function ($match) {
			return '<?php /* ' . str_repeat("\n", substr_count($match[0], "\n")) . '*/ ?>';
		}, $code);

		$return = preg_replace_callback(self::PARSE_PATTERN, function ($match) use ($code) {
			$offset = $match[0][1];
			$line = 1 + substr_count($code, "\n", 0, $offset);

			try {
				$all = $match[0][0];
				$start = !empty($match[2][0]) ? substr($match[1][0], 0, 1) : $match[1][0];
				$name = $match[2][0] ?? $match[1][0];
				$params = $match[3][0] ?? null;

				return $this->_walk($all, $start, $name, $params, $line);
			}
			catch (Brindille_Exception $e) {
				throw new Brindille_Exception(sprintf('Line %d: %s', $line, $e->getMessage()), 0, $e);
			}
		}, $code, -1, $count, PREG_OFFSET_CAPTURE);

		if (count($this->_stack)) {
			$line = 1 + substr_count($code, "\n");
			throw new Brindille_Exception(sprintf('Line %d: missing closing tag "%s"', $line, $this->_lastName()));
		}

		// Remove comments altogether
		$return = preg_replace('!<\?php /\*.*?\*/ \?>!s', '', $return);

		if ($keep_whitespaces) {
			$return = str_replace(["\r\n", "\r"], "\n", $return);
			// Keep whitespaces, but PHP is eating the line break after a closing tag, so double it
			$return = str_replace("?>\n", "?>\n\n", $return);
		}
		else {
			// Remove whitespaces between PHP logic blocks (not echo blocks)
			// this is to avoid sending data to browser in logic code, eg. redirects
			$return = preg_replace('!\s\?>(\s+)<\?php\s!', ' $1 ', $return);
		}

		return $return;
	}

	/**
	 * Try to find a variable from all levels, beginning with last levels
	 */
	public function get(string $name)
	{
		$array =& $this->_variables;

		for ($vars = end($array); key($array) !== null; $vars = prev($array)) {
			// Dots at the start of a variable name mean: go back X levels in variable stack
			if (substr($name, 0, 1) == '.') {
				$name = substr($name, 1);
				continue;
			}

			if (array_key_exists($name, $vars)) {
				return $vars[$name];
			}

			$found = false;

			if (strstr($name, '.')) {
				$return = $this->_magic($name, $vars, $found);

				if ($found) {
					return $return;
				}
			}
		}

		return null;
	}

	/**
	 * Return all assigned variables, as a flat array
	 */
	public function getAllVariables(): array
	{
		$out = [];

		foreach ($this->_variables as $vars) {
			$out = array_merge($out, $vars);
		}

		return $out;
	}

	/**
	 * Magical parser of variables names
	 *
	 * Variable names can point to an object constant or property, or an array key.
	 *
	 * This will return the value of the variable path from the $var value,
	 * and set $found to TRUE or FALSE, as the found value can be NULL.
	 *
	 * This will return NULL if the value is not found. Meaning in Brindille, there is
	 * no "undefined value", and no distinction between a non-existing variable and
	 * a NULL variable.
	 *
	 * {{$array.1}}
	 * {{$array.index_name.sub_index_name}}
	 * {{$object.CONSTANT}}
	 * {{$object.property}}
	 */
	protected function _magic(string $expr, $var, &$found = null)
	{
		static $call = 0;

		if ($call > 999999) {
			throw new \LogicException('Call limit for magic variable finding has been reached, check for recursivity issues');
		}

		$call++;
		$i = 0;

		// Expression ending with a dot is invalid and will return NULL
		if (substr($expr, -1) === '.') {
			$found = false;
			return null;
		}

		$key = strtok($expr, '.');

		do {
			if ($i++ > 20) {
				// Limit the amount of recusivity we can go through
				$found = false;
				strtok('');
				return null;
			}

			if (is_object($var)) {
				// Test for constants
				if (defined(get_class($var) . '::' . $key)) {
					$found = true;
					strtok('');
					return constant(get_class($var) . '::' . $key);
				}

				// Test for properties
				if (!property_exists($var, $key)) {
					$found = false;
					strtok('');
					return null;
				}

				$var = $var->$key;
			}
			elseif (is_array($var)) {
				if (!array_key_exists($key, $var)) {
					$found = false;
					strtok('');
					return null;
				}

				$var = $var[$key];
			}
		}
		while (false !== ($key = strtok('.')));
		strtok('');

		$found = true;
		return $var;
	}

	/**
	 * Push a new section/if/elseif/else item in the stack
	 */
	public function _push(int $type, ?string $name = null, ?array $params = null): void
	{
		$this->_stack[] = func_get_args();
	}

	/**
	 * Remove last item from stack
	 */
	public function _pop(): ?array
	{
		return array_pop($this->_stack);
	}

	/**
	 * Return type of last item in stack
	 */
	public function _lastType(): int
	{
		return count($this->_stack) ? end($this->_stack)[0] : self::NONE;
	}

	/**
	 * Return name of last item in stack
	 */
	public function _lastName(): ?string
	{
		if ($this->_stack) {
			return end($this->_stack)[1];
		}

		return null;
	}

	/**
	 * Return block type/name/extra params, if block type/name is found in parent stack
	 */
	public function _getStack(int $type, ?string $name = null): ?array
	{
		foreach ($this->_stack as $item) {
			if ($item[0] !== $type) {
				continue;
			}

			if ($name === null || $name === $item[1]) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * This is the main compile function, that will walk all tags
	 */
	protected function _walk(string $all, ?string $start, string $name, ?string $params, int $line): string
	{
		if ($name == 'literal') {
			$this->_push(self::LITERAL, $name);
			return '';
		}
		elseif ($start == '/literal') {
			if ($this->_lastType() != self::LITERAL) {
				throw new Brindille_Exception('closing of a literal block that wasn\'t opened');
			}

			$this->_pop();
			return '';
		}
		elseif ($this->_lastType() == self::LITERAL) {
			return $all;
		}

		$params = trim((string) $params);

		// Variable
		if ($start == '$') {
			return sprintf('<?=%s?>', $this->_variable('$' . $name . $params, true, $line));
		}

		if ($start == '"' || $start == '\'') {
			return sprintf('<?=%s?>', $this->_variable($start . $name . $params, true, $line));
		}

		if ($start == '#' && array_key_exists($name, $this->_sections)) {
			return $this->_section($name, $params, $line);
		}
		elseif ($start == 'if') {
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'if', $line);
		}
		elseif ($start == 'elseif') {
			if ($this->_lastType() != self::IF) {
				throw new Brindille_Exception('"elseif" block is not following a "if" block');
			}

			$this->_pop();
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'elseif', $line);
		}
		elseif ($start == 'else') {
			return $this->_else($line);
		}
		elseif (array_key_exists($start . $name, $this->_blocks) && substr($name, 0, 5) !== 'else:') {
			return $this->_block($start . $name, $params, $line);
		}
		elseif ($start == '/') {
			return $this->_close($name, $all);
		}
		elseif ($start == ':' && array_key_exists($name, $this->_functions)) {
			return $this->_function($name, $params, $line);
		}

		throw new Brindille_Exception('Unknown block: ' . $all);
	}

	/**
	 * Call a modifier from inside compiled code
	 */
	public function callModifier(string $name, int $line, ... $params) {
		if (!$this->checkModifierExists($name)) {
			throw new Brindille_Exception('This modifier does not exist: ' . $name);
		}

		try {
			if (array_key_exists($name, $this->_modifiers)) {
				$callback = $this->_modifiers[$name];

				// If auto-escaping is disabled, just return the first argument
				if (null === $callback && $name === 'escape') {
					return $params[0] ?? null;
				}

				return $callback(...$params);
			}
			elseif (isset($this->_modifiers_with_instance[$name])) {
				return $this->_modifiers_with_instance[$name]($this, $line, ...$params);
			}
		}
		catch (\Exception | \ArgumentCountError | \ValueError | \TypeError | \ArgumentCountError | \DivisionByZeroError $e) {
			$message = preg_replace('/in\s+.*?\son\sline\s\d+|to\s+function\s+.*?,/', '', $e->getMessage());
			throw new Brindille_Exception(sprintf("line %d: modifier '%s' has returned an error: %s\nParameters: %s", $line, $name, $message, json_encode($params)), 0, $e);
		}

		return null;
	}

	/**
	 * Return PHP code for a function tag
	 */
	public function _function(string $name, string $params, int $line): string
	{
		if (!isset($this->_functions[$name])) {
			throw new Brindille_Exception(sprintf('line %d: unknown function "%s"', $line, $name));
		}

		$params = $this->_parseArguments($params, $line);
		$params = $this->_exportArguments($params);

		return sprintf('<?=$this->_callFunction(%s, %s, %d)?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	/**
	 * Call a function from inside the generated PHP code of a template
	 */
	public function _callFunction(string $name, array $params, int $line)
	{
		try {
			return call_user_func($this->_functions[$name], $params, $this, $line);
		}
		catch (\Exception $e) {
			throw new Brindille_Exception(sprintf("line %d: function '%s' has returned an error: %s\nParameters: %s", $line, $name, $e->getMessage(), json_encode($params)));
		}
	}

	/**
	 * Return PHP code for a section tag
	 */
	public function _section(string $name, string $params, int $line): string
	{
		$this->_push(self::SECTION, $name);

		if (!isset($this->_sections[$name])) {
			throw new Brindille_Exception(sprintf('line %d: unknown section "%s"', $line, $name));
		}

		$params = $this->_parseArguments($params, $line);
		$params = $this->_exportArguments($params);

		return sprintf('<?php unset($last); $i = call_user_func($this->_sections[%s], %s, $this, %d); $i ??= []; foreach ($i as $key => $value): $this->_variables[] = []; $this->assignArray(array_merge($value, [\'__\' => $value, \'_\' => $key]), null, false); ?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	/**
	 * Return PHP code for a compile block tag
	 */
	public function _block(string $name, string $params, int $line): string
	{
		if (!isset($this->_blocks[$name])) {
			throw new Brindille_Exception(sprintf('unknown section "%s"', $name));
		}

		return call_user_func($this->_blocks[$name], $name, $params, $this, $line);
	}

	/**
	 * Return PHP code for 'if' tag
	 */
	public function _if(string $name, string $params, string $tag_name, int $line): string
	{
		try {
			$tokens = self::tokenize($params, self::TOK_IF_BLOCK);
		}
		catch (\InvalidArgumentException $e) {
			throw new Brindille_Exception(sprintf('Error in "if" block: %s', $e->getMessage()));
		}

		$code = '';
		$count = count($tokens);

		foreach ($tokens as $i => $token) {
			$prev = null;
			$next = null;

			for ($j = $i - 1; $j >= 0; $j--) {
				$prev = $tokens[$j];

				// Skip spaces
				if ($prev->type === self::T_SPACE) {
					continue;
				}

				break;
			}

			for ($j = $i + 1; $j < $count; $j++) {
				$next = $tokens[$j];

				// Skip spaces
				if ($next->type !== self::T_SPACE) {
					break;
				}
			}

			// Validate if condition: a scalar or variable can only follow a non-scalar/variable
			if ($token->type === self::T_SCALAR || $token->type === self::T_VAR) {
				if ($prev && ($prev->type === self::T_SCALAR || $prev->type === self::T_VAR)) {
					throw new Brindille_Exception(sprintf('Error in "if" block: unexpected "%s" after "%s" at position %d', $token->value, $prev->value, $token->offset));
				}
			}
			elseif ($token->type === self::T_OPERATOR && $token->value === '!') {
				if (!$next || ($next->type !== self::T_VAR && $next->type !== self::T_SCALAR)) {
					throw new Brindille_Exception(sprintf('Error in "if" block: unexpected operator "%s" before "%s" at position %d', $token->value, $prev->value, $token->offset));
				}
			}
			// a non-scalar/variable can only follow a variable/scalar value
			// eg. "$var && $var === 1" is correct, but "$var && && 1" is not
			elseif ($token->type !== self::T_SPACE) {
				if ($prev && !($prev->type === self::T_SCALAR || $prev->type === self::T_VAR)) {
					throw new Brindille_Exception(sprintf('Error in "if" block: unexpected "%s" after "%s" at position %d', $token->value, $prev->value, $token->offset));
				}
			}

			if ($token->type === self::T_VAR) {
				$code .= $this->_variable($token->value, false, $line);
			}
			else {
				$code .= $token->value;
			}
		}

		if (empty($code)) {
			throw new Brindille_Exception('No condition given');
		}

		return sprintf('<?php %s (%s): ?>', $tag_name, $code);
	}

	/**
	 * Return PHP code for a 'else' tag
	 * Note: 'else' blocks can follow either a 'if', a 'elseif', or a section tag.
	 * A section generator that didn't create any iteration will trigger the following 'else'.
	 */
	public function _else(int $line): string
	{
		$type = $this->_lastType();

		if ($type != self::IF && $type != self::SECTION) {
			throw new Brindille_Exception('"else" block is not following a "if" or section block');
		}

		$name = $this->_lastName();
		$this->_pop();
		$this->_push(self::ELSE, $name);

		if (isset($this->_blocks['else:' . $name])) {
			return $this->_block('else:' . $name, '', $line);
		}
		elseif ($type == self::SECTION) {
			return '<?php $last = array_pop($this->_variables); endforeach; if (!isset($last) || !count($last)): ?>';
		}
		else {
			return '<?php else: ?>';
		}
	}

	/**
	 * Close the current stack item, return PHP code
	 */
	public function _close(string $name, string $block): string
	{
		if ($this->_lastName() != $name) {
			// Logic error
			throw new Brindille_Exception(sprintf('"%s": block closing does not match last block "%s" opened', $block, $this->_lastName()));
		}

		$type = $this->_lastType();
		$this->_pop();

		if ($type == self::IF || $type == self::ELSE) {
			return '<?php endif; ?>';
		}
		else {
			return '<?php array_pop($this->_variables); endforeach; ?>';
		}
	}

	/**
	 * Parse a variable, either from a {{$block}} or from an argument: {{block arg=$bla|rot13}}
	 */
	public function _variable(string $raw, bool $escape, int $line): string
	{
		// Split by pipe (|) except if enclosed in quotes
		$modifiers = preg_split('/\|(?=(([^\'"]*["\']){2})*[^\'"]*$)/', $raw);
		$var = array_shift($modifiers);

		$pre = $post = '';

		if (count($modifiers))
		{
			$modifiers = array_reverse($modifiers);

			foreach ($modifiers as &$modifier)
			{
				$_post = '';

				$pos = strpos($modifier, ':');

				// Arguments
				if ($pos !== false)
				{
					$mod_name = trim(substr($modifier, 0, $pos));
					$raw_args = substr($modifier, $pos+1);

					// Split by two points (:) except if enclosed in quotes
					$arguments = preg_split('/\s*:\s*|("(?:\\\\.|[^"])*?"|\'(?:\\\\.|[^\'])*?\'|[^:\'"\s]+)/', trim($raw_args), 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
					$arguments = array_map([$this, '_exportArgument'], $arguments);

					$_post .= ', ' . implode(', ', $arguments);
				}
				else
				{
					$mod_name = trim($modifier);
				}

				// Disable autoescaping
				if ($mod_name == 'raw') {
					$escape = false;
					continue;
				}
				else if ($mod_name == 'escape') {
					$escape = false;
				}

				// Modifiers MUST be registered at compile time
				if (!$this->checkModifierExists($mod_name)) {
					throw new Brindille_Exception('Unknown modifier name: ' . $mod_name);
				}

				$post = $_post . ')' . $post;
				$pre .= '$this->callModifier(' . var_export($mod_name, true) . ', ' . $line . ', ';
			}
		}

		$var = $this->_exportArgument($var);

		$var = $pre . $var . $post;

		unset($pre, $post, $arguments, $mod_name, $modifier, $modifiers, $pos, $_post);

		// auto escape
		if ($escape)
		{
			$var = '$this->callModifier(\'escape\', ' . $line . ', ' . $var . ')';
		}

		return $var;
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 * @param  string $str List of arguments
	 * @param  integer $line Source code line
	 * @return array
	 */
	public function _parseArguments(string $str, int $line)
	{
		$args = [];
		$name = null;
		$state = 0;
		$last_value = '';

		preg_match_all('/(?:"(?:\\\\"|[^\"])*?"|\'(?:\\\\\'|[^\'])*?\'|(?>[^"\'=\s]+))+|[=]/i', $str, $match);

		foreach ($match[0] as $value) {
			if ($state == 0) {
				$name = $value;
			}
			elseif ($state == 1) {
				if ($value != '=') {
					throw new Brindille_Exception('Expecting \'=\' after \'' . $last_value . '\'');
				}
			}
			elseif ($state == 2) {
				if ($value == '=') {
					throw new Brindille_Exception('Unexpected \'=\' after \'' . $last_value . '\'');
				}

				$args[$name] = $this->_variable($value, false, $line);
				$name = null;
				$state = -1;
			}

			$last_value = $value;
			$state++;
		}

		unset($state, $last_value, $name, $str, $match);

		return $args;
	}

	/**
	 * Export a single argument as PHP code
	 */
	public function _exportArgument(string $raw_arg): string
	{
		// If it's a variable, call the get method
		if (substr($raw_arg, 0, 1) == '$') {
			return sprintf('$this->get(%s)', var_export(substr($raw_arg, 1), true));
		}

		return var_export($this->getValueFromArgument($raw_arg), true);
	}

	/**
	 * Export an array to a string, like var_export but without escaping of strings
	 *
	 * This is used to reference variables and code in arrays
	 *
	 * Warning: this is different than _exportArgument
	 *
	 * @param  array   $args      Arguments to export
	 * @return string
	 */
	public function _exportArguments(array $args): string
	{
		if (!count($args)) {
			return '[]';
		}

		$out = '[';

		foreach ($args as $key=>$value) {
			$out .= var_export($key, true) . ' => ' . $value . ', ';
		}

		$out = substr($out, 0, -2);

		$out .= ']';

		return $out;
	}

	/**
	 * Returns string value from a quoted or unquoted block argument
	 * @param  string $arg Extracted argument ({foreach from=$loop item="value"} => [from => "$loop", item => "\"value\""])
	 */
	public function getValueFromArgument(string $arg)
	{
		static $replace = [
			'\\"'  => '"',
			'\\\'' => '\'',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		];

		if (strlen($arg) && ($arg[0] == '"' || $arg[0] == "'"))
		{
			return strtr(substr($arg, 1, -1), $replace);
		}

		switch ($arg) {
			case 'true':
				return true;
			case 'false':
				return false;
			case 'null':
				return null;
			default:
				if (ctype_digit($arg)) {
					return (int)$arg;
				}

				return $arg;
		}
	}

	/**
	 * Tokenize a string following a list of regexps
	 * @see https://github.com/nette/tokenizer
	 * @return array a list of tokens, each is an object with a value, a type (the array index of $tokens) and the offset position
	 * @throws \InvalidArgumentException if an unknown token is encountered
	 */
	static public function tokenize(string $input, array $tokens): array
	{
		$pattern = '~(' . implode(')|(', $tokens) . ')~A';
		preg_match_all($pattern, $input, $match, PREG_SET_ORDER);

		$types = array_keys($tokens);
		$count = count($types);

		$len = 0;

		foreach ($match as &$token) {
			$type = null;

			for ($i = 1; $i <= $count; $i++) {
				if (!isset($token[$i])) {
					break;
				} elseif ($token[$i] !== '') {
					$type = $types[$i - 1];
					break;
				}
			}

			$token = (object) ['value' => $token[0], 'type' => $type, 'offset' => $len];
			$len += strlen($token->value);
		}

		if ($len !== strlen($input)) {
			$text = substr($input, 0, $len);
			$line = substr_count($text, "\n") + 1;
			$col = $len - strrpos("\n" . $text, "\n") + 1;
			$token = str_replace("\n", '\n', substr($input, $len, 10));

			throw new \InvalidArgumentException("Unexpected '$token' on line $line, column $col");
		}

		return $match;
	}

	/**
	 * Default included foreach section
	 *
	 * Allowed parameters:
	 * - count: will loop for the number of iterations passed in the count parameter
	 * - from: source array/iterator
	 * - key: name of the variable to assign the key of each item of the array/iterator
	 * - item: name of the variable to assign the value of each item of the array/iterator
	 *
	 * If each item of the array is itself a list array (keys are strings), or an object,
	 * each of its keys will be assigned as variables as well.
	 *
	 * ```
	 * {{#foreach count=3 key="i"}}{{$i}}.{{/foreach}}
	 * {{:assign var="array1" menu="pizza"}}
	 * {{:assign var="array2" c=$array1}}
	 * {{#foreach from=$array2}}
	 *   {{$menu}}
	 * {{/foreach}}
	 * {{#foreach from=$array1 key="k" item="value"}}
	 *   {{$k}} = {{$value}}
	 * {{/foreach}}
	 * ```
	 */
	static public function __foreach(array $params, Brindille $tpl, int $line): \Generator
	{
		if (array_key_exists('count', $params)) {
			for ($i = 0; $i < (int)$params['count']; $i++) {
				$array = [];

				if (isset($params['key']) && is_string($params['key'])) {
					$array[$params['key']] = $i;
				}

				yield $array;
			}

			return;
		}

		if (!array_key_exists('from', $params)) {
			throw new Brindille_Exception(sprintf('line %d: missing parameter: "from" or "count"', $line));
		}

		if (null == $params['from']) {
			return null;
		}

		if (!is_iterable($params['from'])) {
			return null;
		}

		foreach ($params['from'] as $key => $value) {
			$array = [];

			if (is_object($value)) {
				$value = (array)$value;
			}

			if (is_array($value) && is_string(key($value))) {
				$array = $value;
			}

			if (isset($params['item']) && is_string($params['item'])) {
				$array[$params['item']] = $value;
			}

			if (isset($params['key']) && is_string($params['key'])) {
				$array[$params['key']] = $key;
			}

			yield $array;
		}
	}

	/**
	 * Default '{{:assign' function
	 *
	 * This *always* assigns variables to level 0 so that the variables are kept in all contexts
	 *
	 * This allows these syntaxes:
	 * {{:assign name="Mr Lonely"}} => {{$name}}
	 * {{:assign var="people" age=42 name="Mr Lonely"}} => {{$people.age}} {{$people.name}}
	 * {{:assign .="user"}} => {{$user.name}} (within a section)
	 * {{:assign var="people[address]" value="42 street"}}
	 */
	static public function __assign(array $params, Brindille $tpl, int $line)
	{
		$unset = [];

		// Special case: {{:assign .="user" ..="loop"}}
		foreach ($params as $key => $value) {
			if (!preg_match('/^\.+$/', $key)) {
				continue;
			}

			$level = count($tpl->_variables) - strlen($key);

			self::__assign(array_merge($tpl->_variables[$level], ['var' => $value]), $tpl, $line);
			unset($params[$key]);
		}

		if (isset($params['var'])) {
			$var = $params['var'];
			unset($params['var']);

			$has_dot = false !== strpos($var, '.');
			$has_bracket = false !== strpos($var, '[');

			if ($has_bracket) {
				$separator = '[';
			}
			else {
				$separator = '.';
			}

			$parts = explode($separator, $var);

			$var_name = array_shift($parts);
			$unset[] = $var_name;

			if (!isset($tpl->_variables[0][$var_name]) || !is_array($tpl->_variables[0][$var_name])) {
				$tpl->_variables[0][$var_name] = [];
			}

			$prev =& $tpl->_variables[0][$var_name];

			// To assign to arrays, eg. {{:assign var="rows[0][label]"}}
			// or {{:assign var="rows.0.label"}}
			foreach ($parts as $sub) {
				$sub = trim($sub, '\'" ' . ($separator === '[' ? '[]' : '.'));

				if (null === $prev || !is_array($prev)) {
					$prev = [];
				}

				// Empty key: just increment
				if (!strlen($sub)) {
					$sub = count($prev);
				}

				if (!array_key_exists($sub, $prev)) {
					$prev[$sub] = [];
				}

				$prev =& $prev[$sub];
			}

			if (isset($params['append'])) {
				foreach ((array) $params['append'] as $key) {
					$prev[$key] = null;
					$prev =& $prev[$key];
				}

				unset($params['append']);
			}

			// If value is supplied, and nothing else is supplied, then use this value
			if (array_key_exists('value', $params) && count($params) === 1) {
				$prev = $params['value'];
			}
			// Same for 'from', but use it as a variable name
			// {{:assign var="test" from="types.%s"|args:$type}}
			elseif (array_key_exists('from', $params) && count($params) === 1) {
				$prev = null;
				$prev = is_string($params['from']) ? $tpl->get($params['from']) : null;
			}
			// Or else assign all params
			else {
				$prev = $params;
			}

			unset($prev);
		}
		// {{:assign bla="blou" address="42 street"}}
		else {
			$unset = array_keys($params);

			try {
				$tpl->assignArray($params, 0);
			}
			catch (\InvalidArgumentException $e) {
				throw new Brindille_Exception(sprintf('line %d: %s', $line, $e->getMessage()));
			}
		}

		// Unset all variables of the same name in children contexts,
		// as we expect the assigned variable to be accessible right away
		// If we don't do that, calling {{:assign}} in a section with a variable
		// named like an existing one, and then {{$variable}} in the same section,
		//  the variable from the section will be used instead of the one just assigned
		foreach ($unset as $name) {
			for ($i = count($tpl->_variables) - 1; $i > 0; $i--) {
				unset($tpl->_variables[$i][$name]);
			}
		}
	}
}

class Brindille_Exception extends \RuntimeException
{

}
