<?php

namespace PgFactory\MarkdownPlus;

use Kirby\Data\Yaml as Yaml;
use Kirby\Data\Json as Json;
use Exception;
use Kirby\Exception\InvalidArgumentException;
use PgFactory\PageFactory\PageFactory;
use function PgFactory\PageFactory\indentLines;

const MDPMD_CACHE_PATH =       'site/cache/markdownplus/';
const MDPMD_MKDIR_MASK =       0700;
const MDP_LOGS_PATH =          'site/logs/';
const BLOCK_SHIELD =           'div shielded';
const INLINE_SHIELD =          'span shielded';
const MD_SHIELD =              'span md';

class MdPlusHelper
{
    /**
     * @var array
     */
    private static array $availableIcons = [];
    private static array $processedSvgIcons = [];


    /**
     * Reads content of a directory. Automatically ignores any filenames starting with chars contained in $exclude.
     * @param string $pat  Optional glob-style pattern
     * @param bool|string $exclude  Default: '-_#'
     * @return array
     */
    public static function getDir(string $pat, mixed $exclude = null): array
    {
        if ($exclude === null) {
            $exclude = '^[-_#]';
        } elseif (preg_match_all('/@#(\d+);/', $exclude, $m)) {
            foreach ($m[1] as $i => $ascii) {
                $exclude = str_replace($m[0][$i], chr($m[1][$i]), $exclude);
            }
        }
        if (!str_contains($pat, '{')) {
            if (str_contains($pat, '*')) {
                $files = glob($pat);
            } else {
                $files = glob(self::fixPath($pat).'*', GLOB_BRACE);
            }
        } else {
            $files = glob($pat, GLOB_BRACE);
        }
        if (!$files) {
            return [];
        }
        // exclude unwanted files, ie starting with char contained in $exclude:
        foreach ($files as $i => $item) {
            $basename = basename($item);
            if (!$basename || ($exclude && preg_match("/$exclude/", $basename[0]))) {
                unset($files[$i]);
            }
            if (is_dir($item)) {
                $files[$i] = $item.'/';
            }
        }
        return array_values($files);
    } // getDir


    /**
     * Appends a trailing '/' if it's missing.
     *     Note: within PageFactory all paths always end with '/'.
     * @param string $path
     * @return string
     */
    private static function fixPath(string $path): string
    {
        if ($path) {
            $path = rtrim($path, '/').'/';
        }
        return $path;
    } // fixPath


    /**
     * Checks whether icon-name can be converted
     * @param string $iconName
     * @return bool
     */
    public static function iconExists(string $iconName): bool
    {
        $iconFile = self::$availableIcons[$iconName] ?? false;
        if (!$iconFile || !file_exists($iconFile)) {
            return false;
        }
        return true;
    } // iconExists


    /**
     * Checks predefined paths to find available icons
     * Note: this does not include Kirby's own icons as they are not available for web-pages
     *  (https://forum.getkirby.com/t/panel-icons-but-how/15612/7)
     * @return void
     */
    public static function findAvailableIcons(): void
    {
        if (self::$availableIcons) {
            return;
        }
        $path = kirby()->option('pgfactory.markdownplus.iconsPath');
        if (!$path) {
            if (is_dir('site/plugins/pagefactory/assets/icons/')) {
                $paths[0] = 'site/plugins/pagefactory/assets/icons/';
            } else {
                $paths = [];
            }
        } else {
            $paths = explode(',', $path);
        }

        $paths[] = dirname(__DIR__)."/assets/svg-icons/";
        $icons = [];
        foreach ($paths as $path) {
            $path = rtrim($path, '/') . '/*.{jpg,gif,png,svg}';
            $icons = array_merge($icons, glob($path,  GLOB_BRACE));
        }
        $iconNames = array_map(function ($e) {
            return preg_replace('|^.*/(.*?)\.\w+$|', "$1", $e);
        }, $icons);
        self::$availableIcons = array_combine($iconNames, $icons);
    } // findAvailableIcons


    /**
     * Renders an icon specified by its name.
     *   $iconName may be written as ':icon_name:'
     *   $iconName may contain title as 'icon_name/title text...'
     * @param string $iconName
     * @return string
     * @throws Exception
     */
    public static function renderIcon(string $iconName, string $title = '', bool $throwError = false): string
    {
        $iconName0 = $iconName;
        if (preg_match('/^:(.*?):(.*)/', $iconName, $m)) {
            $iconName = $m[1].$m[2];
        }

        if (str_contains($iconName, '/')) {
            list($iconName, $title) = explode('/', $iconName, 2);
        }

        if ($title) {
            $title = " title='$title'";
        }
        $iconFile = self::$availableIcons[$iconName] ?? false;
        if (!$iconFile || !file_exists($iconFile)) {
            if ($throwError) {
                throw new Exception("Error: icon '$iconName' not found.");
            }
            return $iconName0;
        }

        if (str_ends_with($iconFile, '.svg')) {
            $icon = self::renderSvgIcon($iconName, $iconFile);
            $icon = "<span class='mdp-icon'$title>$icon</span>";
        } else {
            $icon = "<span class='mdp-icon'$title><img src='$iconFile' alt=''></span>";
        }
        return $icon;
    } // renderIcon


    /**
     * Appends the svg source to end of body, returns an svg reference (<use...>)
     * @param string $iconName
     * @param string $iconFile
     * @return string
     * @throws Exception
     */
    private static function renderSvgIcon(string $iconName, string $iconFile): string
    {
        $iconId = "pfy-iconsrc-$iconName";
        if (isset(self::$processedSvgIcons[$iconName])) {
            $icon = self::$processedSvgIcons[$iconName];
        } else {
            $str = svg($iconFile);
            $str = str_replace("\n", '', $str);
            if (!preg_match('|(<svg.*?>)(.*)</svg>|ms', $str, $m)) {
                throw new Exception("Error in code of icon '$iconName'");
            }
            $svg = $m[1];
            $icon = "$svg<use href='#$iconId' /></svg>";
            $svg = '<svg style="display:none" aria-hidden="true" focusable="false">' .substr($svg,4);
            $svgBody = $m[2];
            $str = "$svg<symbol id='$iconId'>$svgBody</symbol></svg>";
            PageFactory::$pg->addBodyEndInjections($str);
            self::$processedSvgIcons[$iconName] = $icon;
        }
        return $icon;
    } // renderSvgIcon


    /**
     * Shields a string from the markdown compiler, optionally instructing the unshielder to run the result through
     * the md-compiler separately.
     * @param string $str
     * @param mixed $options     'md' or 'inlineLevel' or 'blocklevel' (= default)
     * @return string
     */
    public static function shieldStr(string $str, mixed $options = false): string
    {
        $ch1 = $options[0]??'';
        $base64 = rtrim(base64_encode($str), '=');
        if ($ch1 === 'm') {
            return '<'.MD_SHIELD.">$base64</".MD_SHIELD.'>';
        } elseif ($ch1 === 'i') {
            return '<'.INLINE_SHIELD.">$base64</".INLINE_SHIELD.'>';
        } else {
            return '<'.BLOCK_SHIELD.">$base64</".BLOCK_SHIELD.'>';
        }
    } // shieldStr


    /**
     * Un-shields shielded strings, optionally running the result through the md-compiler
     * @param string $str
     * @param bool $unshieldLiteral
     * @return string
     * @throws Exception
     */
    public static function unshieldStr(string $str, bool $unshieldLiteral = null): string
    {
        if (!str_contains($str, '<')) {
            return $str;
        }

        if ($unshieldLiteral !== false) {
            $str = preg_replace('#(&lt;|<)(/?)('.INLINE_SHIELD.'|'.BLOCK_SHIELD.'|'.MD_SHIELD.')(&gt;|>)#', "<$2$3>", $str);
            if (preg_match_all('/<('.INLINE_SHIELD.'|'.BLOCK_SHIELD.')>(.*?)<\/('.INLINE_SHIELD.'|'.BLOCK_SHIELD.')>/m', $str, $m)) {
                foreach ($m[2] as $i => $item) {
                    $literal = base64_decode($m[2][$i]);
                    $str = str_replace($m[0][$i], $literal, $str);
                }
            }
        }
        if (preg_match_all('|<'.MD_SHIELD.'>(.*?)</'.MD_SHIELD.'>|m', $str, $m)) {
            foreach ($m[1] as $i => $item) {
                $md = base64_decode($m[1][$i]);
                $html = self::compileMarkdown($md);
                $str = str_replace($m[0][$i], $html, $str);
            }
        }
        return $str;
    } // unshieldStr


    /**
     * @param string $mdStr
     * @param bool $asParagraph
     * @return string
     * @throws Exception
     */
    private static function compileMarkdown(string $mdStr, bool $asParagraph = false): string
    {
        $md = new MarkdownPlus();
        if ($asParagraph) {
            $html = $md->compileParagraph($mdStr);
        } else {
            $html = $md->compile($mdStr);
        }
        return $html;
    } // compileMarkdown


    /**
     * Parses a string to retrieve HTML/CSS attributes
     *  Patterns:
     *      <x  = html tag
     *      #x  = id
     *      .x  = class
     *      x:y = style
     *      x=y = html attribute, e.g. aria-live=polite
     *      !x  = meta command, e.g. !off or !lang=en
     *      'x  = text
     *      "x  = text
     *      x   = text
     *
     * test string:
     *  $str = "<div \"u v w\" #id1 .cls1 !lang=de !showtill:2021-11-17T10:18 color:red; !literal !off lorem ipsum aria-live=\"polite\" .cls.cls2 'dolor dada' data-tmp='x y'";
     * @param string $str
     * @return array
     * @throws Exception
     */
    public static function parseInlineBlockArguments(string $str): array
    {
        $tag = $id = $class = $style = $text = $lang = '';
        $literal = $inline = 0;
        $attr = [];

        $str = str_replace('&lt;', '<', $str);

        // parse argument string, element by element:
        while ($str) {
            $str = ltrim($str);
            $c1 = $str[0]??'';
            if (ctype_alpha($c1)) {

                // catch style instructions "xx:yy":
                if (preg_match('/^([\w-]+):\s*(.*)/', $str, $m)) {
                    $rest = $m[2];
                    if (preg_match('/^([\'"]) (.*?) \1 (.*)/x', $rest, $mm)) {
                        $str = $mm[3];
                        $style = "$style{$m[1]}:{$mm[2]}; ";
                    } elseif (preg_match('/^([^\s;]+)(.*)/', $rest, $mm)) {
                        $str = $mm[2];
                        $style = "$style{$m[1]}:{$mm[1]}; ";
                    }

                // catch attribute instructions "xx=yy":
                } elseif (preg_match('/^([\w-]+)=\s*(.*)/', $str, $m)) {
                    $rest = $m[2];
                    if (preg_match('/^([\'"]) (.*?) \1 (.*)/x', $rest, $mm)) {
                        $quote = $mm[1];
                        $str = $mm[3];
                        $attr[] = "{$m[1]}=$quote{$mm[2]}$quote";
                    } elseif (preg_match('/^([\w-]+)(.*)/', $rest, $mm)) {
                        $str = $mm[2];
                        $attr[] = "{$m[1]}='{$mm[1]}'";
                    }
                } elseif (preg_match('/^(\S*)(.*)/', $str, $m)) {
                    $str = $m[2];
                    $text = $text ? "$text {$m[1]}" : $m[1];
                } else {
                    $str = '';
                }
                continue;
            }

            $str = substr($str,1);
            switch ($c1) {
                case '<':
                    if (preg_match('/^(\w+)(.*)/', $str, $m)) {
                        list($_, $tag, $str) = $m;
                    }
                    $tag = rtrim($tag, '>');
                    break;
                case '#':
                    if (preg_match('/^([\w-]+)(.*)/', $str, $m)) {
                        list($_, $id, $str) = $m;
                    }
                    break;
                case '.':
                    if (preg_match('/^([\w-]+)(.*)/', $str, $m)) {
                        list($_, $class1, $str) = $m;
                        $class = $class? "$class $class1" : $class1;
                    }
                    break;
                case '!':
                    $str = self::_parseMetaCmds($str, $lang, $literal, $inline, $style, $tag);
                    break;
                case '"':
                    if (($p = strpos($str, '"')) !== false) {
                        $t = substr($str, 0, $p-1);
                        $str = substr($str, $p+1);
                        $text = $text ? "$text $t" : $t;
                    }

                    break;
                case "'":
                    if (($p = strpos($str, '\'')) !== false) {
                        $t = substr($str, 0, $p-1);
                        $str = substr($str, $p+1);
                        $text = $text ? "$text $t" : $t;
                    }
                    break;
            }
        }
        if ($literal === 0) {
            $literal = null;
        }
        if ($inline === 0) {
            $inline = null;
        }
        $style = trim($style);
        list($htmlAttrs, $htmlAttrArray) = self::_assembleHtmlAttrs($id, $class, $style, $attr);

        return [
            'tag' => $tag,
            'id' => $id,
            'class' => $class,
            'style' => $style,
            'attr' => $attr,
            'text' => $text,
            'literal' => $literal,
            'inline' => $inline,
            'lang' => $lang,
            'htmlAttrs' => $htmlAttrs,
            'htmlAttrArray' => $htmlAttrArray,
        ];
    } // parseInlineBlockArguments


    /**
     * Helper for parseInlineBlockArguments() -> identifies extended commands (starting with '!')
     * @param string $arg
     * @param string $lang
     * @param mixed $literal
     * @param mixed $inline
     * @param string $style
     * @param string $tag
     */
    private static function _parseMetaCmds(string $str, string &$lang, mixed &$literal, mixed &$inline, string &$style, string &$tag): string
    {
        if (preg_match('/^([\w-]+) [=:]? (.*) /x', $str, $m)) {
            $cmd = strtolower($m[1]);
            $str = ltrim($m[2]);
            $arg = '';
            if (preg_match('/^(\S+)\s*(.*)/', $str, $m)) {
                list($_, $arg, $str) = $m;
            }
            if ($cmd === 'literal') {
                $literal = true;

            } elseif ($cmd === 'user') {
                $admitted = Permission::evaluate("user=$arg");
                if (!$admitted) {
                    $tag = 'skip';
                }

            } elseif ($cmd === 'role') {
                $admitted = Permission::evaluate("role=$arg");
                if (!$admitted) {
                    $tag = 'skip';
                }

            } elseif ($cmd === 'visible' || $cmd === 'visibility') {
                $admitted = Permission::evaluate("$arg");
                if (!$admitted) {
                    $tag = 'skip';
                }

            } elseif ($cmd === 'inline') {
                $inline = true;

            } elseif ($cmd === 'lang') {
                $lang = $arg;
                if (!kirby()->language()) {
                    throw new \Exception("Warning: no language is active or defined while using MarkdownPlus option '!lang=xy'. -> You need to configure languages.");
                }
                if (($lang === 'skip') || ($lang === 'none') || ($lang !== MarkdownPlus::$lang)) {
                    $tag = 'skip';
                }

            } elseif (($cmd === 'off') || (($cmd === 'visible') && ($arg !== 'true')))  {
                $style = $style? " $style display:none;" : 'display:none;';

            } elseif ($cmd === 'showtill') {
                $t = strtotime($arg) - time();
                if ($t < 0) {
                    $lang = 'none';
                    $tag = 'skip';
                    $style = $style? " $style display:none;" : 'display:none;';
                }

            } elseif ($cmd === 'showfrom') {
                $t = strtotime($arg) - time();
                if ($t > 0) {
                    $lang = 'none';
                    $tag = 'skip';
                    $style = $style? " $style display:none;" : 'display:none;';
                }
            }
        }
        return $str;
    } // _parseMetaCmds


    /**
     * Helper for parseInlineBlockArguments()
     * @param string $id
     * @param string $class
     * @param string $style
     * @param $attr
     * @return array
     */
    private static function _assembleHtmlAttrs(string $id, string $class, string $style, $attr): array
    {
        $out = '';
        $htmlAttrArray = [];
        if ($id) {
            $out .= " id='$id'";
            $htmlAttrArray['id'] = $id;
        }
        if ($class) {
            $out .= " class='$class'";
            $htmlAttrArray['class'] = $class;
        }
        if ($style) {
            $out .= " style='$style'";
            $htmlAttrArray['style'] = $style;
        }
        if ($attr) {
            $out .= ' '.implode(' ', $attr);
            $htmlAttrArray = array_merge($htmlAttrArray, $attr);
        }
        return [$out, $htmlAttrArray];
    } // _assembleHtmlAttrs


    /**
     * Splits a string and trims each element. Optionally removes empty elements.
     * @param string $sep
     * @param string $str
     * @param bool $excludeEmptyElems
     * @return array
     */
    public static function explodeTrim(string $sep, string $str, bool $excludeEmptyElems = false): array
    {
        $str = trim($str);
        if ($str === '') {
            return [];
        }
        if (strlen($sep) > 1) {
            if ($sep[0]  === '/') {
                if (($m = preg_split($sep, $str)) !== false) {
                    return $m;
                }
            } elseif (!preg_match("/[$sep]/", $str)) {
                return [ $str ];
            }
            $sep = preg_quote($sep);
            $out = array_map('trim', preg_split("/[$sep]/", $str));

        } else {
            if (!str_contains($str, $sep)) {
                return [ $str ];
            }
            $out = array_map('trim', explode($sep, $str));
        }

        if ($excludeEmptyElems) {
            $out = array_filter($out, function ($item) {
                return ($item !== '');
            });
        }
        return $out;
    } // explodeTrim


    /**
     * Converts string to a pixel value, e.g. '1em' -> 12[px]
     * @param string $str
     * @return float
     */
    public static function convertToPx(string $str): float
    {
        $px = 0;
        if (preg_match('/([\d.]+)(\w*)/', $str, $m)) {
            $unit = $m[2];
            $value = floatval($m[1]);
            switch ($unit) {
                case 'in':
                    $px = 96 * $value; break;
                case 'cm':
                    $px = 37.7952755906 * $value; break;
                case 'mm':
                    $px = 3.779527559 * $value; break;
                case 'em':
                    $px = 12 * $value; break;
                case 'ch':
                    $px = 6 * $value; break;
                case 'pt':
                    $px = 1.3333333333 * $value; break;
                case 'px':
                    $px = $value; break;
            }
        }
        return $px;
    } // convertToPx

    /**
     * Loads content of file, applies some cleanup on demand: remove comments, zap end after __END__.
     * If file extension is yaml, csv or json, data is decoded and returned as a data structure.
     * @param string $file
     * @param mixed $removeComments Possible values:
     *         true    -> zap END
     *         'hash'  -> #...
     *         'empty' -> remove empty lines
     *         'cStyle' -> // or /*
     * @param bool $useCaching In case of yaml files caching can be activated
     * @return array|mixed|string|string[]
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public static function loadFile(string $file, mixed $removeComments = true, bool $useCaching = false): mixed
    {
        if (!$file) {
            return '';
        }
        if ($useCaching) {
            $data = self::checkDataCache($file);
            if ($data !== false) {
                return $data;
            }
        }
        $data = self::getFile($file, $removeComments);

        // if it's data of a known format (i.e. yaml,json etc), decode it:
        $ext = self::fileExt($file);
        if (str_contains(',yaml,yml,json,csv', $ext)) {
            $data = Yaml::decode($data);
            if ($useCaching) {
                self::updateDataCache($file, $data);
            }
        }
        return $data;
    } // loadFile


    /**
     * Reads file safely, first resolving path, removing comments and zapping rest (after __END__) if requested
     * @param string $file
     * @param mixed $removeComments -> cstyle|hash|empty
     * @return string|array|bool|string[]
     */
    public static function getFile(string $file, mixed $removeComments = true): string|array|bool
    {
        if (!$file) {
            return '';
        }

        $data = self::fileGetContents($file);
        if (!$data) {
            return '';
        }

        // remove BOM
        $data = str_replace("\xEF\xBB\xBF", '', $data);

        // special option 'zapped' -> return what would be zapped:
        if (str_contains((string)$removeComments, 'zapped')) {
            return self::zapFileEND($data, true);
        }

        // always zap, unless $removeComments === false:
        if ($removeComments) {
            $data = self::zapFileEND($data);
        }
        // default (== true):
        if ($removeComments === true) {
            $data = self::removeCStyleComments($data);
            $data = self::removeEmptyLines($data);

            // specific instructions:
        } elseif (is_string($removeComments)) {
            // extract first characters from comma-separated-list:
            $removeComments = implode('', array_map(function ($elem){
                return strtolower($elem[0]);
            }, self::explodeTrim(',',$removeComments)));

            if (str_contains($removeComments, 'c')) {    // c style
                $data = self::removeCStyleComments($data);
            }
            if (str_contains($removeComments, 'h')) {    // hash style
                $data = self::removeHashTypeComments($data);
            }
            if (str_contains($removeComments, 'e')) {    // empty lines
                $data = self::removeEmptyLines($data);
            }
        }
        return $data;
    } // getFile


    /**
     * Zaps rest of file following pattern \n__END__
     * @param string $str
     * @param bool $reverse
     * @return string
     */
    public static function zapFileEND(string $str, bool $reverse = false): string
    {
        $p = strpos($str, "\n__END__\n");
        if ($p === false) {
            if (str_starts_with($str, "__END__\n")) {
                $p = -1;
            } else {
                if ($reverse) {
                    return '';
                } else {
                    return $str;
                }
            }
        }
        $p++;
        if ($reverse) {
            $str = substr($str, $p);
        } else {
            $str = substr($str, 0, $p);
        }
        return $str;
    } // zapFileEND


    /**
     * Removes empty lines from a string.
     * @param string $str
     * @param bool $leaveOne
     * @return string
     */
    public static function removeEmptyLines(string $str, bool $leaveOne = true): string
    {
        if ($leaveOne) {
            return preg_replace("/\n\s*\n+/", "\n\n", $str);
        } else {
            return preg_replace("/\n\s*\n+/", "\n", $str);
        }
    } // removeEmptyLines


    /**
     * Removes hash-type comments from a string, e.g. \n#...
     * @param string $str
     * @return string
     */
    public static function removeHashTypeComments(string $str): string
    {
        if (!$str) {
            return '';
        }
        $lines = explode(PHP_EOL, $str);
        $lead = true;
        foreach ($lines as $i => $l) {
            if (isset($l[0]) && ($l[0] === '#')) {  // # at beginning of line
                unset($lines[$i]);
            } elseif ($lead && !$l) {   // empty line while no data line encountered
                unset($lines[$i]);
            } else {
                $lead = false;
            }
        }
        return implode("\n", $lines);
    } // removeHashTypeComments


    /**
     * Removes c-style comments from a string, e.g. // or /*
     * Supported exceptions: links (http://...) and shielded comments (\// or \/*)
     * @param string $str
     * @return string
     */
    public static function removeCStyleComments(string $str): string
    {
        $p = 0;
        while (($p = strpos($str, '/*', $p)) !== false) {        // /* */ style comments

            $ch_1 = $p ? $str[$p - 1] : "\n"; // char preceding '/*' must be whitespace
            if ($p && ($ch_1 === '\\')) {                    // avoid shielded //
                $p += 2;
                continue;
            }
            if (strpbrk(" \n\t", $ch_1) === false) {
                $p += 2;
                continue;
            }
            $p2 = strpos($str, "*/", $p);
            $str = substr($str, 0, $p) . substr($str, $p2 + 2);
        }

        $p = 0;
        while (($p = strpos($str, '//', $p)) !== false) {        // // style comments
            $ch_1 = $p ? $str[$p - 1] : "\n"; // char preceding '/*' must be whitespace
            if ($p && ($ch_1 === ':')) {            // avoid http://
                $p += 2;
                continue;
            }

            if ($p && ($ch_1 === '\\')) {                    // avoid shielded //
                $p += 2;
                continue;
            }
            $p2 = strpos($str, "\n", $p);       // find end of line
            if ($p2 === false) {
                return substr($str, 0, $p);
            }

            if ((!$p || ($ch_1 === "\n")) && ($str[$p2])) {
                $p2++;
            }
            $str = substr($str, 0, $p) . substr($str, $p2); // cut out commented part
        }
        return $str;
    } // removeCStyleComments


    /**
     * @param string $str
     * @return string
     * @throws Exception
     */
    public static function removeHtmlComments(string $str): string
    {
        list($p1, $p2) = self::strPosMatching($str,0, '<!--', '-->');
        while($p1 !== false) {
            if ($p2) {
                $str = substr($str, 0, $p1).substr($str,$p2+3);
            } else {
                $str = substr($str, 0, $p1);
            }
            list($p1, $p2) = self::strPosMatching($str,$p1+3, '<!--', '-->');
        }
        return $str;
    } // removeHtmlComments


    /**
     * Returns file extension of a filename.
     * @param string $file0
     * @param bool $reverse       Returns path&filename without extension
     * @param bool $couldBeUrl    Handles case where URL may include args and/or #target
     * @return string
     */
    public static function fileExt(string $file0, bool $reverse = false, bool $couldBeUrl = false): string
    {
        if ($couldBeUrl) {
            $file = preg_replace(['|^\w{1,6}://|', '/[#?&:].*/'], '', $file0); // If ever needed for URLs as well
            $file = basename($file);
        } else {
            $file = basename($file0);
        }
        if ($reverse) {
            $path = dirname($file0) . '/';
            if ($path === './') {
                $path = '';
            }
            $file = pathinfo($file, PATHINFO_FILENAME);
            return $path . $file;

        } else {
            return pathinfo($file, PATHINFO_EXTENSION);
        }
    } // fileExt


    /**
     * file_get_contents() replacement with file_exists check
     * @param string $file
     * @return string|bool
     */
    public static function fileGetContents(string $file): string|bool
    {
        if (file_exists($file)) {
            return @file_get_contents($file);
        } else {
            return false;
        }
    } // fileGetContents


    /**
     * Checks whether cache contains valid data (used for yaml-cache)
     * @param string|array $file
     * @return mixed|null
     */
    private static function checkDataCache(mixed $file): mixed
    {
        if (is_array($file)) {
            $file1 = $file[0]??'';
            $cacheFile = self::cacheFileName($file1, '.0');
            if (!file_exists($cacheFile)) {
                return false;
            }
            $tCache = self::fileTime($cacheFile);
            $tFiles = 0;
            foreach ($file as $f) {
                $tFiles = max($tFiles, self::fileTime($f));
            }
            if ($tFiles < $tCache) {
                $raw = file_get_contents($cacheFile);
                return unserialize($raw);
            }

        } else {
            $cacheFile = self::cacheFileName($file);
            if (file_exists($cacheFile)) {
                $tFile = self::fileTime($file);
                $tCache = self::fileTime($cacheFile);
                if ($tFile < $tCache) {
                    $raw = file_get_contents($cacheFile);
                    return unserialize($raw);
                }
            }
        }
        return false;
    } // checkDataCache


    /**
     * Writes data to the (yaml-)cache
     * @param string $file
     * @param mixed $data
     * @param string $tag
     * @throws Exception
     */
    private static function updateDataCache(string $file, mixed $data, string $tag = ''): void
    {
        $raw = serialize($data);
        $cacheFile = self::cacheFileName($file, $tag);
        self::preparePath($cacheFile);
        file_put_contents($cacheFile, $raw);
    } // updateDataCache


    /**
     * Returns the name of the (yaml-)cache file.
     * @param string $file
     * @param string $tag
     * @return string
     */
    private static function cacheFileName(string $file, string $tag = ''): string
    {
        $cacheFile = self::localPath($file);
        $cacheFile = str_replace('/', '_', $cacheFile);
        return MDPMD_CACHE_PATH . $cacheFile . $tag .'.cache';
    } // cacheFileName


    /**
     * filemtime() replacement with file_exists check
     * @param string $file
     * @return int
     */
    public static function fileTime(string $file): int
    {
        if (file_exists($file)) {
            return (int)@filemtime($file);
        } else {
            return 0;
        }
    } // fileTime


    /**
     * Parses a string to extract structured data of relaxed Yaml syntax:
     *     First arguments may omit key, then they get keys 0,1,...
     *     Superbrackts may be used to shield value contents: e.g. '!!', '%%' (as used by macros)
     *     Example: key: !! x:('") !!
     * @param string $str
     * @param string $delim
     * @return array
     * @throws InvalidArgumentException
     * @throws Exception
     */


    public static function parseArgumentStr(string $str, string $delim = ','): array
    {
        // terminate if string empty:
        if (!($str = trim($str))) {
            return [];
        }

        // skip '{{ ... }}' to avoid conflict with '{ ... }':
        if (preg_match('/^\s* {{ .* }} \s* $/x', $str)) {
            return [ $str ];
        }

        // if string starts with { we assume it's "non-relaxed" json:
        if (($str[0] === '{') && !str_contains($str, '<'.INLINE_SHIELD.'>') &&
            !str_contains($str, '<'.BLOCK_SHIELD.'>') &&
            !str_contains($str, '{md{')) {
            return Json::decode($str);
        }

        // otherwise, interpret as 'relaxed Yaml':
        // (meaning: first elements may come without key, then they are interpreted by position)
        $rest = ltrim($str, ", \n");
        $rest = rtrim($rest, ", \n)");

        if (preg_match('/^(.*?) \)\s*}}/msx', $rest, $mm)) {
            $rest = rtrim($mm[1], " \t\n");
        }

        $json = '';
        $counter = 100;
        $index = 0;
        while ($rest && ($counter-- > 0)) {
            $key = self::parseArgKey($rest, $delim);
            $ch = ltrim($rest);
            $ch = $ch[0]??'';
            if ($ch !== ':') {
                $json .= "\"$index\": $key,";
                $rest = ltrim($rest, " $delim\n");
            } else {
                $rest = ltrim(substr($rest, 1));
                $value = self::parseArgValue($rest, $delim);
                $json .= "$key: $value,";
            }
            $index++;
        }

        $json = rtrim($json, ',');
        $json = '{'.$json.'}';
        $options = json_decode($json, true);
        if ($options === null) {
            $options = [];
        }

        return $options;
    } // parseArgumentStr



    /**
     * Parses the key part of 'key: value'
     * @param string $rest
     * @param string $delim
     * @return string
     */
    public static function parseArgKey(string &$rest, string $delim): string
    {
        $key = '';
        $rest = ltrim($rest);
        // case quoted key or value:
        if ((($ch1 = ($rest[0]??'')) === '"') || ($ch1 === "'")) {
            $pattern = "$ch1 (.*?) $ch1";
            // case 'value' without key:
            if (preg_match("/^ ($pattern) (.*)/xms", $rest, $m)) {
                $key = $m[2];
                $rest = $m[3];
            }

            // case naked key or value:
        } else {
            // case value without key:
            if (preg_match('|^(https?://\S*)(.*)|', $rest, $m)) {
                $key = $m[1];
                $rest = $m[2];
            } else {
                $pattern = "[^$delim\n:]+";
                if (preg_match("/^ ($pattern) (.*) /xms", $rest, $m)) {
                    $key = $m[1];
                    $rest = $m[2];
                }
            }
        }
        $key = str_replace(['\\', '"', "\t", "\n", "\r", "\f"], ['\\\\', '\\"', '\\t', '\\n', '\\r', '\\f'], $key);
        return "\"$key\"";
    } // parseArgKey


    /**
     * Parses the value part of 'key: value'
     * @param string $rest
     * @param string $delim
     * @return string
     */
    public static function parseArgValue(string &$rest, string $delim): mixed
    {
        // case quoted key or value:
        $value = '';
        $ch1 = ltrim($rest);
        $ch1 = $ch1[0]??'';
        if (($ch1 === '"') || ($ch1 === "'")) {
            $rest = ltrim($rest);
            $pattern = "$ch1 (.*?) $ch1";
            // case 'value' without key:
            if (preg_match("/^ ($pattern) (.*)/xms", $rest, $m)) {
                $value = $m[2];
                $rest = ltrim($m[3], ', ');
            }

            // case string wrapped in {} -> assume it's propre Json:
        } elseif ($ch1 === '{') {
            $p = self::strPosMatching($rest, 0, '{', '}');
            $value = substr($rest, $p[0], $p[1]-$p[0]+1);
            $rest = substr($rest, $p[1]+1);
            return $value;

        } else {
            // case value without key:
            $pattern = "[^$delim\n]+";
            if (preg_match("/^ ($pattern) (.*) /xms", $rest, $m)) {
                $value = $m[1];
                $rest = ltrim($m[2], ', ');
            }
        }
        $value = self::fixDataType($value);
        if (is_string($value)) {
            $value = str_replace(['\\', '"', "\t", "\n", "\r", "\f"], ['\\\\', '\\"', '\\t', '\\n', '\\r', '\\f'], $value);
            $value = '"' . trim($value) . '"';
        } elseif (is_bool($value)) {
            $value = $value? 'true': 'false';
        }
        $pattern = "^[$delim\n]+";
        $rest = preg_replace("/$pattern/", '', $rest);
        return $value;
    } // parseArgValue


    /**
     * @param string $value
     * @return mixed
     */
    private static function fixDataType(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if ($value === '0') { // we must check this before empty because zero is empty
            return 0;
        }

        if (empty($value)) {
            return '';
        }

        if ($value === 'null') {
            return null;
        }

        if ($value === 'undefined') {
            return null;
        }

        if ($value === '1') {
            return 1;
        }

        if (!preg_match('/[^0-9.]+/', $value)) {
            if(preg_match('/[.]+/', $value)) {
                return (double)$value;
            }else{
                return (int)$value;
            }
        }

        if ($value == 'true') {
            return true;
        }

        if ($value == 'false') {
            return false;
        }

        return (string)$value;
    } // fixDataType



    /**
     * Returns positions of opening and closing patterns, ignoring shielded patters (e.g. \{{ )
     * @param string $str
     * @param int $p0
     * @param string $pat1
     * @param string $pat2
     * @return array|false[]
     * @throws Exception
     */
    public static function strPosMatching(string $str, int $p0 = 0, string $pat1 = '{{', string $pat2 = '}}'): array
    {
        if (!$str || ($p0 === null) || (strlen($str) < $p0)) {
            return [false, false];
        }

        // simple case: both patterns are equal -> no need to check for nested patterns:
        if ($pat1 === $pat2) {
            $p1 = strpos($str, $pat1);
            $p2 = strpos($str, $pat1, $p1+1);
            return [$p1, $p2];
        }

        // opening and closing patterns -> need to check for nested patterns:
        self::checkBracesBalance($str, $p0, $pat1, $pat2);

        $d = strlen($pat2);
        if ((strlen($str) < 4) || ($p0 > strlen($str))) {
            return [false, false];
        }

        if (!self::checkNesting($str, $pat1, $pat2)) {
            return [false, false];
        }

        $p1 = $p0 = self::findNextPattern($str, $pat1, $p0);
        if ($p1 === false) {
            return [false, false];
        }
        $cnt = 0;
        do {
            $p3 = self::findNextPattern($str, $pat1, $p1+$d); // next opening pat
            $p2 = self::findNextPattern($str, $pat2, $p1+$d); // next closing pat
            if ($p2 === false) { // no more closing pat
                return [false, false];
            }
            if ($cnt === 0) {	// not in nexted structure
                if ($p3 === false) {	// no more opening pat
                    return [$p0, $p2];
                }
                if ($p2 < $p3) { // no more opening patterns or closing before next opening
                    return [$p0, $p2];
                } else {
                    $cnt++;
                    $p1 = $p3;
                }
            } else {	// within nexted structure
                if ($p3 === false) {	// no more opening pat
                    $cnt--;
                    $p1 = $p2;
                } else {
                    if ($p2 < $p3) { // no more opening patterns or closing before next opening
                        $cnt--;
                        $p1 = $p2;
                    } else {
                        $cnt++;
                        $p1 = $p3;
                    }
                }
            }
        } while (true);
    } // strPosMatching


    /**
     * Helper for strPosMatching()
     * @param string $str
     * @param int $p0
     * @param string $pat1
     * @param string $pat2
     * @throws Exception
     */
    private static function checkBracesBalance(string $str, int $p0 = 0, string $pat1 = '{{', string $pat2 = '}}'): void
    {
        $shieldedOpening = substr_count($str, '\\' . $pat1, $p0);
        $opening = substr_count($str, $pat1, $p0) - $shieldedOpening;
        $shieldedClosing = substr_count($str, '\\' . $pat2, $p0);
        $closing = substr_count($str, $pat2, $p0) - $shieldedClosing;
        if ($opening > $closing) {
            throw new Exception("Error in source: unbalanced number of &#123;&#123; resp }}");
        }
    } // checkBracesBalance


    /**
     * Helper for strPosMatching()
     * @param string $str
     * @param string $pat1
     * @param string $pat2
     * @return int
     * @throws Exception
     */
    private static function checkNesting(string $str, string $pat1, string $pat2): int
    {
        $n1 = substr_count($str, $pat1);
        $n2 = substr_count($str, $pat2);
        if ($n1 > $n2) {
            throw new Exception("Nesting Error in string '$str'");
        }
        return $n1;
    } // checkNesting


    /**
     * Finds the next position of unshielded pattern
     * @param string $str
     * @param string $pat
     * @param int $p1
     * @return int|bool
     */
    private static function findNextPattern(string $str, string $pat, mixed $p1 = 0): int|bool
    {
        while (($p1 = strpos($str, $pat, $p1)) !== false) {
            if (($p1 === 0) || (substr($str, $p1 - 1, 1) !== '\\')) {
                break;
            }
            $p1 += strlen($pat);
        }
        return $p1;
    } // findNextPattern


    /**
     * Converts a absolute path to one starting at app-root.
     * @param string $absPath
     * @return string
     */
    public static function localPath(string $absPath): string
    {
        if (($absPath[0]??'') === '/') {
            $absAppRoot = kirby()->root().'/';
            return substr($absPath, strlen($absAppRoot));
        } else {
            return $absPath;
        }
    } // localPath


    /**
     * Takes a path and creates corresponding folders/subfolders if they don't exist. Applies access writes if given.
     * @param string $path0
     * @param mixed $accessRights
     * @throws Exception
     */
    public static function preparePath(string $path0, mixed $accessRights = false): void
    {
        // check for inappropriate path, e.g. one attempting to point to an ancestor directory:
        if (str_contains($path0, '../')) {
            $path0 = self::normalizePath($path0);
            if (str_contains($path0, '../')) {
                self::mylog("=== Warning: preparePath() trying to access inappropriate location: '$path0'");
                return;
            }
        }

        // make folder(s) if necessary:
        $path = dirname($path0.'x');
        if (!file_exists($path)) {
            $accessRights1 = $accessRights ?: MDPMD_MKDIR_MASK;
            try {
                mkdir($path, $accessRights1, true);
            } catch (Exception) {
                throw new Exception("Error: failed to create folder '$path'");
            }
        }

        // apply access rights if requested:
        if ($accessRights) {
            $path1 = '';
            foreach (explode('/', $path) as $p) {
                $path1 .= "$p/";
                try {
                    chmod($path1, $accessRights);
                } catch (Exception) {
                    throw new Exception("Error: failed to create folder '$path'");
                }
            }
        }
    } // preparePath


    /**
     * If within a path pattern '../' appears, replaces it with a direct path.
     * @param string $path
     * @return string
     */
    public static function normalizePath(string $path): string
    {
        $hdr = '';
        if (preg_match('|^ ((\.\./)+) (.*)|x', $path, $m)) {
            $hdr = $m[1];
            $path = $m[3];
        }
        while ($path && preg_match('|(.*?) ([^/.]+/\.\./) (.*)|x', $path, $m)) {
            $path = $m[1] . $m[3];
        }
        $path = str_replace('/./', '/', $path);
        $path = preg_replace('|(?<!:)//|', '/', $path);
        return $hdr.$path;
    } // normalizePath


    /**
     * Simple log function for quick&dirty testing
     * @param string $str
     * @throws Exception
     */
    public static function mylog(string $str): void
    {
        $logFile = MDP_LOGS_PATH. 'log.txt';
        $logMaxWidth = 80;

        if ((strlen($str) > $logMaxWidth) || (str_contains($str, "\n"))) {
            $str = wordwrap($str, $logMaxWidth);
            $str1 = '';
            foreach (explode("\n", $str) as $i => $l) {
                if ($i > 0) {
                    $str1 .= '                     ';
                }
                $str1 .= "$l\n";
            }
            $str = $str1;
        }
        $str = self::timestampStr()."  $str\n\n";
        self::writeFile($logFile, $str, FILE_APPEND);
    } // mylog


    /**
     * Writes a string to a file.
     * @param string $file
     * @param string $content
     * @param int $flags        e.g. FILE_APPEND
     * @throws Exception
     */
    public static function writeFile(string $file, string $content, int $flags = 0): void
    {
        self::preparePath($file);
        if (file_put_contents($file, $content, $flags) === false) {
            $file = basename($file);
            throw new Exception("Writing to file '$file' failed");
        }
    } // writeFile


    /**
     * Returns a timestamp string of type '2021-12-07'
     * @param bool $short
     * @return string
     */
    public static function timestampStr(bool $short = false): string
    {
        if (!$short) {
            return date('Y-m-d H:i:s');
        } else {
            return date('Y-m-d');
        }
    } // timestampStr


} // MdPlusHelper