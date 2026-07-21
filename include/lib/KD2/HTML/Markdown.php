<?php

namespace KD2\HTML;

/**
 * Custom Parsedown extension to enable the use of extensions inside Markdown markup
 *
 * Also adds support for footnotes and Table of Contents
 *
 * @see https://github.com/erusev/parsedown/wiki/Tutorial:-Create-Extensions
 */
class Markdown extends Parsedown
{
	protected array $extensions = [];
	protected $defaultExtensionCallback = null;

	public array $toc = [];

	/**
	 * Custom tags allowed inline
	 */
	const DEFAULT_INLINE_TAGS = [
		'abbr'    => null,
		'dfn'     => null,
		'acronym' => null,

		'kbd'    => null,
		'samp'   => null,
		'code'   => null,

		'del'    => null,
		'ins'    => null,
		'sup'    => null,
		'sub'    => null,

		'mark'   => null,
		'var'    => null,

		'span'   => null,
		'strong' => null,
		'em'     => null,
		'i'      => null,
		'b'      => null,
		'small'  => null,
		'a'      => ['href', 'target'],
	];

	/**
	 * Custom tags allowed as blocks
	 */
	const DEFAULT_BLOCK_TAGS = [
		'object'     => ['type', 'width', 'height', 'data'],
		'iframe'     => ['src', 'width', 'height', 'frameborder', 'scrolling', 'allowfullscreen', 'title'],
		'audio'      => ['src', 'controls', 'loop'],
		'video'      => ['src', 'controls', 'width', 'height', 'poster'],
	];

	public $allowed_inline_tags = self::DEFAULT_INLINE_TAGS;
	public $allowed_block_tags = self::DEFAULT_BLOCK_TAGS;

	protected $ext_level = 0;

	public function registerExtension(string $tag, ?callable $callback): void
	{
		if (null === $callback) {
			unset($this->extensions[$tag]);
		}
		else {
			$this->extensions[$tag] = $callback;
		}
	}

	public function registerDefaultExtensionCallback(?callable $callback): void
	{
		$this->defaultExtensionCallback = $callback;
	}

	function __construct()
	{
		array_unshift($this->BlockTypes['<'], 'Extension');
		array_unshift($this->BlockTypes['<'], 'TOC');

		$this->BlockTypes['['][]= 'TOC';
		$this->BlockTypes['{'][]= 'TOC';
		$this->BlockTypes['{'][]= 'Class';

		// Make Skriv extensions also available inline, before anything else
		array_unshift($this->InlineTypes['<'], 'Extension');

		# identify footnote definitions before reference definitions
		array_unshift($this->BlockTypes['['], 'Footnote');

		# identify footnote markers before before links
		array_unshift($this->InlineTypes['['], 'FootnoteMarker');

		$this->InlineTypes['='][] = 'Highlight';

		$this->inlineMarkerList .= '=';
		$this->specialCharacters[] = '<';

		$this->setBreaksEnabled(true);
		$this->setUrlsLinked(true);
		$this->setSafeMode(true);

		$this->extensions['toc'] = [$this, '_getTempTOC'];
	}

	/**
	 * Parse attributes from a HTML tag
	 */
	protected function _parseAttributes(string $str): array
	{
		preg_match_all('/([[:alpha:]][[:alnum:]]*)(?:\s*=\s*(?:([\'"])(.*?)\2|([^>\s\'"]+)))?/i', $str, $match, PREG_SET_ORDER);
		$params = [];

		foreach ($match as $m)
		{
			$params[$m[1]] = isset($m[4]) ? $m[4] : (isset($m[3]) ? $m[3] : null);
		}

		return $params;
	}

	protected function _filterURL(string $url): ?string
	{
		$url = html_entity_decode($url);

		$check_url = rawurldecode($url);
		$check_url = str_replace([' ', "\t", "\n", "\r", "\0"], '', $check_url);

		if (stristr($check_url, 'script:')) {
			return null;
		}

		if (strstr($check_url, '"') || strstr($check_url, '\'')) {
			return null;
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);

		if ($scheme && $scheme != 'http' && $scheme != 'https') {
			return null;
		}

		return $url;
	}

	/**
	 * Filter attributes for a HTML tag
	 */
	protected function _filterHTMLAttributes(string $name, ?array $allowed, string $str): ?array
	{
		$attributes = $this->_parseAttributes($str);

		$allowed ??= [];
		$allowed[] = 'class';
		$allowed[] = 'lang';
		$allowed[] = 'title';

		$style = $attributes['style'] ?? null;

		foreach ($attributes as $key => $value) {
			if (!in_array($key, $allowed)) {
				unset($attributes[$key]);
				continue;
			}

			$value = $value ? htmlspecialchars($value) : '';
			$attributes[$key] = $value;
		}

		if ($name === 'iframe' || $name === 'video' || $name === 'audio') {
			$attributes['loading'] = 'lazy';
		}

		if ($name === 'video' || $name === 'audio') {
			$attributes['controls'] = 'true';
		}

		if ($name === 'iframe') {
			if (!isset($attributes['src']) || !preg_match('!^https?://|//!', $attributes['src'])) {
				return null;
			}

			$attributes['src'] = htmlspecialchars_decode($attributes['src']);
			$attributes['referrerpolicy'] = 'no-referrer';
			$attributes['sandbox'] = 'allow-same-origin allow-scripts allow-popups allow-forms allow-modals';
			$attributes['frameborder'] = 0;

			if ($style && preg_match('/width:\s*(\d+(?:px|%)?)/', $style, $match)) {
				$attributes['width'] = $match[1];
			}

			if ($style && preg_match('/height:\s*(\d+(?:px|%)?)/', $style, $match)) {
				$attributes['height'] = $match[1];
			}
		}
		elseif ($name === 'object') {
			if (!isset($attributes['data']) || !preg_match('!^https?://!', $attributes['data'])) {
				return null;
			}

			$attributes['data'] = $this->_filterURL($attributes['data']);
		}

		if (isset($attributes['src'])) {
			$attributes['src'] = $this->_filterURL($attributes['src']);
		}

		if (isset($attributes['href'])) {
			$attributes['href'] = $this->_filterURL($attributes['href']);
		}

		if (isset($attributes['poster'])) {
			$attributes['poster'] = $this->_filterURL($attributes['poster']);
		}

		return $attributes;
	}

	protected function _getTempTOC(bool $block, array $args): string
	{
		return sprintf('<<toc|%d|%d>>', $args['level'] ?? 0, array_key_exists('aside', $args) && $args['aside'] !== false);
	}

	protected function _replaceTempTOC(string $out, array $toc): string
	{
		if (false !== strpos($out, '<<toc') && preg_match_all('!<<toc\|(\d\|(?:0|1))>>!', $out, $match, PREG_PATTERN_ORDER)) {
			$types = array_unique($match[1] ?? []);

			foreach ($types as $t) {
				$args = ['level' => (int) $t[0], 'aside' => (bool) $t[2]];
				$str = $this->_buildTOC($args, $toc);
				$out = str_replace(sprintf('<<toc|%s>>', $t), $str, $out);
			}
		}

		return $out;
	}

	protected function _buildTOC(array $args, array $toc): string
	{
		$max_level = $args['level'] ?? 0;
		$out = '';

		if (!count($toc)) {
			return $out;
		}

		$level = 0;

		foreach ($toc as $k => $h) {
			if ($max_level > 0 && $h['level'] > $max_level) {
				continue;
			}

			if ($h['level'] < $level) {
				$out .= "\n" . str_repeat("\t", $level);
				$out .= str_repeat("</ol></li>\n", $level - $h['level']);
				$level = $h['level'];
			}
			elseif ($h['level'] > $level) {
				$out .= "\n" . str_repeat("\t", $h['level']);
				$out .= str_repeat("<ol>\n", $h['level'] - $level);
				$level = $h['level'];
			}
			elseif ($k) {
				$out .= "</li>\n";
			}

			$out .= str_repeat("\t", $level + 1);
			$out .= sprintf('<li><a href="#%s">%s</a>', $h['id'], $h['label']);
		}

		if ($level > 0) {
			$out .= "\n";
			$out .= str_repeat('</li></ol>', $level);
		}

		if (isset($args['aside']) && $args['aside'] !== false) {
			$out = '<aside class="toc">' . $out . '</aside>';
		}
		else {
			$out = '<div class="toc">' . $out . '</div>';
		}

		return $out;
	}

	/**
	 * Inline extensions: <<color red>>bla blabla<</color>>
	 */
	protected function inlineExtension(array $str): ?array
	{
		if (preg_match('/^<<<?(\/?[a-z][a-z0-9_]*)((?:(?!>>>?).)*?)>>>?/i', $str['text'], $match)) {
			$text = $this->callExtension($match[1], false, $match[2]);

			return [
				'extent'    => strlen($match[0]),
				'element' => [
					'rawHtml'                => $text,
					'allowRawHtmlInSafeMode' => true,
				],
			];
		}

		return null;
	}

	/**
	 * Block extensions
	 */
	protected function blockExtension(array $line): ?array
	{
		$line = $line['text'];

		if (strpos($line, '<<') === 0
			&& preg_match('/^<<<?(\/?[a-z][a-z0-9_]*)((?:(?!>>>?).)*?)(>>>?$|$)/ism', trim($line), $match)) {
			$closed = !empty($match[3]);

			// Count levels of extensions inside extensions
			$this->ext_level += intval(!$closed);

			$text = $closed ? $this->callExtension($match[1], true, $match[2]) : '';

			return [
				'char'       => $line[0],
				'ext_name'   => $match[1],
				'ext_params' => $match[2],
				'ext_content' => '',
				'closed'     => $closed,
				'element'    => [
					'rawHtml'                => $text,
					'allowRawHtmlInSafeMode' => true,
				],
			];
		}

		return null;
	}

	protected function blockExtensionContinue(array $line, array $block): ?array
	{
		if (!empty($block['closed'])) {
			return null;
		}

		// Only close an extension if the closing >> is the last one encountered
		if (strpos($line['text'], '>>') === 0
			&& --$this->ext_level === 0) {
			$block['closed'] = true;
			$block['element']['rawHtml'] = $this->callExtension($block['ext_name'], true, $block['ext_params'], rtrim($block['ext_content']));
		}
		else {
			// Count levels of extensions inside extensions
			if (strpos($line['text'], '<<') === 0) {
				$this->ext_level++;
			}

			// Restore empty lines
			if (isset($block['interrupted'])) {
				$block['ext_content'] .= str_repeat("\n", $block['interrupted']);
				unset($block['interrupted']);
			}

			$block['ext_content'] .= $line['body'] . "\n";
		}

		return $block;
	}

	/**
	 * Class block:
	 * {{class1 class2
	 * > My block
	 * }}
	 */
	protected function blockClass(array $line): ?array
	{
		$line = $line['text'];

		if (strpos($line, '{{{') === 0) {
			$classes = trim(substr($line, 3));
			$classes = str_replace('.', '', $classes);

			return [
				'char'    => $line[0],
				'element' => [
					'name' => 'div',
					'attributes' => ['class' => $classes],
					'handler' => [
						'function' => 'linesElements',
						'argument' => [],
						'destination' => 'elements',
					],
				],
				'closed' => false,
			];
		}

		return null;
	}

	protected function blockClassContinue(array $line, array $block): ?array
	{
		if (!empty($block['closed'])) {
			return null;
		}

		if (strpos($line['text'], '}}}') !== false) {
			$block['closed'] = true;
		}
		else {
			$block['element']['handler']['argument'][] = $line['body'];
		}

		return $block;
	}

	/**
	 * Remove HTML comments
	 * @replaces parent::blockComment
	 */
	protected function blockComment($line): ?array
	{
		if (strpos($line['text'], '<!--') === 0) {
			$block = ['element' => ['rawHtml' => '']];

			if (strpos($line['text'], '-->') !== false) {
				$block['closed'] = true;
			}

			return $block;
		}

		return null;
	}

	/**
	 * Remove HTML comments
	 * @replaces parent::blockComment
	 */
	protected function blockCommentContinue($line, array $block): ?array
	{
		if (isset($block['closed'])) {
			return null;
		}

		if (strpos($line['text'], '-->') !== false) {
			$block['closed'] = true;
		}

		return $block;
	}

	/**
	 * Transform ==text== to <mark>text</mark>
	 */
	protected function inlineHighlight(array $str): ?array
	{
		if (substr($str['text'], 1, 1) === '='
			&& preg_match('/^==(?=\S)(.+?)(?<=\S)==/', $str['text'], $matches))
		{
			return [
				'extent' => strlen($matches[0]),
				'element' => [
					'name' => 'mark',
					'handler' => [
						'function' => 'lineElements',
						'argument' => $matches[1],
						'destination' => 'elements',
					],
				],
			];
		}

		return null;
	}

	/**
	 * Override default strikethrough, as it is incorrectly using <del>
	 */
	protected function inlineStrikethrough($e)
	{
		if (substr($e['text'], 1, 1) === '~' && preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $e['text'], $matches))
		{
			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'span',
					'attributes' => ['style' => 'text-decoration: line-through'],
					'handler' => array(
						'function' => 'lineElements',
						'argument' => $matches[1],
						'destination' => 'elements',
					)
				),
			);
		}

		return null;
	}

	/**
	 * Allow simple inline markup tags
	 */
	protected function inlineMarkup($str)
	{
		$text = $str['text'];

		// Comments
		if (preg_match('/<!--.*?-->/', $text, $match)) {
			return ['element' => ['rawHtml' => ''], 'extent' => strlen($match[0])];
		}

		// Skip if not a tag
		if (!preg_match('!(</?)(\w+)([^>]*?)>!', $text, $match)) {
			return null;
		}

		$name = $match[2];

		if (!array_key_exists($name, $this->allowed_inline_tags)) {
			return null;
		}

		$attributes = $this->_filterHTMLAttributes($name, $this->allowed_inline_tags[$name], $match[3]);

		if (null === $attributes) {
			return null;
		}

		$attributes_string = '';

		foreach ($attributes as $key => $value) {
			if (null === $value) {
				return null;
			}

			$attributes_string .= sprintf(' %s="%s"', htmlspecialchars($key), htmlspecialchars($value));
		}

		$tag = sprintf('%s%s%s>', $match[1], $name, $attributes_string);

		return [
			'element' => [
				'rawHtml' => $tag,
				'allowRawHtmlInSafeMode' => true,
			],
			'extent' => strlen($match[0]),
		];
	}

	/**
	 * Allow some markup blocks, eg. iframe
	 */
	protected function blockMarkup($line): ?array
	{
		// Skip if not a tag
		if (!preg_match('!<(/?)(\w+)([^>]*)>!', $line['text'], $match)) {
			return null;
		}

		$name = $match[2];

		if (!array_key_exists($name, $this->allowed_block_tags)) {
			return null;
		}

		// Don't load youtube player, just display preview
		if ($name === 'iframe' && preg_match('!https://www.youtube.com/embed/([^"?]+)!', $line['text'], $m)) {
			return [
				'element' => [
					'rawHtml' => sprintf('<figure class="video"><a href="https://www.youtube.com/watch?v=%s" target="_blank" title="Ouvrir la vidéo" rel="noreferrer"><img src="http://img.youtube.com/vi/%1$s/maxresdefault.jpg" alt="Vidéo Youtube" loading="lazy" /></a></figure>', htmlspecialchars($m[1])),
					'allowRawHtmlInSafeMode' => true,
				],
			];
		}
		/*
		// Don't load PeerTube player, just display preview
		elseif ($name === 'iframe' && preg_match('!"((https?://[^/]+/)videos/embed/([0-9a-f-]{36}).*?)"!', $line['text'], $m)) {
			$html = sprintf('<figure class="video"><a href="%sw/%s" target="_blank" title="Ouvrir la vidéo" rel="noreferrer"><img src="%1$sstatic/previews/%2$s.jpg" alt="Vidéo PeerTube" /></a></figure>', htmlspecialchars($m[2]), htmlspecialchars($m[3]));

			return [
				'element' => [
					'rawHtml' => $html,
					'allowRawHtmlInSafeMode' => true,
					// onclick="this.parentNode.innerHTML = \'%s\';"
					// <iframe title="Paheko_Membres" width="560" height="315" src="https://videos.yeswiki.net/videos/embed/906b2038-4ee8-4cd9-abe2-7009f6e4f5e5?autoplay=1" frameborder="0" allowfullscreen="" sandbox="allow-same-origin allow-scripts allow-popups"></iframe>
				],
			];
		}
		*/
		// Force iframes to be responsive
		elseif ($name === 'iframe') {
			$attributes = $this->_filterHTMLAttributes($name, $this->allowed_block_tags[$name], $match[3]);

			if (null === $attributes || empty($attributes['src'])) {
				return null;
			}

			$h = intval($attributes['height'] ?? 56.25);
			$w = intval($attributes['width'] ?? 100);

			if ($h < $w) {
				$style = sprintf('padding-top: %f%%;', ($h / $w) * 100);
			}
			else {
				$style = sprintf('height: %s', $attributes['height']);
			}

			unset($attributes['width'], $attributes['height']);
			$attributes['frameborder'] = 0;
			$attributes['allowfullscreen'] = '';
			$attributes['allowtransparency'] = '';
			$attributes['style'] = 'position: absolute; inset: 0px;';

			array_walk($attributes, fn (&$v, $k) => $v = $k . '="' . htmlspecialchars((string)$v) . '"');
			$attributes = implode(' ', $attributes);

			return ['element' => [
				'rawHtml' => sprintf('<figure class="video" style="%s"><iframe width="100%%" height="100%%" %s></iframe></figure>', $style, $attributes),
				'allowRawHtmlInSafeMode' => true,
			]];
		}

		$attributes = $this->_filterHTMLAttributes($name, $this->allowed_block_tags[$name], $match[3]);

		if (null === $attributes) {
			return null;
		}

		return [
			'element' => [
				'name' => $name,
				'attributes' => $attributes,
				'autobreak' => true,
				'text' => '',
			],
		];
	}

	/**
	 * Open external links in new page
	 */
	protected function inlineLink($e)
	{
		$e = parent::inlineLink($e);

		if (isset($e['element']['attributes']['href']) && strstr($e['element']['attributes']['href'], '://')) {
			$e['element']['attributes']['rel'] = 'noreferrer noopener external';
		}

		return $e;
	}

	/**
	 * Use headers to populate TOC
	 */
	protected function blockHeader($line): ?array
	{
		$block = parent::blockHeader($line);

		if (!is_array($block)) {
			return $block;
		}

		$text =& $block['element']['handler']['argument'];

		// Extract attributes: {#id} {.class-name}
		if (preg_match('/(?!\\\\)[ #]*{((?:[#.][-\w]+[ ]*)+)}[ ]*$/', $text, $matches, PREG_OFFSET_CAPTURE)) {
			$block['element']['attributes'] = $this->_parseAttributeData($matches[1][0]);
			$text = trim(substr($text, 0, $matches[0][1]));
		}

		if (strstr($block['element']['attributes']['class'] ?? '', 'no_toc')) {
			return $block;
		}

		if (!isset($block['element']['attributes']['id'])) {
			$block['element']['attributes']['id'] = strtolower($this->_titleToIdentifier($text));
		}

		$level = substr($block['element']['name'], 1); // h1, h2... -> 1, 2...
		$id = $block['element']['attributes']['id'];
		$label = $text;
		unset($text);

		$this->toc[] = compact('level', 'id', 'label');

		return $block;
	}

	/**
	 * Transforms a title (used in headings) to a unique identifier (used in id attribute)
	 * Copied from SkrivMarkup project by Amaury Bouchard
	 * @param  string $text original title
	 * @return string unique title identifier
	 */
	protected function _titleToIdentifier(string $text): string
	{
		// Don't process empty strings
		if (!trim($text))
			return '-';

		$translit = false;

		// Use a proper transliterator if available
		if (function_exists('transliterator_transliterate'))
		{
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
		$text = strtolower($text);

		return $text;
	}

	protected function _parseAttributeData(string $string): array
	{
		$data = [];
		$classes = [];
		$attributes = preg_split('/[ ]+/', $string, - 1, PREG_SPLIT_NO_EMPTY);

		foreach ($attributes as $attribute) {
			if ($attribute[0] === '#') {
				$data['id'] = substr($attribute, 1);
			}
			else {
				$classes[] = substr($attribute, 1);
			}
		}

		if (count($classes))  {
			$data['class'] = implode(' ', $classes);
		}

		return $data;
	}

	/**
	 * Replace [toc], <<toc>>, {:toc} and [[_TOC_]] with temporary TOC token
	 * as we first need to process the headings to build the TOC
	 */
	protected function blockTOC(array $line): ?array
	{
		if (!preg_match('/^(?:\[toc\]|\{:toc\}|\[\[_TOC_\]\]|<<<?toc(?:\s+([^>]+?))?>>>?)$/', trim($line['text']), $match)) {
			return null;
		}

		$level = 0;

		if (!empty($match[1]) && false !== ($pos = strpos($match[1], 'level='))) {
			$level = (int) trim(substr($match[1], 6 + $pos, 2), ' "');
		}

		$aside = (bool) strstr($match[1] ?? '', 'aside');

		return [
			'char'     => $line['text'][0],
			'complete' => true,
			'element'  => [
				'rawHtml'                => $this->_getTempTOC(false, compact('level', 'aside')),
				'allowRawHtmlInSafeMode' => true,
			],
		];
	}

	/**
	 * Footnotes implementation, inspired by ParsedownExtra
	 * We're not using ParsedownExtra as it's buggy and unmaintained
	 */
	protected function blockFootnote(array $line): ?array
	{
		if (preg_match('/^\[\^(.+?)\]:[ ]?(.*)$/', $line['text'], $matches))
		{
			$block = array(
				'footnotes' => [$matches[1] => $matches[2]],
			);

			return $block;
		}

		return null;
	}

	protected function blockFootnoteContinue(array $line, array $block): ?array
	{
		if ($line['text'][0] === '[' && preg_match('/^\[\^(.+?)\]: ?(.*)$/', $line['text'], $matches))
		{
			$block['footnotes'][$matches[1]] = $matches[2];
			return $block;
		}

		end($block['footnotes']);
		$last = key($block['footnotes']);

		if (isset($block['interrupted']) && $line['indent'] >= 4)
		{
			$block['footnotes'][$last] .= "\n\n" . $line['text'];

			return $block;
		}
		else
		{
			$block['footnotes'][$last] .= "\n" . $line['text'];

			return $block;
		}
	}

	protected function blockFootnoteComplete(array $in)
	{
		$html = '';

		foreach ($in['footnotes'] as $name => $value) {
			$html .= sprintf('<dt id="fn-%s"><a href="#fn-ref-%1$s">%1$s</a></dt><dd>%s</dd>', htmlspecialchars($name), $this->line($value));
		}

		$out = [
			'complete' => true,
			'element' => [
				'name'                   => 'dl',
				'attributes'             => ['class' => 'footnotes'],
				'rawHtml'                => $html,
				'allowRawHtmlInSafeMode' => true,
			],
		];

		return $out;
	}


	protected function inlineFootnoteMarker($e)
	{
		if (preg_match('/^\[\^(.+?)\]/', $e['text'], $matches))
		{
			$name = htmlspecialchars($matches[1]);

			$Element = array(
				'name' => 'a',
				'attributes' => ['id' => 'fn-ref-'.$name, 'href' => '#fn-'.$name, 'class' => 'footnote-ref'],
				'text' => $name,
			);

			return [
				'extent' => strlen($matches[0]),
				'element' => $Element,
			];
		}
	}

	public function text($text)
	{
		$this->toc = [];

		$out = parent::text($text);

		$out = $this->_replaceTempTOC($out, $this->toc);
		return $out;
	}

	public function callExtension(string $name, bool $block = false, ?string $params = null, ?string $content = null): string
	{
		$name = strtolower($name);

		if (!array_key_exists($name, $this->extensions) && !$this->defaultExtensionCallback) {
			return self::error('Unknown extension: ' . $name);
		}

		$params = rtrim($params ?? '');

		// "official" unnamed arguments separated by a pipe
		if ($params !== '' && $params[0] == '|') {
			$params = array_map('trim', explode('|', substr($params, 1)));
		}
		// unofficial named arguments similar to html args
		elseif ($params !== '' && (strpos($params, '=') !== false)) {
			preg_match_all('/([[:alpha:]][[:alnum:]]*)(?:\s*=\s*(?:([\'"])(.*?)\2|([^>\s\'"]+)))?/i', $params, $match, PREG_SET_ORDER);
			$params = [];

			foreach ($match as $m)
			{
				$params[$m[1]] = isset($m[4]) ? $m[4] : (isset($m[3]) ? $m[3] : null);
			}
		}
		// unofficial unnamed arguments separated by spaces
		elseif ($params !== '' && $params[0] == ' ') {
			$params = preg_split('/[ ]+/', $params, -1, \PREG_SPLIT_NO_EMPTY);
		}
		elseif ($params != '') {
			return self::error(sprintf('Invalid arguments (expecting arg1|arg2|arg3… or arg1="value1") for extension "%s": %s', $name, $params));
		}
		else {
			$params = [];
		}

		return call_user_func($this->extensions[$name] ?? $this->defaultExtensionCallback, $block, $params, $content, $name, $this);
	}

	static public function error(string $msg, bool $block = false)
	{
		$tag = $block ? 'p' : 'b';
		return '<' . $tag . ' style="color: red; background: yellow; padding: 5px">/!\ ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</' . $tag . '>';
	}
}

/// The following code is from Parsedown:


#
#
# Parsedown
# http://parsedown.org
#
# (c) Emanuil Rusev
# http://erusev.com
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#

class Parsedown
{
	# ~

	const version = '1.8.0-beta-7';

	# ~

	function text($text)
	{
		$Elements = $this->textElements($text);

		# convert to markup
		$markup = $this->elements($Elements);

		# trim line breaks
		$markup = trim($markup, "\n");

		return $markup;
	}

	protected function textElements($text)
	{
		# make sure no definitions are set
		$this->DefinitionData = array();

		# standardize line breaks
		$text = str_replace(array("\r\n", "\r"), "\n", $text);

		# remove surrounding line breaks
		$text = trim($text, "\n");

		# split text into lines
		$lines = explode("\n", $text);

		# iterate through lines to identify blocks
		return $this->linesElements($lines);
	}

	#
	# Setters
	#

	function setBreaksEnabled($breaksEnabled)
	{
		$this->breaksEnabled = $breaksEnabled;

		return $this;
	}

	protected $breaksEnabled;

	function setMarkupEscaped($markupEscaped)
	{
		$this->markupEscaped = $markupEscaped;

		return $this;
	}

	protected $markupEscaped;

	function setUrlsLinked($urlsLinked)
	{
		$this->urlsLinked = $urlsLinked;

		return $this;
	}

	protected $urlsLinked = true;

	function setSafeMode($safeMode)
	{
		$this->safeMode = (bool) $safeMode;

		return $this;
	}

	protected $safeMode;

	function setStrictMode($strictMode)
	{
		$this->strictMode = (bool) $strictMode;

		return $this;
	}

	protected $strictMode;

	protected $safeLinksWhitelist = array(
		'http://',
		'https://',
		'ftp://',
		'ftps://',
		'mailto:',
		'tel:',
		'data:image/png;base64,',
		'data:image/gif;base64,',
		'data:image/jpeg;base64,',
		'irc:',
		'ircs:',
		'git:',
		'ssh:',
		'news:',
		'steam:',
	);

	#
	# Lines
	#

	protected $BlockTypes = array(
		'#' => array('Header'),
		'*' => array('Rule', 'List'),
		'+' => array('List'),
		'-' => array('SetextHeader', 'Table', 'Rule', 'List'),
		'0' => array('List'),
		'1' => array('List'),
		'2' => array('List'),
		'3' => array('List'),
		'4' => array('List'),
		'5' => array('List'),
		'6' => array('List'),
		'7' => array('List'),
		'8' => array('List'),
		'9' => array('List'),
		':' => array('Table'),
		'<' => array('Comment', 'Markup'),
		'=' => array('SetextHeader'),
		'>' => array('Quote'),
		'[' => array('Reference'),
		'_' => array('Rule'),
		'`' => array('FencedCode'),
		'|' => array('Table'),
		'~' => array('FencedCode'),
	);

	# ~

	protected $unmarkedBlockTypes = array(
		'Code',
	);

	#
	# Blocks
	#

	protected function lines(array $lines)
	{
		return $this->elements($this->linesElements($lines));
	}

	protected function linesElements(array $lines)
	{
		$Elements = array();
		$CurrentBlock = null;

		foreach ($lines as $line)
		{
			if (chop($line) === '')
			{
				if (isset($CurrentBlock))
				{
					$CurrentBlock['interrupted'] = (isset($CurrentBlock['interrupted'])
						? $CurrentBlock['interrupted'] + 1 : 1
					);
				}

				continue;
			}

			while (($beforeTab = strstr($line, "\t", true)) !== false)
			{
				$shortage = 4 - mb_strlen($beforeTab, 'utf-8') % 4;

				$line = $beforeTab
					. str_repeat(' ', $shortage)
					. substr($line, strlen($beforeTab) + 1)
				;
			}

			$indent = strspn($line, ' ');

			$text = $indent > 0 ? substr($line, $indent) : $line;

			# ~

			$Line = array('body' => $line, 'indent' => $indent, 'text' => $text);

			# ~

			if (isset($CurrentBlock['continuable']))
			{
				$methodName = 'block' . $CurrentBlock['type'] . 'Continue';
				$Block = $this->$methodName($Line, $CurrentBlock);

				if (isset($Block))
				{
					$CurrentBlock = $Block;

					continue;
				}
				else
				{
					if ($this->isBlockCompletable($CurrentBlock['type']))
					{
						$methodName = 'block' . $CurrentBlock['type'] . 'Complete';
						$CurrentBlock = $this->$methodName($CurrentBlock);
					}
				}
			}

			# ~

			$marker = $text[0];

			# ~

			$blockTypes = $this->unmarkedBlockTypes;

			if (isset($this->BlockTypes[$marker]))
			{
				foreach ($this->BlockTypes[$marker] as $blockType)
				{
					$blockTypes []= $blockType;
				}
			}

			#
			# ~

			foreach ($blockTypes as $blockType)
			{
				$Block = $this->{"block$blockType"}($Line, $CurrentBlock);

				if (isset($Block))
				{
					$Block['type'] = $blockType;

					if ( ! isset($Block['identified']))
					{
						if (isset($CurrentBlock))
						{
							$Elements[] = $this->extractElement($CurrentBlock);
						}

						$Block['identified'] = true;
					}

					if ($this->isBlockContinuable($blockType))
					{
						$Block['continuable'] = true;
					}

					$CurrentBlock = $Block;

					continue 2;
				}
			}

			# ~

			if (isset($CurrentBlock) and $CurrentBlock['type'] === 'Paragraph')
			{
				$Block = $this->paragraphContinue($Line, $CurrentBlock);
			}

			if (isset($Block))
			{
				$CurrentBlock = $Block;
			}
			else
			{
				if (isset($CurrentBlock))
				{
					$Elements[] = $this->extractElement($CurrentBlock);
				}

				$CurrentBlock = $this->paragraph($Line);

				$CurrentBlock['identified'] = true;
			}
		}

		# ~

		if (isset($CurrentBlock['continuable']) and $this->isBlockCompletable($CurrentBlock['type']))
		{
			$methodName = 'block' . $CurrentBlock['type'] . 'Complete';
			$CurrentBlock = $this->$methodName($CurrentBlock);
		}

		# ~

		if (isset($CurrentBlock))
		{
			$Elements[] = $this->extractElement($CurrentBlock);
		}

		# ~

		return $Elements;
	}

	protected function extractElement(array $Component)
	{
		if ( ! isset($Component['element']))
		{
			if (isset($Component['markup']))
			{
				$Component['element'] = array('rawHtml' => $Component['markup']);
			}
			elseif (isset($Component['hidden']))
			{
				$Component['element'] = array();
			}
		}

		return $Component['element'];
	}

	protected function isBlockContinuable($Type)
	{
		return method_exists($this, 'block' . $Type . 'Continue');
	}

	protected function isBlockCompletable($Type)
	{
		return method_exists($this, 'block' . $Type . 'Complete');
	}

	#
	# Code

	protected function blockCode($Line, $Block = null)
	{
		if (isset($Block) and $Block['type'] === 'Paragraph' and ! isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['indent'] >= 4)
		{
			$text = substr($Line['body'], 4);

			$Block = array(
				'element' => array(
					'name' => 'pre',
					'element' => array(
						'name' => 'code',
						'text' => $text,
					),
				),
			);

			return $Block;
		}
	}

	protected function blockCodeContinue($Line, $Block)
	{
		if ($Line['indent'] >= 4)
		{
			if (isset($Block['interrupted']))
			{
				$Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

				unset($Block['interrupted']);
			}

			$Block['element']['element']['text'] .= "\n";

			$text = substr($Line['body'], 4);

			$Block['element']['element']['text'] .= $text;

			return $Block;
		}
	}

	protected function blockCodeComplete($Block)
	{
		return $Block;
	}

	#
	# Comment

	protected function blockComment($Line)
	{
		if ($this->markupEscaped or $this->safeMode)
		{
			return;
		}

		if (strpos($Line['text'], '<!--') === 0)
		{
			$Block = array(
				'element' => array(
					'rawHtml' => $Line['body'],
					'autobreak' => true,
				),
			);

			if (strpos($Line['text'], '-->') !== false)
			{
				$Block['closed'] = true;
			}

			return $Block;
		}
	}

	protected function blockCommentContinue($Line, array $Block)
	{
		if (isset($Block['closed']))
		{
			return;
		}

		$Block['element']['rawHtml'] .= "\n" . $Line['body'];

		if (strpos($Line['text'], '-->') !== false)
		{
			$Block['closed'] = true;
		}

		return $Block;
	}

	#
	# Fenced Code

	protected function blockFencedCode($Line)
	{
		$marker = $Line['text'][0];

		$openerLength = strspn($Line['text'], $marker);

		if ($openerLength < 3)
		{
			return;
		}

		$infostring = trim(substr($Line['text'], $openerLength), "\t ");

		if (strpos($infostring, '`') !== false)
		{
			return;
		}

		$Element = array(
			'name' => 'code',
			'text' => '',
		);

		if ($infostring !== '')
		{
			/**
			 * https://www.w3.org/TR/2011/WD-html5-20110525/elements.html#classes
			 * Every HTML element may have a class attribute specified.
			 * The attribute, if specified, must have a value that is a set
			 * of space-separated tokens representing the various classes
			 * that the element belongs to.
			 * [...]
			 * The space characters, for the purposes of this specification,
			 * are U+0020 SPACE, U+0009 CHARACTER TABULATION (tab),
			 * U+000A LINE FEED (LF), U+000C FORM FEED (FF), and
			 * U+000D CARRIAGE RETURN (CR).
			 */
			$language = substr($infostring, 0, strcspn($infostring, " \t\n\f\r"));

			$Element['attributes'] = array('class' => "language-$language");
		}

		$Block = array(
			'char' => $marker,
			'openerLength' => $openerLength,
			'element' => array(
				'name' => 'pre',
				'element' => $Element,
			),
		);

		return $Block;
	}

	protected function blockFencedCodeContinue($Line, $Block)
	{
		if (isset($Block['complete']))
		{
			return;
		}

		if (isset($Block['interrupted']))
		{
			$Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

			unset($Block['interrupted']);
		}

		if (($len = strspn($Line['text'], $Block['char'])) >= $Block['openerLength']
			and chop(substr($Line['text'], $len), ' ') === ''
		) {
			$Block['element']['element']['text'] = substr($Block['element']['element']['text'], 1);

			$Block['complete'] = true;

			return $Block;
		}

		$Block['element']['element']['text'] .= "\n" . $Line['body'];

		return $Block;
	}

	protected function blockFencedCodeComplete($Block)
	{
		return $Block;
	}

	#
	# Header

	protected function blockHeader($Line)
	{
		$level = strspn($Line['text'], '#');

		if ($level > 6)
		{
			return;
		}

		$text = trim($Line['text'], '#');

		if ($this->strictMode and isset($text[0]) and $text[0] !== ' ')
		{
			return;
		}

		$text = trim($text, ' ');

		$Block = array(
			'element' => array(
				'name' => 'h' . $level,
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $text,
					'destination' => 'elements',
				)
			),
		);

		return $Block;
	}

	#
	# List

	protected function blockList($Line, array $CurrentBlock = null)
	{
		list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]{1,9}+[.\)]');

		if (preg_match('/^('.$pattern.'([ ]++|$))(.*+)/', $Line['text'], $matches))
		{
			$contentIndent = strlen($matches[2]);

			if ($contentIndent >= 5)
			{
				$contentIndent -= 1;
				$matches[1] = substr($matches[1], 0, -$contentIndent);
				$matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
			}
			elseif ($contentIndent === 0)
			{
				$matches[1] .= ' ';
			}

			$markerWithoutWhitespace = strstr($matches[1], ' ', true);

			$Block = array(
				'indent' => $Line['indent'],
				'pattern' => $pattern,
				'data' => array(
					'type' => $name,
					'marker' => $matches[1],
					'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
				),
				'element' => array(
					'name' => $name,
					'elements' => array(),
				),
			);
			$Block['data']['markerTypeRegex'] = preg_quote($Block['data']['markerType'], '/');

			if ($name === 'ol')
			{
				$listStart = ltrim(strstr($matches[1], $Block['data']['markerType'], true), '0') ?: '0';

				if ($listStart !== '1')
				{
					if (
						isset($CurrentBlock)
						and $CurrentBlock['type'] === 'Paragraph'
						and ! isset($CurrentBlock['interrupted'])
					) {
						return;
					}

					$Block['element']['attributes'] = array('start' => $listStart);
				}
			}

			$Block['li'] = array(
				'name' => 'li',
				'handler' => array(
					'function' => 'li',
					'argument' => !empty($matches[3]) ? array($matches[3]) : array(),
					'destination' => 'elements'
				)
			);

			$Block['element']['elements'] []= & $Block['li'];

			return $Block;
		}
	}

	protected function blockListContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']) and empty($Block['li']['handler']['argument']))
		{
			return null;
		}

		$requiredIndent = ($Block['indent'] + strlen($Block['data']['marker']));

		if ($Line['indent'] < $requiredIndent
			and (
				(
					$Block['data']['type'] === 'ol'
					and preg_match('/^[0-9]++'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
				) or (
					$Block['data']['type'] === 'ul'
					and preg_match('/^'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
				)
			)
		) {
			if (isset($Block['interrupted']))
			{
				$Block['li']['handler']['argument'] []= '';

				$Block['loose'] = true;

				unset($Block['interrupted']);
			}

			unset($Block['li']);

			$text = isset($matches[1]) ? $matches[1] : '';

			$Block['indent'] = $Line['indent'];

			$Block['li'] = array(
				'name' => 'li',
				'handler' => array(
					'function' => 'li',
					'argument' => array($text),
					'destination' => 'elements'
				)
			);

			$Block['element']['elements'] []= & $Block['li'];

			return $Block;
		}
		elseif ($Line['indent'] < $requiredIndent and $this->blockList($Line))
		{
			return null;
		}

		if ($Line['text'][0] === '[' and $this->blockReference($Line))
		{
			return $Block;
		}

		if ($Line['indent'] >= $requiredIndent)
		{
			if (isset($Block['interrupted']))
			{
				$Block['li']['handler']['argument'] []= '';

				$Block['loose'] = true;

				unset($Block['interrupted']);
			}

			$text = substr($Line['body'], $requiredIndent);

			$Block['li']['handler']['argument'] []= $text;

			return $Block;
		}

		if ( ! isset($Block['interrupted']))
		{
			$text = preg_replace('/^[ ]{0,'.$requiredIndent.'}+/', '', $Line['body']);

			$Block['li']['handler']['argument'] []= $text;

			return $Block;
		}
	}

	protected function blockListComplete(array $Block)
	{
		if (isset($Block['loose']))
		{
			foreach ($Block['element']['elements'] as &$li)
			{
				if (end($li['handler']['argument']) !== '')
				{
					$li['handler']['argument'] []= '';
				}
			}
		}

		return $Block;
	}

	#
	# Quote

	protected function blockQuote($Line)
	{
		if (preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches))
		{
			$Block = array(
				'element' => array(
					'name' => 'blockquote',
					'handler' => array(
						'function' => 'linesElements',
						'argument' => (array) $matches[1],
						'destination' => 'elements',
					)
				),
			);

			return $Block;
		}
	}

	protected function blockQuoteContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['text'][0] === '>' and preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches))
		{
			$Block['element']['handler']['argument'] []= $matches[1];

			return $Block;
		}

		if ( ! isset($Block['interrupted']))
		{
			$Block['element']['handler']['argument'] []= $Line['text'];

			return $Block;
		}
	}

	#
	# Rule

	protected function blockRule($Line)
	{
		$marker = $Line['text'][0];

		if (substr_count($Line['text'], $marker) >= 3 and chop($Line['text'], " $marker") === '')
		{
			$Block = array(
				'element' => array(
					'name' => 'hr',
				),
			);

			return $Block;
		}
	}

	#
	# Setext

	protected function blockSetextHeader($Line, array $Block = null)
	{
		if ( ! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['indent'] < 4 and chop(chop($Line['text'], ' '), $Line['text'][0]) === '')
		{
			$Block['element']['name'] = $Line['text'][0] === '=' ? 'h1' : 'h2';

			return $Block;
		}
	}

	#
	# Markup

	protected function blockMarkup($Line)
	{
		if ($this->markupEscaped or $this->safeMode)
		{
			return;
		}

		if (preg_match('/^<[\/]?+(\w*)(?:[ ]*+'.$this->regexHtmlAttribute.')*+[ ]*+(\/)?>/', $Line['text'], $matches))
		{
			$element = strtolower($matches[1]);

			if (in_array($element, $this->textLevelElements))
			{
				return;
			}

			$Block = array(
				'name' => $matches[1],
				'element' => array(
					'rawHtml' => $Line['text'],
					'autobreak' => true,
				),
			);

			return $Block;
		}
	}

	protected function blockMarkupContinue($Line, array $Block)
	{
		if (isset($Block['closed']) or isset($Block['interrupted']))
		{
			return;
		}

		if (!isset($Block['element']['rawHtml'])) {
			$Block['element']['rawHtml'] = '';
		}

		$Block['element']['rawHtml'] .= "\n" . $Line['body'];

		return $Block;
	}

	#
	# Reference

	protected function blockReference($Line)
	{
		if (strpos($Line['text'], ']') !== false
			and preg_match('/^\[(.+?)\]:[ ]*+<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*+$/', $Line['text'], $matches)
		) {
			$id = strtolower($matches[1]);

			$Data = array(
				'url' => $matches[2],
				'title' => isset($matches[3]) ? $matches[3] : null,
			);

			$this->DefinitionData['Reference'][$id] = $Data;

			$Block = array(
				'element' => array(),
			);

			return $Block;
		}
	}

	#
	# Table

	protected function blockTable($Line, array $Block = null)
	{
		if ( ! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted']))
		{
			return;
		}

		if (
			strpos($Block['element']['handler']['argument'], '|') === false
			and strpos($Line['text'], '|') === false
			and strpos($Line['text'], ':') === false
			or strpos($Block['element']['handler']['argument'], "\n") !== false
		) {
			return;
		}

		if (chop($Line['text'], ' -:|') !== '')
		{
			return;
		}

		$alignments = array();

		$divider = $Line['text'];

		$divider = trim($divider);
		$divider = trim($divider, '|');

		$dividerCells = explode('|', $divider);

		foreach ($dividerCells as $dividerCell)
		{
			$dividerCell = trim($dividerCell);

			if ($dividerCell === '')
			{
				return;
			}

			$alignment = null;

			if ($dividerCell[0] === ':')
			{
				$alignment = 'left';
			}

			if (substr($dividerCell, - 1) === ':')
			{
				$alignment = $alignment === 'left' ? 'center' : 'right';
			}

			$alignments []= $alignment;
		}

		# ~

		$HeaderElements = array();

		$header = $Block['element']['handler']['argument'];

		$header = trim($header);
		$header = trim($header, '|');

		$headerCells = explode('|', $header);

		if (count($headerCells) !== count($alignments))
		{
			return;
		}

		foreach ($headerCells as $index => $headerCell)
		{
			$headerCell = trim($headerCell);

			$HeaderElement = array(
				'name' => 'th',
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $headerCell,
					'destination' => 'elements',
				)
			);

			if (isset($alignments[$index]))
			{
				$alignment = $alignments[$index];

				$HeaderElement['attributes'] = array(
					'style' => "text-align: $alignment;",
				);
			}

			$HeaderElements []= $HeaderElement;
		}

		# ~

		$Block = array(
			'alignments' => $alignments,
			'identified' => true,
			'element' => array(
				'name' => 'table',
				'elements' => array(),
			),
		);

		$Block['element']['elements'] []= array(
			'name' => 'thead',
		);

		$Block['element']['elements'] []= array(
			'name' => 'tbody',
			'elements' => array(),
		);

		$Block['element']['elements'][0]['elements'] []= array(
			'name' => 'tr',
			'elements' => $HeaderElements,
		);

		return $Block;
	}

	protected function blockTableContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		if (count($Block['alignments']) === 1 or $Line['text'][0] === '|' or strpos($Line['text'], '|'))
		{
			$Elements = array();

			$row = $Line['text'];

			$row = trim($row);
			$row = trim($row, '|');

			preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', $row, $matches);

			$cells = array_slice($matches[0], 0, count($Block['alignments']));

			foreach ($cells as $index => $cell)
			{
				$cell = trim($cell);

				$Element = array(
					'name' => 'td',
					'handler' => array(
						'function' => 'lineElements',
						'argument' => $cell,
						'destination' => 'elements',
					)
				);

				if (isset($Block['alignments'][$index]))
				{
					$Element['attributes'] = array(
						'style' => 'text-align: ' . $Block['alignments'][$index] . ';',
					);
				}

				$Elements []= $Element;
			}

			$Element = array(
				'name' => 'tr',
				'elements' => $Elements,
			);

			$Block['element']['elements'][1]['elements'] []= $Element;

			return $Block;
		}
	}

	#
	# ~
	#

	protected function paragraph($Line)
	{
		return array(
			'type' => 'Paragraph',
			'element' => array(
				'name' => 'p',
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $Line['text'],
					'destination' => 'elements',
				),
			),
		);
	}

	protected function paragraphContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		$Block['element']['handler']['argument'] .= "\n".$Line['text'];

		return $Block;
	}

	#
	# Inline Elements
	#

	protected $InlineTypes = array(
		'!' => array('Image'),
		'&' => array('SpecialCharacter'),
		'*' => array('Emphasis'),
		':' => array('Url'),
		'<' => array('UrlTag', 'EmailTag', 'Markup'),
		'[' => array('Link'),
		'_' => array('Emphasis'),
		'`' => array('Code'),
		'~' => array('Strikethrough'),
		'\\' => array('EscapeSequence'),
	);

	# ~

	protected $inlineMarkerList = '!*_&[:<`~\\';

	#
	# ~
	#

	public function line($text, $nonNestables = array())
	{
		return $this->elements($this->lineElements($text, $nonNestables));
	}

	protected function lineElements($text, $nonNestables = array())
	{
		# standardize line breaks
		$text = str_replace(array("\r\n", "\r"), "\n", $text);

		$Elements = array();

		$nonNestables = (empty($nonNestables)
			? array()
			: array_combine($nonNestables, $nonNestables)
		);

		# $excerpt is based on the first occurrence of a marker

		while ($excerpt = strpbrk($text, $this->inlineMarkerList))
		{
			$marker = $excerpt[0];

			$markerPosition = strlen($text) - strlen($excerpt);

			$Excerpt = array('text' => $excerpt, 'context' => $text);

			foreach ($this->InlineTypes[$marker] as $inlineType)
			{
				# check to see if the current inline type is nestable in the current context

				if (isset($nonNestables[$inlineType]))
				{
					continue;
				}

				$Inline = $this->{"inline$inlineType"}($Excerpt);

				if ( ! isset($Inline))
				{
					continue;
				}

				# makes sure that the inline belongs to "our" marker

				if (isset($Inline['position']) and $Inline['position'] > $markerPosition)
				{
					continue;
				}

				# sets a default inline position

				if ( ! isset($Inline['position']))
				{
					$Inline['position'] = $markerPosition;
				}

				# cause the new element to 'inherit' our non nestables


				$Inline['element']['nonNestables'] = isset($Inline['element']['nonNestables'])
					? array_merge($Inline['element']['nonNestables'], $nonNestables)
					: $nonNestables
				;

				# the text that comes before the inline
				$unmarkedText = substr($text, 0, $Inline['position']);

				# compile the unmarked text
				$InlineText = $this->inlineText($unmarkedText);
				$Elements[] = $InlineText['element'];

				# compile the inline
				$Elements[] = $this->extractElement($Inline);

				# remove the examined text
				$text = substr($text, $Inline['position'] + ($Inline['extent'] ?? 0));

				continue 2;
			}

			# the marker does not belong to an inline

			$unmarkedText = substr($text, 0, $markerPosition + 1);

			$InlineText = $this->inlineText($unmarkedText);
			$Elements[] = $InlineText['element'];

			$text = substr($text, $markerPosition + 1);
		}

		$InlineText = $this->inlineText($text);
		$Elements[] = $InlineText['element'];

		foreach ($Elements as &$Element)
		{
			if ( ! isset($Element['autobreak']))
			{
				$Element['autobreak'] = false;
			}
		}

		return $Elements;
	}

	#
	# ~
	#

	protected function inlineText($text)
	{
		$Inline = array(
			'extent' => strlen($text),
			'element' => array(),
		);

		$Inline['element']['elements'] = self::pregReplaceElements(
			$this->breaksEnabled ? '/[ ]*+\n/' : '/(?:[ ]*+\\\\|[ ]{2,}+)\n/',
			array(
				array('name' => 'br'),
				array('text' => "\n"),
			),
			$text
		);

		return $Inline;
	}

	protected function inlineCode($Excerpt)
	{
		$marker = $Excerpt['text'][0];

		if (preg_match('/^(['.$marker.']++)[ ]*+(.+?)[ ]*+(?<!['.$marker.'])\1(?!'.$marker.')/s', $Excerpt['text'], $matches))
		{
			$text = $matches[2];
			$text = preg_replace('/[ ]*+\n/', ' ', $text);

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'code',
					'text' => $text,
				),
			);
		}
	}

	protected function inlineEmailTag($Excerpt)
	{
		$hostnameLabel = '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';

		$commonMarkEmail = '[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]++@'
			. $hostnameLabel . '(?:\.' . $hostnameLabel . ')*';

		if (strpos($Excerpt['text'], '>') !== false
			and preg_match("/^<((mailto:)?$commonMarkEmail)>/i", $Excerpt['text'], $matches)
		){
			$url = $matches[1];

			if ( ! isset($matches[2]))
			{
				$url = "mailto:$url";
			}

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'a',
					'text' => $matches[1],
					'attributes' => array(
						'href' => $url,
					),
				),
			);
		}
	}

	protected function inlineEmphasis($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]))
		{
			return;
		}

		$marker = $Excerpt['text'][0];

		if ($Excerpt['text'][1] === $marker and preg_match($this->StrongRegex[$marker], $Excerpt['text'], $matches))
		{
			$emphasis = 'strong';
		}
		elseif (preg_match($this->EmRegex[$marker], $Excerpt['text'], $matches))
		{
			$emphasis = 'em';
		}
		else
		{
			return;
		}

		return array(
			'extent' => strlen($matches[0]),
			'element' => array(
				'name' => $emphasis,
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $matches[1],
					'destination' => 'elements',
				)
			),
		);
	}

	protected function inlineEscapeSequence($Excerpt)
	{
		if (isset($Excerpt['text'][1]) and in_array($Excerpt['text'][1], $this->specialCharacters))
		{
			return array(
				'element' => array('rawHtml' => $Excerpt['text'][1]),
				'extent' => 2,
			);
		}
	}

	protected function inlineImage($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]) or $Excerpt['text'][1] !== '[')
		{
			return;
		}

		$Excerpt['text']= substr($Excerpt['text'], 1);

		$Link = $this->inlineLink($Excerpt);

		if ($Link === null)
		{
			return;
		}

		$Inline = array(
			'extent' => $Link['extent'] + 1,
			'element' => array(
				'name' => 'img',
				'attributes' => array(
					'src' => $Link['element']['attributes']['href'],
					'alt' => $Link['element']['handler']['argument'],
				),
				'autobreak' => true,
			),
		);

		$Inline['element']['attributes'] += $Link['element']['attributes'];

		unset($Inline['element']['attributes']['href']);

		return $Inline;
	}

	protected function inlineLink($Excerpt)
	{
		$Element = array(
			'name' => 'a',
			'handler' => array(
				'function' => 'lineElements',
				'argument' => null,
				'destination' => 'elements',
			),
			'nonNestables' => array('Url', 'Link'),
			'attributes' => array(
				'href' => null,
				'title' => null,
			),
		);

		$extent = 0;

		$remainder = $Excerpt['text'];

		if (preg_match('/\[((?:[^][]++|(?R))*+)\]/', $remainder, $matches))
		{
			$Element['handler']['argument'] = $matches[1];

			$extent += strlen($matches[0]);

			$remainder = substr($remainder, $extent);
		}
		else
		{
			return;
		}

		if (preg_match('/^[(]\s*+((?:[^ ()]++|[(][^ )]+[)])++)(?:[ ]+("[^"]*+"|\'[^\']*+\'))?\s*+[)]/', $remainder, $matches))
		{
			$Element['attributes']['href'] = $matches[1];

			if (isset($matches[2]))
			{
				$Element['attributes']['title'] = substr($matches[2], 1, - 1);
			}

			$extent += strlen($matches[0]);
		}
		else
		{
			if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches))
			{
				$definition = strlen($matches[1]) ? $matches[1] : $Element['handler']['argument'];
				$definition = strtolower($definition);

				$extent += strlen($matches[0]);
			}
			else
			{
				$definition = strtolower($Element['handler']['argument']);
			}

			if ( ! isset($this->DefinitionData['Reference'][$definition]))
			{
				return;
			}

			$Definition = $this->DefinitionData['Reference'][$definition];

			$Element['attributes']['href'] = $Definition['url'];
			$Element['attributes']['title'] = $Definition['title'];
		}

		return array(
			'extent' => $extent,
			'element' => $Element,
		);
	}

	protected function inlineMarkup($Excerpt)
	{
		if ($this->markupEscaped or $this->safeMode or strpos($Excerpt['text'], '>') === false)
		{
			return;
		}

		if ($Excerpt['text'][1] === '/' and preg_match('/^<\/\w[\w-]*+[ ]*+>/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}

		if ($Excerpt['text'][1] === '!' and preg_match('/^<!---?[^>-](?:-?+[^-])*-->/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}

		if ($Excerpt['text'][1] !== ' ' and preg_match('/^<\w[\w-]*+(?:[ ]*+'.$this->regexHtmlAttribute.')*+[ ]*+\/?>/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}
	}

	protected function inlineSpecialCharacter($Excerpt)
	{
		if (substr($Excerpt['text'], 1, 1) !== ' ' and strpos($Excerpt['text'], ';') !== false
			and preg_match('/^&(#?+[0-9a-zA-Z]++);/', $Excerpt['text'], $matches)
		) {
			return array(
				'element' => array('rawHtml' => '&' . $matches[1] . ';'),
				'extent' => strlen($matches[0]),
			);
		}

		return;
	}

	protected function inlineStrikethrough($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]))
		{
			return;
		}

		if ($Excerpt['text'][1] === '~' and preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $Excerpt['text'], $matches))
		{
			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'del',
					'handler' => array(
						'function' => 'lineElements',
						'argument' => $matches[1],
						'destination' => 'elements',
					)
				),
			);
		}
	}

	protected function inlineUrl($Excerpt)
	{
		if ($this->urlsLinked !== true or ! isset($Excerpt['text'][2]) or $Excerpt['text'][2] !== '/')
		{
			return;
		}

		if (strpos($Excerpt['context'], 'http') !== false
			and preg_match('/\bhttps?+:[\/]{2}[^\s<]+\b\/*+/ui', $Excerpt['context'], $matches, PREG_OFFSET_CAPTURE)
		) {
			$url = $matches[0][0];

			$Inline = array(
				'extent' => strlen($matches[0][0]),
				'position' => $matches[0][1],
				'element' => array(
					'name' => 'a',
					'text' => $url,
					'attributes' => array(
						'href' => $url,
					),
				),
			);

			return $Inline;
		}
	}

	protected function inlineUrlTag($Excerpt)
	{
		if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(\w++:\/{2}[^ >]++)>/i', $Excerpt['text'], $matches))
		{
			$url = $matches[1];

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'a',
					'text' => $url,
					'attributes' => array(
						'href' => $url,
					),
				),
			);
		}
	}

	# ~

	protected function unmarkedText($text)
	{
		$Inline = $this->inlineText($text);
		return $this->element($Inline['element']);
	}

	#
	# Handlers
	#

	protected function handle(array $Element)
	{
		if (isset($Element['handler']))
		{
			if (!isset($Element['nonNestables']))
			{
				$Element['nonNestables'] = array();
			}

			if (is_string($Element['handler']))
			{
				$function = $Element['handler'];
				$argument = $Element['text'];
				unset($Element['text']);
				$destination = 'rawHtml';
			}
			else
			{
				$function = $Element['handler']['function'];
				$argument = $Element['handler']['argument'];
				$destination = $Element['handler']['destination'];
			}

			$Element[$destination] = $this->{$function}($argument, $Element['nonNestables']);

			if ($destination === 'handler')
			{
				$Element = $this->handle($Element);
			}

			unset($Element['handler']);
		}

		return $Element;
	}

	protected function handleElementRecursive(array $Element)
	{
		return $this->elementApplyRecursive(array($this, 'handle'), $Element);
	}

	protected function handleElementsRecursive(array $Elements)
	{
		return $this->elementsApplyRecursive(array($this, 'handle'), $Elements);
	}

	protected function elementApplyRecursive($closure, array $Element)
	{
		$Element = call_user_func($closure, $Element);

		if (isset($Element['elements']))
		{
			$Element['elements'] = $this->elementsApplyRecursive($closure, $Element['elements']);
		}
		elseif (isset($Element['element']))
		{
			$Element['element'] = $this->elementApplyRecursive($closure, $Element['element']);
		}

		return $Element;
	}

	protected function elementApplyRecursiveDepthFirst($closure, array $Element)
	{
		if (isset($Element['elements']))
		{
			$Element['elements'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['elements']);
		}
		elseif (isset($Element['element']))
		{
			$Element['element'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['element']);
		}

		$Element = call_user_func($closure, $Element);

		return $Element;
	}

	protected function elementsApplyRecursive($closure, array $Elements)
	{
		foreach ($Elements as &$Element)
		{
			$Element = $this->elementApplyRecursive($closure, $Element);
		}

		return $Elements;
	}

	protected function elementsApplyRecursiveDepthFirst($closure, array $Elements)
	{
		foreach ($Elements as &$Element)
		{
			$Element = $this->elementApplyRecursiveDepthFirst($closure, $Element);
		}

		return $Elements;
	}

	protected function element(array $Element)
	{
		if ($this->safeMode)
		{
			$Element = $this->sanitiseElement($Element);
		}

		# identity map if element has no handler
		$Element = $this->handle($Element);

		$hasName = isset($Element['name']);

		$markup = '';

		if ($hasName)
		{
			$markup .= '<' . $Element['name'];

			if (isset($Element['attributes']))
			{
				foreach ($Element['attributes'] as $name => $value)
				{
					if ($value === null)
					{
						continue;
					}

					$markup .= " $name=\"".self::escape($value).'"';
				}
			}
		}

		$permitRawHtml = false;

		if (isset($Element['text']))
		{
			$text = $Element['text'];
		}
		// very strongly consider an alternative if you're writing an
		// extension
		elseif (isset($Element['rawHtml']))
		{
			$text = $Element['rawHtml'];

			$allowRawHtmlInSafeMode = isset($Element['allowRawHtmlInSafeMode']) && $Element['allowRawHtmlInSafeMode'];
			$permitRawHtml = !$this->safeMode || $allowRawHtmlInSafeMode;
		}

		$hasContent = isset($text) || isset($Element['element']) || isset($Element['elements']);

		if ($hasContent)
		{
			$markup .= $hasName ? '>' : '';

			if (isset($Element['elements']))
			{
				$markup .= $this->elements($Element['elements']);
			}
			elseif (isset($Element['element']))
			{
				$markup .= $this->element($Element['element']);
			}
			else
			{
				if (!$permitRawHtml)
				{
					$markup .= self::escape($text, true);
				}
				else
				{
					$markup .= $text;
				}
			}

			$markup .= $hasName ? '</' . $Element['name'] . '>' : '';
		}
		elseif ($hasName)
		{
			$markup .= ' />';
		}

		return $markup;
	}

	protected function elements(array $Elements)
	{
		$markup = '';

		$autoBreak = true;

		foreach ($Elements as $Element)
		{
			if (empty($Element))
			{
				continue;
			}

			$autoBreakNext = (isset($Element['autobreak'])
				? $Element['autobreak'] : isset($Element['name'])
			);
			// (autobreak === false) covers both sides of an element
			$autoBreak = !$autoBreak ? $autoBreak : $autoBreakNext;

			$markup .= ($autoBreak ? "\n" : '') . $this->element($Element);
			$autoBreak = $autoBreakNext;
		}

		$markup .= $autoBreak ? "\n" : '';

		return $markup;
	}

	# ~

	protected function li($lines)
	{
		$Elements = $this->linesElements($lines);

		if ( ! in_array('', $lines)
			and isset($Elements[0]) and isset($Elements[0]['name'])
			and $Elements[0]['name'] === 'p'
		) {
			unset($Elements[0]['name']);
		}

		return $Elements;
	}

	#
	# AST Convenience
	#

	/**
	 * Replace occurrences $regexp with $Elements in $text. Return an array of
	 * elements representing the replacement.
	 */
	protected static function pregReplaceElements($regexp, $Elements, $text)
	{
		$newElements = array();

		while (preg_match($regexp, $text, $matches, PREG_OFFSET_CAPTURE))
		{
			$offset = $matches[0][1];
			$before = substr($text, 0, $offset);
			$after = substr($text, $offset + strlen($matches[0][0]));

			$newElements[] = array('text' => $before);

			foreach ($Elements as $Element)
			{
				$newElements[] = $Element;
			}

			$text = $after;
		}

		$newElements[] = array('text' => $text);

		return $newElements;
	}

	#
	# Deprecated Methods
	#

	function parse($text)
	{
		$markup = $this->text($text);

		return $markup;
	}

	protected function sanitiseElement(array $Element)
	{
		static $goodAttribute = '/^[a-zA-Z0-9][a-zA-Z0-9-_]*+$/';
		static $safeUrlNameToAtt  = array(
			'a'   => 'href',
			'img' => 'src',
		);

		if ( ! isset($Element['name']))
		{
			unset($Element['attributes']);
			return $Element;
		}

		if (isset($safeUrlNameToAtt[$Element['name']]))
		{
			$Element = $this->filterUnsafeUrlInAttribute($Element, $safeUrlNameToAtt[$Element['name']]);
		}

		if ( ! empty($Element['attributes']))
		{
			foreach ($Element['attributes'] as $att => $val)
			{
				# filter out badly parsed attribute
				if ( ! preg_match($goodAttribute, $att))
				{
					unset($Element['attributes'][$att]);
				}
				# dump onevent attribute
				elseif (self::striAtStart($att, 'on'))
				{
					unset($Element['attributes'][$att]);
				}
			}
		}

		return $Element;
	}

	protected function filterUnsafeUrlInAttribute(array $Element, $attribute)
	{
		foreach ($this->safeLinksWhitelist as $scheme)
		{
			if (self::striAtStart($Element['attributes'][$attribute], $scheme))
			{
				return $Element;
			}
		}

		$Element['attributes'][$attribute] = str_replace(':', '%3A', $Element['attributes'][$attribute]);

		return $Element;
	}

	#
	# Static Methods
	#

	protected static function escape($text, $allowQuotes = false)
	{
		return htmlspecialchars($text, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8');
	}

	protected static function striAtStart($string, $needle)
	{
		$len = strlen($needle);

		if ($len > strlen($string))
		{
			return false;
		}
		else
		{
			return strtolower(substr($string, 0, $len)) === strtolower($needle);
		}
	}

	static function instance($name = 'default')
	{
		if (isset(self::$instances[$name]))
		{
			return self::$instances[$name];
		}

		$instance = new static();

		self::$instances[$name] = $instance;

		return $instance;
	}

	private static $instances = array();

	#
	# Fields
	#

	protected $DefinitionData;

	#
	# Read-Only

	protected $specialCharacters = array(
		'\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '>', '#', '+', '-', '.', '!', '|', '~'
	);

	protected $StrongRegex = array(
		'*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*+[*])+?)[*]{2}(?![*])/s',
		'_' => '/^__((?:\\\\_|[^_]|_[^_]*+_)+?)__(?!_)/us',
	);

	protected $EmRegex = array(
		'*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
		'_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
	);

	protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*+(?:\s*+=\s*+(?:[^"\'=<>`\s]+|"[^"]*+"|\'[^\']*+\'))?+';

	protected $voidElements = array(
		'area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source',
	);

	protected $textLevelElements = array(
		'a', 'br', 'bdo', 'abbr', 'blink', 'nextid', 'acronym', 'basefont',
		'b', 'em', 'big', 'cite', 'small', 'spacer', 'listing',
		'i', 'rp', 'del', 'code',          'strike', 'marquee',
		'q', 'rt', 'ins', 'font',          'strong',
		's', 'tt', 'kbd', 'mark',
		'u', 'xm', 'sub', 'nobr',
				   'sup', 'ruby',
				   'var', 'span',
				   'wbr', 'time',
	);
}
