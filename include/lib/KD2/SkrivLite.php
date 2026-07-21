<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2;

/**
 * SkrivLite
 * Lightweight and one-file implementation of Skriv Markup Language.
 *
 * Skriv Markup Language and original implementation are from Amaury Bouchard,
 * see http://markup.skriv.org/ (under GNU GPL).
 */

class SkrivLite_Exception extends \Exception {}

class SkrivLite
{
	const CALLBACK_CODE_HIGHLIGHT = 'codehl';
	const CALLBACK_URL_ESCAPING = 'urlescape';
	const CALLBACK_URL_SHORTENING = 'urlshort';
	const CALLBACK_TITLE_TO_ID = 'title2id';

	public $throw_exception_on_syntax_error = false;
	public $allow_html = false;
	public $footnotes_prefix = 'skriv-notes-';

	public $toc = [];

	/**
	 * Enable <kbd> tags:
	 * [Ctrl] + [F2] [neat yeah] = <kbd>Ctrl</kbd> + <kbd>F2</kbd> [neat yeah]
	 * @var boolean
	 */
	public $enable_kbd = true;

	/**
	 * How many whitespace characters are required at the beginning of a line to make it
	 * render as a <pre> block?
	 * Default is 4 (only 1 in SkrivML original implementation)
	 * Warning: setting it to 1 or 2 may cause unexpected results for people who
	 * put a whitespace at the beginning of a line by mistake.
	 * Set it to 0 to disable <pre> parsing using whitespaces.
	 * This option doesn't affect the rendering as <pre> for lines starting with a tabulation.
	 * @var int
	 */
	public $pre_whitespace_size = 4;

	/**
	 * Ignore metadata at the beginning of the parsed string?
	 * Metadata is at the beginning of the text, using a simple syntax of key: value
	 * Inspired by https://github.com/fletcher/MultiMarkdown/wiki/MultiMarkdown-Syntax-Guide
	 * Default is true.
	 * @var boolean
	 */
	public $ignore_metadata = true;

	/**
	 * Enable basic inline HTML tags?
	 * Only allowed attributes: class, title
	 * @var boolean
	 */
	public $enable_basic_html = true;

	/**
	 * Simple inline tags
	 * @var array
	 */
	protected $inline_tags = array(
			'**' =>	'strong',
			"''" =>	'em',
			'__' =>	'u',
			'--' =>	's',
			'##' =>	'tt',
			'^^' =>	'sup',
			',,' =>	'sub',
		);

	/**
	 * Block tag stack (flat)
	 * @var array
	 */
	protected $_stack = array();

	/**
	 * true if we are in a verbatim/code block
	 * @var boolean
	 */
	protected $_verbatim = false;

	/**
	 * stores the language name of a code block
	 * @var string
	 */
	protected $_code = false;

	/**
	 * Stores current block content for code and extensions block
	 * @var mixed
	 */
	protected $_block = '';

	/**
	 * Configurable callbacks
	 * @var array
	 */
	protected $_callback = array();

	/**
	 * Footnotes
	 * @var array
	 */
	protected $_footnotes = array();
	protected $_footnotes_index = 0;

	/**
	 * User-defined extensions
	 * @var array
	 */
	protected $_extensions = array();

	/**
	 * Stores current block extension name and arguments
	 * @var array
	 */
	protected $_extension = false;

	/**
	 * Inline regexp, initialized at __construct
	 * @var string
	 */
	protected $_inline_regexp = null;

	protected $_nb_columns;

	/**
	 * List of classes
	 * @var array
	 */
	protected $_classes = [];

	public function __construct()
	{
		// Match link/image/extension/footnote not preceded by a backslash
		$this->_inline_regexp = '/(?<![\\\\])([' . preg_quote('[{<(') . '])\1';

		// Match other tags not preceded by a backslash or any character 
		// that is not a whitespace character (so that this**doesn't**work but this **does** work)
		$this->_inline_regexp.= '|(?<![\\\\\S])([' . preg_quote('?,*_^#\'-') . '])\2/';

		// Set default callbacks
		$this->setCallback(self::CALLBACK_CODE_HIGHLIGHT, array(__NAMESPACE__ . '\SkrivLite_Helper', 'highlightCode'));
		$this->setCallback(self::CALLBACK_URL_ESCAPING, array(__NAMESPACE__ . '\SkrivLite_Helper', 'protectUrl'));
		$this->setCallback(self::CALLBACK_TITLE_TO_ID, array(__NAMESPACE__ . '\SkrivLite_Helper', 'titleToIdentifier'));
		$this->footnotes_prefix = 'skriv-notes-' . base_convert(rand(0, 50000), 10, 36) . '-';
	}

	public function registerExtension($name, callable $callback): void
	{
		$this->_extensions[$name] = $callback;
	}

	public function registerExtensions(array $list): void
	{
		$this->_extensions = array_merge($this->_extensions, $list);
	}

	public function setCallback($function, $callback)
	{
		$callbacks = array(
			self::CALLBACK_CODE_HIGHLIGHT,
			self::CALLBACK_URL_ESCAPING,
			self::CALLBACK_URL_SHORTENING,
			self::CALLBACK_TITLE_TO_ID
		);

		if (!in_array($function, $callbacks))
		{
			throw new \UnexpectedValueException('Invalid callback method "' . $function . '"');
		}

		if ((is_bool($callback) && $callback === false) || is_callable($callback))
		{
			$this->_callback[$function] = $callback;
		}
		else
		{
			throw new \UnexpectedValueException('$callback is not a valid callback or FALSE');
		}

		return true;
	}

	public function addFootnote($content, $label = null)
	{
		$id = count($this->_footnotes) + 1;
		$content = trim($content);

		// Custom ID
		if (is_null($label))
		{
			$label = ++$this->_footnotes_index;
		}

		$this->_footnotes[$id] = array($label, $content);

		$id = $this->footnotes_prefix . $id;

		return '<sup class="footnote-ref"><a href="#cite_note-' . $id 
			. '" id="cite_ref-' . $id . '">' . $this->escape($label) . '</a></sup>';
	}

	public function getFootnotes($raw = false)
	{
		if ($raw === true)
		{
			return $this->_footnotes;
		}

		$footnotes = '';

		foreach ($this->_footnotes as $index=>$note)
		{
			list($label, $text) = $note;

			$id = $this->footnotes_prefix . $index;

			$footnotes .= '<p class="footnote"><a href="#cite_ref-' . $id 
				. '" id="cite_note-' . $id . '">';
			$footnotes .= $this->escape($label) . '</a>. ' . $this->_renderInline($text) . '</p>';
		}

		return "\n" . '<div class="footnotes">' . $footnotes . '</div>';
	}

	public function error($msg, $block = false)
	{
		if ($this->throw_exception_on_syntax_error)
		{
			throw new SkrivLite_Exception($msg);
		}
		else
		{
			$tag = $block ? 'p' : 'b';
			return '<' . $tag . ' style="color: red; background: yellow;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</' . $tag . '>';
		}
	}

	public function callExtension(array $match, ?string $content = null, bool $block = false): string
	{
		$name = strtolower($match[1]);

		if (!array_key_exists($name, $this->_extensions))
		{
			return $this->error('Unknown extension: ' . $name);
		}

		$_args = trim($match[2]);

		// "official" unnamed arguments separated by a pipe
		if ($_args != '' && $_args[0] == '|')
		{
			$args = explode('|', substr($_args, 1));
		}
		// unofficial named arguments similar to html args
		elseif ($_args != '' && (strpos($_args, '=') !== false))
		{
			$args = array();
			preg_match_all('/([[:alpha:]][[:alnum:]]*)(?:\s*=\s*(?:([\'"])(.*?)\2|([^>\s\'"]+)))?/i', $_args, $_args, PREG_SET_ORDER);

			foreach ($_args as $_a)
			{
				$args[$_a[1]] = isset($_a[4]) ? $_a[4] : (isset($_a[3]) ? $_a[3] : null);
			}
		}
		// unofficial unnamed arguments separated by spaces
		elseif ($_args != '' && $match[2][0] == ' ')
		{
			$args = preg_split('/[ ]+/', $_args);
		}
		elseif ($_args != '')
		{
			return $this->error('Invalid arguments (expecting arg1|arg2|arg3… or arg1="value1") for extension "'.$name.'": '.$_args);
		}
		else
		{
			$args = [];
		}

		return call_user_func($this->_extensions[$name], $block, $args, $content, $name, $this);
	}

	public function escape($text)
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', true);
	}

	protected function _inlineTag($tag, $text, &$tag_length)
	{
		$out = '';

		// Inline extensions: <<extension>> <<extension|param1|param2>> <<extension param1="value" param2>>
		if ($tag == '<<' && preg_match('/(^[a-z_]+)(.*?)>>/i', $text, $match))
		{
			$out = $this->callExtension($match);
		}
		// Footnotes: ((identifier|Foot note)) or ((numbered foot note))
		elseif ($tag == '((' && preg_match('/^(.*?)\)\)/', $text, $match))
		{
			if (preg_match('/^([\w\d ]+)\|/i', $match[1], $submatch))
			{
				$label = trim($submatch[1]);
				$content = trim(substr($match[1], strlen($submatch[1])+1));
			}
			else
			{
				$content = trim($match[1]);
				$label = null;
			}

			$out = $this->addFootnote($content, $label);
		}
		// Inline tags: **bold** ''italics'' --strike-through-- __underline__ ^^sup^^ ,,sub,,
		elseif (array_key_exists($tag, $this->inline_tags) 
			&& preg_match('/^(.*?)' . preg_quote($tag, '/') . '/', $text, $match))
		{
			$out = '<' . $this->inline_tags[$tag] . '>' . $this->_renderInline($match[1]) . '</' . $this->inline_tags[$tag] . '>';
		}
		// Abbreviations: ??W3C|World Wide Web Consortium??
		elseif ($tag == '??' && preg_match('/^(.+)\|(.+)\?\?/U', $text, $match))
		{
			$out = '<abbr title="' . $this->escape(trim($match[2])) . '">' . trim($match[1]) . '</abbr>';
		}
		// Links: [[http://example.tld/]] or [[Example|http://example.tld/]]
		elseif ($tag == '[[' && preg_match('/(.+?)\]\]/', $text, $match))
		{
			if (($pos = strpos($match[1], '|')) !== false)
			{
				$text = trim(substr($match[1], 0, $pos));
				$text = $this->_renderInline($text);
				$url = trim(substr($match[1], $pos + 1));
			}
			else
			{
				$text = $url = trim($match[1]);
				$text = $this->escape($text);
			}

			if (filter_var($url, FILTER_VALIDATE_EMAIL))
			{
				$url = 'mailto:' . $url;
			}

			$attributes = '';

			if (preg_match('!^https?:!', $url)) {
				$attributes = ' target="_blank" rel="noreferrer noopener"';
			}

			$url = call_user_func($this->_callback[self::CALLBACK_URL_ESCAPING], $url);
			$out = sprintf('<a href="%s"%s>%s</a>', $url, $attributes, $text);
		}
		// Images: {{image.jpg}} or {{alternative text|image.jpg}}
		elseif ($tag == '{{' && preg_match('/(.+?)\}\}/', $text, $match))
		{
			if (($pos = strpos($match[1], '|')) !== false)
			{
				$text = trim(substr($match[1], 0, $pos));
				$url = trim(substr($match[1], $pos + 1));
			}
			else
			{
				$text = $url = trim($match[1]);
			}

			$out = '<img src="' . call_user_func($this->_callback[self::CALLBACK_URL_ESCAPING], $url) . '" '
				. 'alt="' . $this->escape($text) . '" />';
		}
		else
		{
			// Invalid tag
			$out = $tag . $text;
			$tag_length = strlen($out);
		}

		if (isset($match[0]))
		{
			$tag_length = strlen($match[0]);
		}

		return $out;
	}

	/**
	 * <kbd> Rendering, inspired by http://markdown2.github.io/site/syntax/
	 * [Enter] = <kbd>Enter</kbd>
	 * @param  string $text Input text
	 * @return string       Output text
	 */
	protected function _renderInlineKbd($text)
	{
		return preg_replace('/\[((?:(?:Ctrl|Alt|Shift|Command|Option|Meta|Windows|Tab|Backspace|Insert|Delete|Enter|Entrée|Return|F\d{1,2}|Fn|Home|End|(?:Pg|Page)(?:Up|Dn|Down))(?:\s+\w)?)|\w)\]/ui', '<kbd>$1</kbd>', $text);
	}

	protected function _renderInline($text, $escape = false)
	{
		$out = '';

		while ($text != '')
		{
			if (preg_match($this->_inline_regexp, $text, $match, PREG_OFFSET_CAPTURE))
			{
				$pos = $match[0][1];

				if (!$this->allow_html || $escape)
				{
					$out .= $this->escape(substr($text, 0, $pos));
				}
				else
				{
					$out .= substr($text, 0, $pos);
				}

				$pos += 2;
				$text = substr($text, $pos);

				$out .= $this->_inlineTag($match[0][0], $text, $pos);

				$text = substr($text, $pos);
			}
			else
			{
				if (!$this->allow_html || $escape)
				{
					$text = $this->escape($text);
				}

				if ($this->enable_kbd)
				{
					$text = $this->_renderInlineKbd($text);
				}

				if ($this->enable_basic_html)
				{
					if (!$this->allow_html || $escape)
					{
						$start = '&lt;';
						$end = '&gt;';
					}
					else
					{
						$start = '<';
						$end = '>';
					}

					$text = preg_replace('!' . $start 
						. '([biqus]|ins|del|samp|kbd|code|big|small|strong|em|tt|cite|time|var|span|sub|sup)'
						. '((?:\s+(?:class|title)\s*=\s*(?:["\']|&quot;).*?(?:["\']|&quot;))*)'
						. $end . '(.*?)' . $start . '/\\1' . $end . '!i',
						'<\\1\\2>\\3</\\1>', $text);
				}

				$out .= $text;

				$text = '';
			}
		}

		return $out;
	}

	protected function _closeStack()
	{
		$out = '';

		while ($tag = array_pop($this->_stack))
		{
			$out .= '</' . $tag . '>';
		}

		return $out;
	}

	protected function _checkLastStack($tag)
	{
		$last = count($this->_stack);

		if ($last === 0)
			return false;

		if ($this->_stack[$last - 1] == $tag)
			return true;

		return false;
	}

	protected function _countTagsInStack($search)
	{
		$count = 0;

		foreach ($this->_stack as $tag)
		{
			if ($tag == $search)
				$count++;
		}

		return $count;
	}

	protected function _renderLine($line, $prev = null, $next = null)
	{
		// In a verbatim block: no further processing
		if ($this->_verbatim && strpos($line, ']]]') !== 0)
		{
			if ($this->_code && $this->_callback[self::CALLBACK_CODE_HIGHLIGHT])
			{
				$this->_block .= $line . "\n";
				return null;
			}
			else
			{
				return $this->allow_html ? $line : $this->escape($line);
			}
		}
		elseif ($this->_extension && strpos($line, '>>') !== 0)
		{
			$this->_block .= $line . "\n";
			return null;
		}

		$a = substr($line, 0, 1);
		$b = substr($line, 0, 2);
		$c = substr($line, 0, 3);

		// Verbatim/Code
		// Paragraphs breaks
		if (trim($line) === '')
		{
			if ($this->_checkLastStack('pre') && (strlen($line) > 0 || preg_match('/^\s/', $next)))
			{
				$line = '';
			}
			else
			{
				$line = $this->_closeStack();
			}
		}
		elseif ($c === '[[[')
		{
			$before = $this->_closeStack();
			$before .= '<pre>';
			$this->_stack[] = 'pre';

			// If programming language is given it's a code block
			if (trim(substr($line, 3)) !== '')
			{
				$language = strtolower(trim(substr($line, 3)));
				$before .= '<code class="language-' . $this->escape($language) . '">';
				$this->_stack[] = 'code';
				$this->_code = $language;
			}

			$line = $before;
			$this->_verbatim = true;
		}
		// Closing verbatim/code block
		elseif ($c === ']]]')
		{
			$line = '';

			if ($this->_code && $this->_callback[self::CALLBACK_CODE_HIGHLIGHT])
			{
				$line .= call_user_func($this->_callback[self::CALLBACK_CODE_HIGHLIGHT], $this->_code, $this->_block);
				$this->_code = false;
			}

			$line .= $this->_closeStack();
			$this->_verbatim = false;
		}
		// Opening of extension block
		// This regex avoids to match '<<ext|param>> some text <<ext2|other>>' as a block extension
		elseif ($b === '<<' && preg_match('/^<<<?([a-z_]+)((?:(?!>>>?).)*?)(>>>?$|$)/i', trim($line), $match))
		{
			if (!empty($match[3]))
			{
				$line = $this->callExtension($match, null);
			}
			else
			{
				$line = $this->_closeStack();
				$this->_block = '';
				$this->_extension = $match;
			}
		}
		// Closing extension block
		elseif ($b === '>>' && $this->_extension)
		{
			$line = $this->callExtension($this->_extension, $this->_block);

			$this->_block = false;
			$this->_extension = false;
		}
		// Horizontal rule
		elseif ($b === '---')
		{
			$line = $this->_closeStack();
			$line .= '<hr />';
		}
		// Titles / headers
		elseif ($a === '=' && preg_match('#^(={1,6})\s*(.*?)(?:\s*(?<!\\\\)\\1(?:\s*(.+))?)?$#', $line, $match))
		{
			$level = strlen($match[1]);
			$line = trim($match[2]);

			// Optional ID
			if (!empty($match[3]))
			{
				$id = $match[3];
			}
			else
			{
				$line = str_replace('\=', '=', $line);
				$id = $line;
			}

			$id = call_user_func($this->_callback[self::CALLBACK_TITLE_TO_ID], $id);

			$label = $line;
			$this->toc[] = compact('level', 'id', 'label');

			$label = $this->_renderInline($line);
			$line = $this->_closeStack() . '<h' . $level . ' id="' . $id . '">' . $label . '</h' . $level . '>';
		}
		// Quotes
		elseif ($a === '>' && preg_match('#^((?:>\s*)+)\s*(.*)$#', $line, $match))
		{
			$before = $after = '';

			// Number of opened <blockquotes>
			$nb_bq = $this->_countTagsInStack('blockquote');

			if (!$nb_bq)
			{
				$before .= $this->_closeStack();
			}

			// Number of quotes character
			$nb_q = substr_count($match[1], '>');

			$line = trim($match[2]) == '' ? '' : $this->_renderInline($match[2]);

			// If we need to get one level down, we have to close some tags
			if ($nb_q < $nb_bq)
			{
				while ($nb_bq > $nb_q)
				{
					if ($this->_checkLastStack('p'))
					{
						array_pop($this->_stack);
						$before .= '</p>';
					}

					array_pop($this->_stack);
					$before .= '</blockquote>';

					$nb_bq--;
				}
			}

			// If we need to get one level up, we need to open some tags
			if ($nb_q > $nb_bq)
			{
				// First close any <p> tag opened
				if ($this->_checkLastStack('p'))
				{
					array_pop($this->_stack);
					$before .= '</p>';
				}

				while ($nb_bq < $nb_q)
				{
					$this->_stack[] = 'blockquote';
					$before .= '<blockquote>';
					$nb_bq++;
				}
			}

			// Empty line: close current paragraph if open
			if (trim($match[2]) == '' && $this->_checkLastStack('p'))
			{
				array_pop($this->_stack);
				$after .= '</p>';
			}
			// We're already in a paragraph: then the previous line needs a line-break
			elseif ($this->_checkLastStack('p'))
			{
				$before .= '<br />';
			}
			// If we are not in a paragraph and the line is not empty, then we need one for content
			elseif ($line != '')
			{
				$before .= '<p>';
				$this->_stack[] = 'p';
			}

			$line = $before . $line . $after;
		}
		// Preformatted text
		elseif ($a === "\t"
			|| ($a === ' '
				&& $this->pre_whitespace_size > 0
				&& substr($line, 0, $this->pre_whitespace_size) === str_repeat(' ', $this->pre_whitespace_size)
				&& preg_match('/^[ ]{' . (int) $this->pre_whitespace_size . ',}/', $line, $match)))
		{
			$before = '';

			if (!$this->_checkLastStack('pre'))
			{
				$before .= $this->_closeStack();
				$before .= '<pre>';
				$this->_stack[] = 'pre';
			}

			$length = isset($match[0]) ? $this->pre_whitespace_size : 1;
			$line = $before . $this->escape(substr($line, $length));
		}
		// Styled blocks
		elseif ($c === '{{{' && preg_match('/^((?:\{{3}\s*)+)\s*(.*)$/', $line, $match))
		{
			$this->_classes[] = trim($match[2]);
			$line = $this->_closeStack() . '<div class="' . htmlspecialchars(implode(' ', $this->_classes)) . '">';
		}
		// Closing styled blocks
		elseif ($c === '}}}' && preg_match('/^((?:\}{3}\s*)+)$/', $line, $match))
		{
			$nb_closing = substr_count($line, '}}}');
			$line = '';

			// Just checking we have the right amount of closing curly brackets
			// If not, let's just assume this is a mistake and close all styled blocks now
			if ($nb_closing != count($this->_classes))
			{
				while (count($this->_classes))
				{
					array_pop($this->_classes);
					$line .= '</div>';
				}
			}
			else
			{
				array_pop($this->_classes);
				$line .= '</div>';
			}

		}
		// Tables
		elseif (($b === '!!' || $b === '||') && preg_match('/^(?<!\\\\)(!!|\|\|)\s*(.*)$/', $line, $match))
		{
			$line = '';
			$columns = explode($match[1], $match[2]);

			if (!$this->_checkLastStack('table'))
			{
				// Close opened tags before
				$line .= $this->_closeStack();

				$this->_stack[] = 'table';
				$line .= '<table>';
				$this->_nb_columns = count($columns);
			}
			// Avoid having the wrong number of columns after the first row
			elseif (count($columns) != $this->_nb_columns)
			{
				// Will limit the array size to number of columns in the first row
				$columns = explode($match[1], $match[2], $this->_nb_columns);

				// If there is a missing column, just append empty content
				while (count($columns) < $this->_nb_columns)
				{
					$columns[] = '';
				}
			}

			$line .= '<tr>';

			foreach ($columns as $col)
			{
				$tag = ($match[1] == '!!') ? 'th' : 'td';
				$line .= '<' . $tag . '>' . $this->_renderInline(trim($col)) . '</' . $tag . '>';
			}

			$line .= '</tr>';
		}
		// Match lists but avoid parsing bold/monospace tags **/##.
		elseif (($a === '*' || $a === '#')
			&& preg_match('/^([\*#]+)\s*(.*)$/', $line, $match) 
			&& !(($this->_checkLastStack('p') || empty($this->_stack)) 
				&& (trim($match[1]) == '##' || trim($match[1]) == '**')
				&& preg_match('/\*\*|##/', $match[2])))
		{
			$list = preg_replace('/\s/', '', $match[1]);
			$list = str_split($list, 1);

			$before = $after = '';

			$nb_ul = $this->_countTagsInStack('ul');
			$nb_ol = $this->_countTagsInStack('ol');

			// First close the stack if we're at the beginning of a list
			if (!$nb_ul && !$nb_ol)
			{
				$before .= $this->_closeStack();
			}

			// FIXME: The following section would need some simplification
			$stack_idx = 0;

			// For each */#, compare with current tag stack and close or open tags accordingly
			foreach ($list as $char)
			{
				$tag = isset($this->_stack[$stack_idx]) ? $this->_stack[$stack_idx] : false;
				$list_tag = ($char == '*') ? 'ul' : 'ol';

				// The list order differs from the existing one, we need to get back to the common parent
				if ($tag != $list_tag)
				{
					// Close stack up to $stack_idx
					$idx = count($this->_stack);
					while ($idx > $stack_idx)
					{
						$before .= '</' . array_pop($this->_stack) . '>';
						$idx--;
					}

					$tag = false;
				}

				// No tag, we need to open one
				if (!$tag)
				{
					$before .= '<' . $list_tag . '>';
					$this->_stack[] = $list_tag;
				}

				$stack_idx++;

				// Skips <li> tags
				if (isset($this->_stack[$stack_idx]) && $this->_stack[$stack_idx] == 'li')
				{
					$stack_idx++;
				}
			}

			// If there is still tags in stack it means we are going some levels down
			if (count($this->_stack) - $stack_idx > 0)
			{
				// Close stack up to $stack_idx
				$idx = count($this->_stack);
				while ($idx > $stack_idx)
				{
					$before .= '</' . array_pop($this->_stack) . '>';
					$idx--;
				}
			}

			if ($this->_checkLastStack('li'))
			{
				$before .= '</' . array_pop($this->_stack) . '>';
			}

			$before .= '<li>';
			$this->_stack[] = 'li';

			$line = $before . $this->_renderInline($match[2]);
		}
		else
		{
			$line = $this->_renderInline($line);

			// Line has content but no <p> container, open one
			if (!$this->_checkLastStack('p'))
			{
				$line = $this->_closeStack() . '<p>' . $line;
				$this->_stack[] = 'p';
			}
			// Already in a <p>? that means the previous-line needs a line-break
			else
			{
				$line = '<br />' . $line;
			}
		}

		return $line;
	}

	/**
	 * Parse metadata headers at the beginning of a Skriv text
	 * and removes it from the start of $text
	 * Inspired by https://github.com/fletcher/MultiMarkdown/wiki/MultiMarkdown-Syntax-Guide
	 * @param  string $text  Input text
	 * @param  boolean $obj  Return metadata in a stdClass object instead of an array
	 * @return array         Metadata
	 */
	public function parseMetadata(&$text, $obj = true)
	{
		$text = ltrim($text);
		$text = str_replace("\r", '', $text);
		$text = preg_split("/\n/", $text);

		$metadata = [];
		$current_meta = null;
		$in_meta = false;
		$k = null;

		foreach ($text as $k=>$line)
		{
			// Match "Key: Value"
			if (preg_match('/^([\w\d_\s-]+)\s*(?<!\\\\):\s*(.*?)$/u', trim($line), $match))
			{
				$current_meta = strtolower(trim($match[1]));

				if (array_key_exists($current_meta, $metadata))
				{
					$metadata[$current_meta] .= "\n" . trim($match[2]);
				}
				else
				{
					$metadata[$current_meta] = trim($match[2]);
				}
			}
			// Match "Key: Value\nValue second line"
			else if (trim($line) !== "" && $current_meta)
			{
				$metadata[$current_meta] .= "\n" . trim($line);
			}
			// Line is empty or doesn't match, means no meta headers or end of the headers
			else
			{
				break;
			}
		}

		$text = array_slice($text, $k);
		$text = implode("\n", $text);
		return $obj ? (object) $metadata : $metadata;
	}

	/**
	 * Parse a text string and convert it from SkrivML to HTML
	 * @param  string $text SrivML formatted text string
	 * @return string 		HTML formatted text string
	 */
	public function render($text, &$metadata = null)
	{
		// Reset internal storage of footnotes
		$this->_footnotes = array();
		$this->_footnotes_index = 0;
		$this->toc = [];

		$text = str_replace("\r", '', $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = preg_replace("/^\n+|\n+$/", '', $text); // Remove line breaks at beginning and end of text

		if (!$this->ignore_metadata)
		{
			$metadata = $this->parseMetadata($text);
		}

		$text = explode("\n", $text);
		$max = count($text);

		foreach ($text as $i => &$line)
		{
			$line = $this->_renderLine(
				$line,
				($i > 0) ? $text[$i - 1] : null, // Previous line
				($i + 1 < $max) ? $text[$i + 1] : null // Next line
			);
		}

		// Close tags that are still open
		$line .= $this->_closeStack();

		$text = implode("\n", $text);

		// Add footnotes
		if (!empty($this->_footnotes))
		{
			$text .= $this->getFootnotes();
		}

		return $text;
	}
}

/**
 * Some useful default callbacks for SkrivLite class
 */
class SkrivLite_Helper
{
	/**
	 * Allowed schemes in URLs
	 * @var array
	 */
    static public $allowed_url_schemes = array(
        'http'  =>  '://',
        'https' =>  '://',
        'ftp'   =>  '://',
        'mailto'=>  ':',
        'xmpp'  =>  ':',
        'news'  =>  ':',
        'nntp'  =>  '://',
        'tel'   =>  ':',
        'callto'=>  ':',
        'ed2k'  =>  '://',
        'irc'   =>  '://',
        'magnet'=>  ':',
        'mms'   =>  '://',
        'rtsp'  =>  '://',
        'sip'   =>  ':',
        );

	/**
	 * Simple and dirty code highlighter
	 * @param  string $language Language code in lowercase (not filtered for security)
	 * @param  string $line Code line to highlight (not escaped)
	 * @return string Highlighted code
	 */
	static public function highlightCode($language, $line)
	{
		$line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
		$line = preg_replace('![;{}[]$]!', '<b>$1</b>', $line);
		$line = preg_replace('!(public|static|protected|function|private|return)!i', '<i>$1</i>', $line);
		$line = preg_replace('!(false|true|boolean|bool|integer|int)!i', '<u>$1</u>', $line);
		return $line;
	}

	/**
	 * Protects a URL/URI given as an image/link target against XSS attacks
	 * (at least it tries) - copied from garbage2xhtml class by bohwaz
	 * @param  string 	$value 	Original URL
	 * @return string 	Filtered URL
	 */
	static public function protectUrl($value)
	{
        // Decode entities and encoded URIs
        $value = rawurldecode($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

        // Convert unicode entities back to ASCII
        // unicode entities don't always have a semicolon ending the entity
        $value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i', 
			function($match) { return chr(hexdec($match[1])); }, 
			$value);
        $value = preg_replace_callback('~&#0*([0-9]+);?~', 
        	function ($match) { return chr($match[1]); },
        	$value);

        // parse_url already have some tricks against XSS
        $url = parse_url($value);
        $value = '';

        if (!empty($url['scheme']))
        {
            $url['scheme'] = strtolower($url['scheme']);

            if (!array_key_exists($url['scheme'], self::$allowed_url_schemes))
                return '';

            $value .= $url['scheme'] . self::$allowed_url_schemes[$url['scheme']];
        }
        else {
        	$url['scheme'] = null;
        }

        if (!empty($url['host']))
        {
            $value .= $url['host'];
        }


        if (!empty($url['port']) && !($url['scheme'] == 'http' && $url['port'] == 80) 
        	&& !($url['scheme'] == 'https' && $url['port'] == 443))
        {
        	$value .= ':' . (int) $url['port'];
        }

        if (!empty($url['path']))
        {
            $value .= $url['path'];
        }

        if (!empty($url['query']))
        {
            // We can't use parse_str and build_http_string to sanitize url here
            // Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
            $query = explode('&', $url['query']);

            foreach ($query as &$item)
            {
                $item = explode('=', $item);

                if (isset($item[1]))
                    $item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
                else
                    $item = rawurlencode(rawurldecode($item[0]));
            }

            $value .= '?' . htmlspecialchars(implode('&', $query), ENT_QUOTES, 'UTF-8', true);
        }

        if (!empty($url['fragment']))
        {
            $value .= '#' . $url['fragment'];
        }
        return $value;
	}

	/**
	 * Transforms a title (used in headings) to a unique identifier (used in id attribute)
	 * Copied from SkrivMarkup project by Amaury Bouchard
	 * @param  string $text original title
	 * @return string unique title identifier
	 */
	static public function titleToIdentifier($text)
	{
        // Don't process empty strings
        if (!trim($text))
            return '-';

        $translit = false;

        // Disable transliterator as it is slow
        if (false && function_exists('transliterator_transliterate'))
        {
        	// Use a proper transliterator if available
        	$default = ini_get('intl.use_exceptions');
        	ini_set('intl.use_exceptions', 1);

        	try {
        		$text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        		$translit = true;
        	}
        	catch (\IntlException $e)
        	{
        		$translit = false;
        	}

        	ini_set('intl.use_exceptions', $default);
        }


        if (!$translit)
        {
			// conversion of accented characters
			// see http://www.weirdog.com/blog/php/supprimer-les-accents-des-caracteres-accentues.html
			$text = htmlentities($text, ENT_NOQUOTES, 'utf-8');
			$text = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $text);
			$text = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $text);	// for ligatures e.g. '&oelig;'
			$text = preg_replace('#&([lr]s|sb|[lrb]d)(quo);#', ' ', $text);	// for *quote (http://www.degraeve.com/reference/specialcharacters.php)
			$text = str_replace('&nbsp;', ' ', $text);                      // for non breaking space
			$text = preg_replace('#&[^;]+;#', '', $text);                   // strips other characters
		}

		$text = preg_replace("/[^a-zA-Z0-9_-]/", ' ', $text);           // remove any other characters
		$text = str_replace(' ', '-', $text);
		$text = preg_replace('/\s+/', " ", $text);
		$text = preg_replace('/-+/', "-", $text);
		$text = trim($text, '-');
		$text = trim($text);
		$text = empty($text) ? '-' : $text;

        return $text;
	}
}
