<?php
namespace Ridibooks\Library\EPub;

// TODO: builder 버전에 따른 처리 추가 (캐시가 남아있는 문제가 있음)

class Bom2EPubConverter
{
	private $b_id;

	private $input_dir;
	private $output_dir;
	/**
	 * key with [title, pub_date, author, publisher_name, isbn10, isbn13]
	 * @var array
	 */
	private $book_config;

	public function __construct($b_id, $input_dir, $output_dir, $book_config = [])
	{
		ini_set('pcre.backtrack_limit', PHP_INT_MAX);
		ini_set('pcre.recursion_limit', PHP_INT_MAX);

		$this->b_id = preg_replace("/\D/", "", $b_id);

		$this->input_dir = $input_dir;
		$this->output_dir = $output_dir;
		$this->book_config = $book_config;
	}

	public function getEPubPath()
	{
		return $this->output_dir . "/{$this->b_id}.epub";
	}

	private function findLargestCover()
	{
		$coverSrc = $this->input_dir . '/' . $this->b_id . '_org.jpg';
		if (is_file($coverSrc)) {
			return basename($coverSrc);
		}

		return null;
	}

	private function copyImages()
	{
		exec("cp -rv {$this->input_dir}/*.jpg epub/OEBPS/Images/ 2>&1");
		exec("cp -rv {$this->input_dir}/*.jpeg epub/OEBPS/Images/ 2>&1");
		exec("cp -v {$this->input_dir}/*.png epub/OEBPS/Images/ 2>&1");
		exec("cp -v {$this->input_dir}/*.gif epub/OEBPS/Images/ 2>&1");
	}

	private function getImageList()
	{
		$b_id = $this->b_id;

		$imgs = array_merge(
			glob("epub/OEBPS/Images/*.jpg"),
			glob("epub/OEBPS/Images/*.jpeg"),
			glob("epub/OEBPS/Images/*.png"),
			glob("epub/OEBPS/Images/*.gif")
		);

		$IMGTAGS = '';

		foreach ($imgs as $img) {
			if ($b_id . '_org.jpg' == basename($img)) {
				continue;
			}
			$img = $this->basename_utf8($img);
			$img_id = 'id.' . preg_replace('/\W/i', '', base64_encode($img));
			if (preg_match('/\.jpg$/i', $img) || preg_match('/\.jpeg$/i', $img)) {
				$type = 'image/jpeg';
			} elseif (preg_match('/\.png$/i', $img)) {
				$type = 'image/png';
			} else {
				$type = 'image/gif';
			}
			$IMGTAGS .= '<item id="' . $img_id . '" href="Images/' . $img . '" media-type="' . $type . '"/>' . "\n";
			passthru(
				"convert \"epub/OEBPS/Images/{$img}\" -flatten -colorspace sRGB \"epub/OEBPS/Images/{$img}\" 2>&1"
			);
		}

		// 표지 1장만 제외하고 모든 이미지 삭제
		$coverImg = $this->findLargestCover();
		copy("epub/OEBPS/Images/{$coverImg}", "epub/OEBPS/Images/__epub_main_cover.jpg");
		passthru(
			"convert epub/OEBPS/Images/__epub_main_cover.jpg -colorspace sRGB epub/OEBPS/Images/__epub_main_cover.jpg"
		);
		@unlink("epub/OEBPS/Images/" . $b_id . '_org.jpg');

		return [$imgs, $IMGTAGS];
	}

	private function getMetadata()
	{
		//TODO get from DB
		$bookDataFromDB = $this->book_config;

		$meta = [];
		$title = $bookDataFromDB['title'];
		if (strlen($title) <= 0) {
			$title = 'unknown title';
		}
		$meta['TITLE'] = $title;
		$meta['PUB_DATE'] = preg_replace('/(\d{4})(\d{2})(\d{2})/', '\1-\2-\3', $bookDataFromDB['pub_date']);
		$meta['PUB_DATE'] = date('Y-m-d', strtotime($meta['PUB_DATE']));
		$meta['AUTHOR'] = $bookDataFromDB['author'];
		$meta['PUB'] = $bookDataFromDB['publisher_name'];
		$meta['IDENTIFIER'] = 'ridi:' . $this->b_id;
		$isbn10 = preg_replace('/\W/', '', $bookDataFromDB['isbn10']);
		if (strlen($isbn10) == 10) {
			$meta['IDENTIFIER'] = 'isbn:' . $isbn10;
		}
		$isbn13 = preg_replace('/\W/', '', $bookDataFromDB['isbn13']);
		if (strlen($isbn13) == 13) {
			$meta['IDENTIFIER'] = 'isbn:' . $isbn13;
		}

		return $meta;
	}

	private function convert()
	{
		$contentsFilePostfix = $this->input_dir . '/' . $this->b_id;

		//get body
		{
			$txtFile = $contentsFilePostfix . '.txt';
			$body = file_get_contents($txtFile);
			$body = StringUtil::removeUnnecessaryCharacter($body);
			$bodys = preg_split('/\n(?=[^\n]*\{PAGE)/i', $body);
		}

		//trim bodys
		foreach ($bodys as $k => $body) {
			if (strlen(trim($body)) == 0) {
				unset($bodys[$k]);
			}
		}
		$bodys = array_values($bodys);

		$navpointKey = '__ridi__navpoint__ASDHAOSHDSUAFAISOFHIUAS__';
		$titles = [];
		$title2body_map = []; //title_num => body_num

		//make meta title
		$_this = $this;
		foreach ($bodys as $body_num => $body) {
			$make_metatitle_regex = '/
				\{INDEX
					\s*
					(?:title=
						(?:
							"([^"]+)"
							|
							\'([^\']+)\'
						)
					)?
					(?:[^\}]*)
				\}
				([^\r\n]*)
				/ix';
			$make_metatitle_callback = function ($mat) use ($_this, &$titles, $navpointKey) {
				$title = '';
				if (strlen($mat[1])) {
					$title = $mat[1];
				} else {
					if (strlen($mat[2])) {
						$title = $mat[2];
					} else {
						if (strlen($mat[3])) {
							$title = $_this->removeBomTagsForIndex($mat[3]);
						}
					}
				}

				$ret = '';
				if (strlen($title)) {
					$index = count($titles);
					$titles[] = $title;
					$ret .= $navpointKey . $index . $navpointKey;
				}
				$ret .= $mat[3];

				return $ret;
			};
			$bodys[$body_num] = preg_replace_callback($make_metatitle_regex, $make_metatitle_callback, $body);
		}

		//process body
		list($imgs, $IMGTAGS) = $this->getImageList();

		$newbodies = [];
		$newIndex = 0;
		foreach ($bodys as $body) {
			$body = $this->formatting_contents($body, $imgs);

			{
				//index 바로뒤에 page[imgbegin] 가 있는경우, 위치 교체
				// ex : {INDEX title="왕의 녹차, 하동"/}{IMG src="002.jpg" fullscreen="true"}
				$indexPageSwitcheRegex = '/(' . $navpointKey . '\d+' . $navpointKey . ')(<page imgbegin\/>)/i';
				$body = preg_replace($indexPageSwitcheRegex, "\\2\\1", $body);
			}

			$bodySplits = array_filter(preg_split('/<page([^>]*)>(<page([^>]*)>|\s|<br\/>)*/i', $body));
			foreach ($bodySplits as $bodySplit) {
				$bodySplit = $this->recoverHtmlTags($bodySplit);
				$newbodies[$newIndex] = $bodySplit;
				$newIndex++;
			}
		}
		$bodys = $newbodies;

		//remove metatitle
		foreach ($bodys as $body_num => $body) {
			$convert_metatitle_regex = '/' . $navpointKey . '(\d+)' . $navpointKey . '/';
			$convert_metatitle_callback = function ($mat) use (&$title2body_map, $body_num) {
				$title_num = $mat[1];
				$title2body_map[$title_num] = $body_num;

				return '<pre id="__ridi__navpoint__' . $title_num . '" style="display: inline;" ></pre>';
			};
			$bodys[$body_num] = preg_replace_callback($convert_metatitle_regex, $convert_metatitle_callback, $body);
		}

		//build navPoint, manifest, spine
		$navPoint = '';
		foreach ($title2body_map as $title_num => $body_num) {
			$navPoint .= '
			<navPoint id="navPoint-' . ($title_num + 2) . '" playOrder="' . ($title_num + 2) . '">
				<navLabel>
					<text>' . htmlspecialchars($titles[$title_num]) . '</text>
				</navLabel>
				<content src="Text/Section' . sprintf("%04d", $body_num) . '.html#__ridi__navpoint__' . $title_num . '"/>
			</navPoint>
			';
		}
		$manifest = '';
		foreach ($bodys as $body_num => $body) {
			$manifest .= '<item id="Section' . sprintf("%04d", $body_num) . '.html" href="Text/Section' .
				sprintf("%04d", $body_num) . '.html" media-type="application/xhtml+xml" />';
		}
		$spine = '';
		foreach ($bodys as $body_num => $body) {
			$spine .= '<itemref idref="Section' . sprintf("%04d", $body_num) . '.html"/>';
		}

		$meta = $this->getMetadata();

		//update toc.ncx
		$toc = file_get_contents("epub/OEBPS/toc.ncx");
		$toc = str_replace("{{INDEXS}}", $navPoint, $toc);
		$toc = $this->replace_meta($meta, $toc);
		file_put_contents("epub/OEBPS/toc.ncx", $toc);

		//update content.opf
		$contents = file_get_contents("epub/OEBPS/content.opf");
		$contents = str_replace("{{IMG}}", $IMGTAGS, $contents);
		$contents = str_replace("{{MANIFEST}}", $manifest, $contents);
		$contents = str_replace("{{SPINE}}", $spine, $contents);
		$contents = $this->replace_meta($meta, $contents);
		file_put_contents("epub/OEBPS/content.opf", $contents);

		//update body
		$contents = file_get_contents("epub/OEBPS/Text/Section0000.html");
		$i = 0;
		foreach ($bodys as $body) {
			$contentsWithBody = str_replace("{{BODY}}", $body, $contents);
			file_put_contents("epub/OEBPS/Text/Section" . sprintf("%04d", $i) . ".html", $contentsWithBody);
			$i++;
		}
	}

	private function getTemplateDir()
	{
		return realpath(__DIR__ . '/../../template');
	}

	private function pack()
	{
		chdir('epub');
		@unlink("../{$this->b_id}.epub");
		exec("zip -0 ../{$this->b_id}.epub mimetype");
		exec("zip -r ../{$this->b_id}.epub META-INF OEBPS");
		chdir('..');
	}

	public function buildEpub($force_rebuild = false)
	{
		@mkdir($this->output_dir, 0777, true);

		if (!is_dir($this->output_dir)) {
			throw new \Exception('Output directory not exists: ' . $this->output_dir);
		}

		if (!is_dir($this->getTemplateDir())) {
			throw new \Exception("Can't create temporary directory");
		}

		$epub_file = $this->output_dir . '/' . $this->b_id . '.epub';
		if (!$force_rebuild && is_file($epub_file)) {
			return;
		}

		chdir($this->output_dir);
		@mkdir('epub');

		// prepare working directory
		exec('rm -rf epub');
		exec('cp -rfv ' . $this->getTemplateDir() . ' epub 2>&1');

		$this->copyImages();
		$this->convert();
		$this->pack();

		// clear
		exec('rm -rf epub');
	}

	/**
	 * @param $title
	 *
	 * @return string
	 */
	public function removeBomTagsForIndex($title)
	{
		//위첨자 아래첨자는 목차에서 제외
		{
			$title = preg_replace('/\{SUP\}.+\{\/SUP\}/Uis', '', $title);
			$title = preg_replace('/\{SUB\}.+\{\/SUB\}/Uis', '', $title);
		}
		$title = preg_replace('/\{[^\{\}]+\}/Us', '', $title);

		return strval($title);
	}

	private function recoverHtmlTags($bodySplit)
	{
		global $recoverBomTagsStack;
		$recoverBomTagsStack = [];
		$ret = preg_replace_callback(
			'/\<(\/?)([\w]+)([^\<\>]*)\>/',
			[$this, 'recoverHtmlTags_callback'],
			$bodySplit
		);

		while ($lastTag = array_pop($recoverBomTagsStack)) {
			$ret .= '</' . $lastTag . '>';
		}

		return $ret;
	}

	private function recoverHtmlTags_callback($mat)
	{
		global $recoverBomTagsStack;

		if (strlen($mat[3])) {
			$lastChar = $mat[3][strlen($mat[3]) - 1];
		} else {
			$lastChar = '';
		}

		if ($lastChar != '/') {
			$curTag = $mat[2];
			if (strtolower($curTag) == 'img') {
				return $mat[0];
			}
			if (!strlen($mat[1])) { //tag begin
				array_push($recoverBomTagsStack, $curTag);
			} else { //tag end
				$ret = '';
				while (true) {
					$lastTag = array_pop($recoverBomTagsStack);
					if ($lastTag === null) {
						#echo "\t[recover] {$curTag} tag overflow recovered\n";
						//top wrapper
						{
							$ret .= '<div>';
							array_push($recoverBomTagsStack, 'div');
						}
						$ret .= '<' . $curTag . '>';
						break;
					} else {
						if ($lastTag != $curTag) {
							#echo "\t[recover] {$lastTag} tag miss match recovered\n";
							$ret .= '</' . $lastTag . '>';
						} else {
							break;
						}
					}
				}

				return $ret . $mat[0];
			}
		}

		return $mat[0];
	}

	private function basename_utf8($file)
	{
		preg_match('/\/?([^\/]+)$/', $file, $mat);

		return $mat[1];
	}

	private function formatting_contents($body, $imgs)
	{
		global $__existimages;
		$__existimages = [];
		foreach ($imgs as $img) {
			$__existimages[$this->basename_utf8($img)] = true;
		}

		$content = $body;

		$content = htmlspecialchars($content);

		$content = str_ireplace('{FONT/}', '{/FONT}', $content);
		$content = str_ireplace('{/TITLE}', '{/FONT}', $content);

		$content = preg_replace_callback("/\n +/", [$this, 'str_textindent'], $content);
		$content = preg_replace_callback(
			"/(\{(\w+)(?:\W*|\W[^\}]+)\}).+(?:\{\/\\2[^\}]*\})/iUs",
			[$this, 'newliner'],
			$content
		);
		$content = preg_replace_callback("/\{(\/?)([^\}]*)\}/", [$this, 'tags_callback'], $content);

		$content = preg_replace('/<page\/>(<page\/>|\s)*/i', '<page/>', $content);

		$content = str_replace("\t", "&nbsp;&nbsp;&nbsp;&nbsp;", $content);
		$content = str_replace("\n", "<br/>\n", $content);

		return $content;
	}

	public function str_textindent($args)
	{
		return str_replace(' ', '&nbsp;&nbsp;', $args[0]);
	}

	public function newliner($args)
	{
		if (in_array(strtolower($args[2]), ['font', 'sub', 'sup'])) {
			$ret = str_replace("\r", "", $args[0]);
			$ret = str_replace("\n", "{/" . $args[2] . "}\n" . $args[1], $ret);

			return $ret;
		}

		return $args[0];
	}

	private function convertHtmlattrToDict($str)
	{
		$dict = [];
		$key = '';
		$writable = false;
		preg_match_all('/(\w+)|(=)|"([^"]*)"|\'([^\']*)\'|\s+/s', $str, $tokens, PREG_SET_ORDER);
		foreach ($tokens as $token) {
			if ($token[1]) {
				$key = strtolower($token[1]);
				$writable = false;
			} else {
				if ($token[2] && strlen($key) && $writable == false) {
					$writable = true;
				} else {
					if (($token[3] || $token[4]) && strlen($key) && $writable) {
						$dict[$key] = $token[3] . $token[4];
						$key = '';
						$writable = false;
					} else {
						$key = '';
						$writable = false;
					}
				}
			}
		}

		return $dict;
	}

	private function convertDictToHtmlattr($dict)
	{
		$ret = ' ';
		foreach ($dict as $k => $v) {
			$ret .= $k;
			$ret .= '=';
			$ret .= '"' . addslashes($v) . '" ';
		}

		return $ret;
	}

	private function imgtag_callback($args)
	{
		global $__existimages;

		$tagBody = $args[2];
		$dict = $this->convertHtmlattrToDict($tagBody);
		$newAttr = [];
		$prefixes = [];
		$postfixes = [];

		$newAttr['src'] = '../Images/' . $dict['src'];
		if ($dict['fullscreen']) {
			$prefixes[] = "<page imgbegin/>";
			$postfixes[] = "<page imgend/>";
		}
		$prefixes[] = "<div style='text-align:center;'>";
		$postfixes[] = "</div>";
		if ($dict['caption']) {
			$postfixes[] = "<p style='text-align:center'>" . htmlspecialchars($dict['caption']) . "</p>";
		}
		$newAttr['alt'] = $this->basename_utf8($newAttr['src']);

		if ($__existimages[$this->basename_utf8($dict['src'])]) {
			return implode('', $prefixes) . '<' . $args[1] . 'img' . $this->convertDictToHtmlattr(
					$newAttr
				) . '/>' . implode(
					'',
					array_reverse($postfixes)
				);
		} else {
			return '';
		}
	}

	private function fonttag_callback($args)
	{
		$tagBody = $args[2];
		$dict = $this->convertHtmlattrToDict($tagBody);
		$newAttr = [];

		if ($dict['size']) {
			switch ($dict['size']) {
				case 1:
					$size = 'x-small';
					break;
				case 2:
					$size = 'small';
					break;
				default://case 3
					$size = 'medium';
					break;
				case 4:
					$size = 'large';
					break;
				case 5:
					$size = 'x-large';
					break;
				case 6:
					$size = 'xx-large';
					break;
				case 7:
					$size = 'xx-large';
					break;
			}
			$newAttr['style'] .= 'font-size:' . $size . ';';
			if ($dict['size'] == 7) {
				$newAttr['style'] .= 'line-height:120%;';
			}
		}
		if ($dict['align']) {
			if (in_array($dict['align'], ['left', 'center', 'right'])) {
				$newAttr['style'] .= 'text-align:' . $dict['align'] . ';display:block;';
			}
		}
		if ($dict['color']) {
			if (preg_match('/^#[\da-f]{6}$/i', $dict['color'])) {
				$newAttr['style'] .= 'color:' . $dict['color'] . ';';
			}
		}

		return '<' . $args[1] . 'span' . $this->convertDictToHtmlattr($newAttr) . '>';
	}

	private function titletag_callback($args)
	{
		$tagBody = $args[2];
		$dict = $this->convertHtmlattrToDict($tagBody);
		$newAttr = [];
		$prefix = '';

		if ($args[1] == '/') {
			return '</span>';
		} else {
			$newAttr['style'] = 'font-size:xx-large;line-height:120%;';
			if ($dict['page'] != 'no') {
				$prefix .= '<page/>';
			}

			return $prefix . '<' . $args[1] . 'span' . $this->convertDictToHtmlattr($newAttr) . '>';
		}
	}

	public function tags_callback($args)
	{
		$args[2] = htmlspecialchars_decode($args[2]);
		if (preg_match('/^(sub|sup|link)/i', $args[2])) {
			return '<' . $args[1] . $args[2] . '>';
		}
		if (preg_match('/^(index)/i', $args[2])) {
			return '';
		}
		if (preg_match('/^(page)/i', $args[2])) {
			return "<page/>";
		}
		if (preg_match('/^(font)/i', $args[2])) {
			return $this->fonttag_callback($args);
		}
		if (preg_match('/^(img)/i', $args[2])) {
			return $this->imgtag_callback($args);
		}
		if (preg_match('/^(title)/i', $args[2])) {
			return $this->titletag_callback($args);
		}

		return '{' . $args[1] . htmlspecialchars($args[2]) . '}';
	}

	private function replace_meta($arrs, $str)
	{
		foreach ($arrs as $k => $arr) {
			$str = str_replace("{{" . $k . "}}", htmlspecialchars($arr), $str);
		}

		return $str;
	}
}
