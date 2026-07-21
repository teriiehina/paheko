<?php
declare(strict_types=1);

namespace KD2\HTML;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Simplified CSS parser and matching engine
 * This is inspired by NetSurf, Servo, Gecko and SerenityOS LibWeb.
 *
 * This will parse and match:
 * - @media rules for media names: eg. 'screen', 'handheld', etc.
 * - selectors matching elements ('table', 'table tr'), classes and IDs
 *
 * This is unsupported:
 * - multiple classes selector: table.list.selected will match both <table class="list"> and <table class="statement">
 * - attributes selectors: a[name], a[name=pizza], etc.
 * - wildcards: *, +, >
 * - functions: :nth-child, etc.
 * - pseudo-selectors: :hover, ::after,etc.
 * - @import and other at-rules
 * - !important
 *
 * @author BohwaZ <https://bohwaz.net/>
 */
/*
	This software is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This software is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with this software.  If not, see <https://www.gnu.org/licenses/>.
*/
class CSSParser
{
	const RULE_NAME_TOKEN = '[a-z]+(?:-[a-z]+)*';

	const TOKENS = [
		// @media / @import / etc.
		'at-rule' => '\s*@' . self::RULE_NAME_TOKEN . '\s+[^\{\};]+?\s*[\{;]\s*',
		// Properties: border: 1px solid red; padding: 1px }
		'property' => '\s*-?' . self::RULE_NAME_TOKEN . '\s*:\s*[^;]+?\s*[;\}]\s*',
		'open' => '\s*[^\{\}]+?\s*\{\s*',
		'close' => '\s*\}\s*',
	];

	protected array $declarations = [];
	protected array $cache = [];
	protected array $path_cache = [];

	public function xpath(DOMNode $dom, string $query, int $item = null)
	{
		$xpath = new DOMXPath($dom instanceOf DOMDocument ? $dom : $dom->ownerDocument);
		$result = $xpath->query($query, $dom);

		if (null !== $item) {
			if (!$result->length || $result->length < $item + 1) {
				return null;
			}

			return $result->item($item);
		}

		return $result;
	}

	/**
	 * Import styles from <link rel="stylesheet"> tags inside a HTML document
	 *
	 * @param DOMNode $dom DOM document (or subset of it)
	 * @param string $media supply a media string here ('print', 'screen') to only keep stylesheets matching this media
	 */
	public function importExternalFromDOM(DOMNode $dom, string $media = null): void
	{
		$links = $this->xpath($dom, './/link[@rel="stylesheet"][@href]');

		foreach ($links as $link) {
			if ($media && false === strpos($link->getAttribute('media'), $media)) {
				continue;
			}

			$href = trim($link->getAttribute('href'));

			if (!$href) {
				continue;
			}

			$css = trim(@file_get_contents($href));

			if (!$css) {
				continue;
			}

			$this->import($css);
		}
	}

	/**
	 * Import styles from <style type="text/css"> tags inside a HTML document
	 *
	 * @param DOMNode $dom DOM document (or subset of it)
	 * @param string $media supply a media string here ('print', 'screen') to only keep stylesheets matching this media
	 */
	public function importInternalFromDOM(DOMNode $dom, string $media = null): void
	{
		$tags = $this->xpath($dom, './/style[@type="text/css"]');

		foreach ($tags as $style) {
			if ($media && false === strpos($style->getAttribute('media'), $media)) {
				continue;
			}

			$css = trim($style->textContent);

			if (!$css) {
				continue;
			}

			$this->import($css);
		}
	}

	/**
	 * Import styles from a string
	 *
	 * @param string $css Raw CSS text
	 * @param string $media Supply a media string here ('print', 'screen') to only keep declarations matching this media
	 */
	public function import(string $css, string $media = null): void
	{
		$this->declarations = $this->parse($css);

		foreach ($this->declarations as $key => $declaration) {
			if ($media && false === strpos($declaration['media'] ?? '', $media)) {
				unset($this->declarations[$key]);
				continue;
			}

			$this->declarations[$key]['selectors'] = $this->parseSelectors($declaration['selectors']);
		}
	}

	/**
	 * Return all imported CSS declarations
	 */
	public function getDeclarations(): array
	{
		return $this->declarations;
	}

	/**
	 * Parse a CSS string and produces an array of declarations (as inputted), containing properties (same)
	 */
	public function parse(string $css): array
	{
		// Remove comments
		$css = preg_replace('/\/\*.*\*\//Us', '', $css);

		// Normalize line breaks
		$css = preg_replace("/\r\n|\r/", "\n", $css);

		// Split string in tokens
		$tokens = self::tokenize($css, self::TOKENS);

		$current_atrule = null;
		$current_media = null;
		$current_selectors = null;
		$current_properties = null;
		$declarations = [];

		// Error function
		$fail = function (int $offset, string $msg) use ($css) {
			$line = substr_count($css, "\n", 0, $offset);
			throw new \InvalidArgumentException(sprintf('Parser error on line %d: ' . $msg, $line));
		};

		foreach ($tokens as $token) {
			extract($token);
			$value = trim($value);
			$closing = false;

			// Process @media, etc.
			if ($type == 'at-rule') {
				if (null !== $current_atrule) {
					$fail($offset, 'previous at-rule wasn\'t closed');
				}

				if (!preg_match('/^@(' . self::RULE_NAME_TOKEN . ')\s+/', $value, $match)) {
					$fail($offset, 'invalid at-rule: ' . $selector);
				}

				$name = $match[1];
				$selector = substr($value, strlen($match[0]));
				$selector = trim($selector);

				// We don't support @ rules without any content
				if (substr($selector, -1) == ';') {
					continue;
				}

				$current_atrule = $name;

				// We only support @media
				if ($name != 'media') {
					continue;
				}

				$current_media = rtrim($selector, "\n\t {");
			}
			// Open declaration, store selectors
			elseif ($type == 'open') {
				if (null !== $current_selectors) {
					$fail($offset, 'previous selector wasn\'t closed');
				}

				$current_selectors = rtrim($value, "\n\t {");
				$current_properties = [];
			}
			// Add a rule to the current declaration
			elseif ($type == 'property') {
				if (null === $current_properties) {
					$fail($offset, 'rule is not inside a selector');
				}

				$value = trim($value);
				// Properties can be missing a semicolon, in that case it's assumed
				// the rule is closing the declaration block
				$closing = substr($value, -1) == '}';
				$value = rtrim($value, "\n\t };");

				// Split name and value
				$pos = strpos($value, ':');
				$name = trim(substr($value, 0, $pos));
				$value = trim(substr($value, $pos + 1));

				$current_properties[$name] = $value;
			}
			// Close declaration
			elseif ($type == 'close') {
				if (null === $current_selectors && null !== $current_atrule) {
					$current_atrule = null;
					$current_media = null;
				}
				elseif (null === $current_selectors && null === $current_atrule) {
					$fail($offset, 'unexpected closing brace');
				}
				else {
					$closing = true;
				}
			}

			if ($closing) {
				$declarations[] = [
					'selectors' => $current_selectors,
					'media' => $current_media,
					'properties' => $current_properties,
				];

				$current_selectors = null;
				$current_properties = null;
			}
		}

		return $declarations;
	}

	/**
	 * Parse a string containing a list of selectors (eg 'table tr.odd td.number, #cell')
	 *
	 * Selectors are then splitted: 'table tr th, td, td.num' => ['table tr th', 'tr', 'td.num']
	 * then each individual selector is split to get the descendance: [['table', 'tr', 'th'], ['tr'], ['td.num']]
	 * In the end each part of the selector is split again to look for class names and IDs
	 */
	public function parseSelectors(string $selectors): array
	{
		$selectors = explode(',', $selectors);
		$selectors = array_map('trim', $selectors);
		$out = [];

		foreach ($selectors as $selector) {
			if (preg_match('/[\[\]:!\*=\+\>]/', $selector)) {
				// We only handle elements, IDs, and classes selectors, others: dismiss
				continue;
			}

			$selector = preg_split('/\s+/', $selector);
			$new_selector = [];

			foreach ($selector as $part) {
				preg_match_all('/\.[_a-zA-Z]+[_a-zA-Z0-9-]*|#[_a-zA-Z]+[\_a-zA-Z0-9-]*|[0-9A-Za-z]+/', $part, $match, PREG_PATTERN_ORDER);
				$part = [
					'classes' => [],
					'name' => '',
					'id' => '',
					//'selector' => implode(' ', $selector), // Useful for debug
				];

				foreach ($match[0] as $m) {
					if (substr($m, 0, 1) == '.') {
						$part['classes'][] = substr($m, 1);
					}
					elseif (substr($m, 0, 1) == '#') {
						$part['id'] = substr($m, 1);
					}
					else {
						$part['name'] = $m;
					}
				}

				$new_selector[] = $part;
			}

			$out[] = $new_selector;
		}

		return $out;
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

			$token = ['value' => $token[0], 'type' => $type, 'offset' => $len];
			$len += strlen($token['value']);
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
	 * Try to match a node with a selector
	 */
	public function matchSelector(DOMNode $node, array $selector): bool
	{
		// Match ID
		if ($selector['id'] && $node->getAttribute('id') != $selector['id']) {
			return false;
		}

		// Match tag name
		if ($selector['name'] && strtolower($node->nodeName) != $selector['name']) {
			return false;
		}

		// Match classes
		if (count($selector['classes'])) {
			$classes = preg_split('/\s+/', $node->getAttribute('class'));

			if (!array_intersect($selector['classes'], $classes)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the element path, including ID and name, useful for cache
	 * @see https://github.com/SerenityOS/serenity/blob/master/Userland/Libraries/LibWeb/CSS/StyleComputer.cpp#L1579
	 * @see https://hacks.mozilla.org/2017/08/inside-a-super-fast-css-engine-quantum-css-aka-stylo/
	 */
	public function getElementPath(DOMElement $node): string
	{
		$path = [];

		do {
			$name = array_search($node, $this->path_cache, true);

			if (!$name) {
				$name = $node->nodeName;

				if ($node->hasAttribute('class') && ($class = $node->getAttribute('class'))) {
					if (false !== strpos($class, ' ')) {
						$name .= '.' . preg_replace('/\s+/', '.', $class);
					}
					else {
						$name .= '.' . $class;
					}
				}
				elseif ($node->hasAttribute('id') && ($id = $node->getAttribute('id'))) {
					$name .= '#' . $id;
				}
			}

			$path[] = $name;
			$this->path_cache[$name] = $node;
		}
		while (($node = $node->parentNode) && $node instanceof DOMElement);

		$path = array_reverse($path);
		return implode('/', $path);
	}

	/**
	 * Return all CSS declarations applying specifically to a node
	 * (not including inherited properties from parents)
	 */
	public function match(DOMElement $search_node): array
	{
		$declarations = [];

		foreach ($this->declarations as &$declaration) {
			foreach ($declaration['selectors'] as &$selector) {
				$last = end($selector);
				$node = $search_node;

				// See if last item in selector matches node
				if (!$this->matchSelector($node, $last)) {
					continue;
				}

				// Try to match parent selectors (if any)
				// For each parent selector, we try to match parent nodes with this selector
				// until we run out of selectors or parent nodes
				while ($last = prev($selector)) {
					$parent = $node;
					while ($parent = $parent->parentNode) {
						if ($this->matchSelector($parent, $last)) {
							// If this matches, go to the next one
							$node = $parent;
							continue(2);
						}
					}

					// If we get there, it means nothing matched a parent, stop
					continue(2);
				}

				$declarations[] = $declaration;
			}
		}

		unset($declaration, $selector);

		return $declarations;
	}

	/**
	 * Return all properties applying to a node, including inherited properties from parents
	 */
	public function get(DOMElement $node): array
	{
		$path = $this->getElementPath($node);

		// Try to use cache, this speeds up things 3x times
		// If element has same path, classes and ID matches something in cache, just return it
		if (array_key_exists($path, $this->cache)) {
			return $this->cache[$path];
		}

		$properties = [];
		$parent = $node->parentNode;

		while ($parent instanceof DOMElement) {
			$declarations = $this->match($parent);

			foreach ($declarations as $declaration) {
				$properties = array_merge($properties, $declaration['properties']);
			}

			$parent = $parent->parentNode;
		}

		$declarations = $this->match($node);

		foreach ($declarations as $declaration) {
			$properties = array_merge($properties, $declaration['properties']);
		}

		$this->cache[$path] = $properties;

		return $properties;
	}

	/**
	 * Apply imported styles to a node and its children.
	 * For each styled node, a modified node will be returned, containing two arrays:
	 * css_declarations, containing a list of the declaration blocks matching this node
	 * css_properties, containing an inherited list of CSS properties (cascading applies)
	 */
	public function apply(DOMNode $node, array $properties = []): \Generator
	{
		$declarations = $this->match($node);

		foreach ($declarations as $declaration) {
			$properties = array_merge($properties, $declaration['properties']);
		}

		if (count($properties)) {
			$node->css_properties = $properties;
			$node->css_declarations = $declarations;
			yield $node;
		}


		foreach ($node->childNodes as $child) {
			if ($child->nodeType != XML_ELEMENT_NODE) {
				continue;
			}

			yield from $this->apply($child, $properties);
		}
	}

	/**
	 * Return CSS styles string from properties array, for use in tag attribute
	 */
	public function outputStyles(array $properties): ?string
	{
		$out = '';

		foreach ($properties as $name => $value) {
			$out .= $name . ': ' . $value . "; ";
		}

		return $out;
	}

	/**
	 * Add the computed styles to each node 'style' attribute
	 */
	public function style(DOMNode $parent)
	{
		foreach ($this->apply($parent) as $element) {
			$element->setAttribute('style', trim($this->outputStyles($element->css_properties)));
		}
	}

	/**
	 * Display what's going on
	 */
	public function debug(DOMNode $parent)
	{
		foreach ($this->apply($parent) as $element) {
			if (!count($element->css_declarations)) {
				continue;
			}

			echo "Declarations applying to: " . $element->nodeName . "\n";

			foreach ($element->css_declarations as $k => $d) {
				echo "- $k:\n";
				echo "  Media: " . $d['media'] . "\n";
				echo "  Properties:\n";

				foreach ($d['properties'] as $name => $value) {
					echo "    $name: $value\n";
				}
			}

			echo str_repeat("-", 70) . "\n";
		}
	}
}
