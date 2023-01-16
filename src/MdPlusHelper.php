<?php

namespace Usility\MarkdownPlus;

use Kirby\Data\Yaml as Yaml;
use Kirby\Data\Json as Json;
use Exception;
use Kirby\Exception\InvalidArgumentException;

const MDPMD_CACHE_PATH =       'site/cache/markdownplus/';
const MDPMD_MKDIR_MASK =       0700;
const MDP_LOGS_PATH =          'site/logs/';


class MdPlusHelper
{
    private static array $availableIcons = [];


    /**
     * Reads content of a directory. Automatically ignores any filenames starting with chars contained in $exclude.
     * @param string $pat  Optional glob-style pattern
     * @param bool|string $exclude  Default: '-_#'
     * @return array
     */
    public static function getDir(string $pat, mixed $exclude = null): array
    {
        $exclude = ($exclude ===null) ? '-_#' : '';
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
            if (!$basename || ($exclude && str_contains($exclude, $basename[0]))) {
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
        $path = kirby()->option('usility.markdownplus.iconsPath');
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
     * @param string $iconName
     * @return string
     * @throws Exception
     */
    public static function renderIcon(string $iconName): string
    {
        $iconFile = self::$availableIcons[$iconName] ?? false;
        if (!$iconFile || !file_exists($iconFile)) {
            throw new Exception("Error: icon '$iconName' not found.");
        }

        if (str_ends_with($iconFile, '.svg')) {
            $icon = "<span class='mdp-icon'>".svg($iconFile).'</span>';
        } else {
            $icon = "<span class='mdp-icon'><img src='$iconFile' alt=''></span>";
        }
        return $icon;
    } // renderIcon


    /**
     * Shields a string from the markdown compiler, optionally instructing the unshielder to run the result through
     * the md-compiler separately.
     * @param string $str
     * @param bool $mdCompile
     * @return string
     */
    public static function shieldStr(string $str, bool $mdCompile = false): string
    {
        if ($mdCompile) {
            return '<md>' . base64_encode($str) . '</md>';
        } else {
            return '<raw>' . base64_encode($str) . '</raw>';
        }
    } // shieldStr


    /**
     * Un-shields shielded strings, optionally running the result through the md-compiler
     * @param string $str
     * @param bool $unshieldLiteral
     * @return string
     */
    public static function unshieldStr(string $str, bool $unshieldLiteral = false): string
    {
        if ($unshieldLiteral && preg_match_all('|<raw>(.*?)</raw>|m', $str, $m)) {
            foreach ($m[1] as $i => $item) {
                $literal = base64_decode($m[1][$i]);
                $str = str_replace($m[0][$i], $literal, $str);
            }
        }
        if (preg_match_all('|<md>(.*?)</md>|m', $str, $m)) {
            foreach ($m[1] as $i => $item) {
                $mdStr = base64_decode($m[1][$i]);
                $md = new MarkdownPlus();
                $html = $md->compileStr($mdStr);
                $str = str_replace($m[0][$i], $html, $str);
            }
        }
        return $str;
    } // unshieldStr


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
     */
    public static function parseInlineBlockArguments(string $str): array
    {
        $tag = $id = $class = $style = $text = $lang = '';
        $literal = $inline = 0;
        $attr = [];

        $str = str_replace('&lt;', '<', $str);

        // catch quoted elements:
        if (preg_match_all('/(?<!=) (["\']) (.*?) \1/x', $str, $m)) {
            foreach ($m[2] as $i => $t) {
                $text = $text? "$text $t": $t;
                $str = str_replace($m[0][$i], '', $str);
            }
        }

        // catch attributes with quoted args:
        if (preg_match_all('/([=!\w-]+) = \' (.+?)  \'/x', $str, $m)) {
            foreach ($m[2] as $i => $t) {
                $ch1 = $m[1][$i][0];
                if (($ch1 === '!') || ($ch1 === '=')){
                    continue;
                }
                $attr[ $m[1][$i] ] = $t;
                $str = str_replace($m[0][$i], '', $str);
            }
        }
        if (preg_match_all('/([=!\w-]+) = " (.+?)  "/x', $str, $m)) {
            foreach ($m[2] as $i => $t) {
                $ch1 = $m[1][$i][0];
                if (($ch1 === '!') || ($ch1 === '=')){
                    continue;
                }
                $attr[ $m[1][$i] ] = $t;
                $str = str_replace($m[0][$i], '', $str);
            }
        }
        if (preg_match_all('/([=!\w-]+) = (\S+) /x', $str, $m)) {
            foreach ($m[2] as $i => $t) {
                $ch1 = $m[1][$i][0];
                if (($ch1 === '!') || ($ch1 === '=')){
                    continue;
                }
                $attr[ $m[1][$i] ] = $t;
                $str = str_replace($m[0][$i], '', $str);
            }
        }

        if (preg_match_all('/([=!\w-]+) : ([^\s;,]+) ;?/x', $str, $m)) {
            foreach ($m[2] as $i => $t) {
                $ch1 = $m[1][$i][0];
                if (($ch1 === '!') || ($ch1 === '=')){
                    continue;
                }
                $style ="$style{$m[1][$i]}:$t;";
                $str = str_replace($m[0][$i], '', $str);
            }
        }

        // catch rest:
        $str = str_replace(['#','.'],[' #',' .'], $str);
        $args = self::explodeTrim(' ', $str, true);
        foreach ($args as $arg) {
            $c1 = $arg[0];
            $arg1 = substr($arg,1);
            switch ($c1) {
                case '<':
                    $tag = rtrim($arg1, '>');
                    break;
                case '#':
                    $id = $arg1;
                    break;
                case '.':
                    $arg1 = str_replace('.', ' ', $arg1);
                    $class = $class? "$class $arg1" : $arg1;
                    break;
                case '!':
                    self::_parseMetaCmds($arg1, $lang, $literal, $inline, $style, $tag);
                    break;
                case '"':
                    $t = rtrim($arg1, '"');
                    $text = $text ? "$text $t" : $t;
                    break;
                case "'":
                    $t = rtrim($arg1, "'");
                    $text = $text ? "$text $t" : $t;
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
    private static function _parseMetaCmds(string $arg, string &$lang, mixed &$literal, mixed &$inline, string &$style, string &$tag): void
    {
        if (preg_match('/^([\w-]+) [=:]? (.*) /x', $arg, $m)) {
            $arg = strtolower($m[1]);
            $param = $m[2];
            if ($arg === 'literal') {
                $literal = true;
            } elseif ($arg === 'inline') {
                $inline = true;
            } elseif ($arg === 'lang') {
                $lang = $param;
                if (($lang === 'skip') || ($lang === 'none')) {
                    $tag = 'skip';
                    $style = $style? " $style display:none;" : 'display:none;';
                }
            } elseif (($arg === 'off') || (($arg === 'visible') && ($param !== 'true')))  {
                $style = $style? " $style display:none;" : 'display:none;';
            } elseif ($arg === 'showtill') {
                $t = strtotime($param) - time();
                if ($t < 0) {
                    $lang = 'none';
                    $tag = 'skip';
                    $style = $style? " $style display:none;" : 'display:none;';
                }
            } elseif ($arg === 'showfrom') {
                $t = strtotime($param) - time();
                if ($t > 0) {
                    $lang = 'none';
                    $tag = 'skip';
                    $style = $style? " $style display:none;" : 'display:none;';
                }
            }
        }
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
            foreach ($attr as $k => $v) {
                $out .= " $k='$v'";
            }
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
     * @param string $str
     * @return string
     */
    public static function removeCStyleComments(string $str): string
    {
        $p = 0;
        while (($p = strpos($str, '/*', $p)) !== false) {        // /* */ style comments

            $ch_1 = $p ? $str[$p - 1] : "\n"; // char preceding '/*' must be whitespace
            if (strpbrk(" \n\t", $ch_1) === false) {
                $p += 2;
                continue;
            }
            $p2 = strpos($str, "*/", $p);
            $str = substr($str, 0, $p) . substr($str, $p2 + 2);
        }

        $p = 0;
        while (($p = strpos($str, '//', $p)) !== false) {        // // style comments

            if ($p && ($str[$p - 1] === ':')) {            // avoid http://
                $p += 2;
                continue;
            }

            if ($p && ($str[$p - 1] === '\\')) {                    // avoid shielded //
                $str = substr($str, 0, $p - 1) . substr($str, $p);
                $p += 2;
                continue;
            }
            $p2 = strpos($str, "\n", $p);
            if ($p2 === false) {
                return substr($str, 0, $p);
            }

            if ((!$p || ($str[$p - 1] === "\n")) && ($str[$p2])) {
                $p2++;
            }
            $str = substr($str, 0, $p) . substr($str, $p2);
        }
        return $str;
    } // removeCStyleComments


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

        // if string starts with { we assume it's json:
        if (($str[0] === '{') && (!str_starts_with($str, '<raw>')) && (!str_starts_with($str, '{md{'))) {
            return Json::decode($str);
        }

        // otherwise, interpret as 'relaxed Yaml':
        // (meaning: first elements may come without key, then they are interpreted by position)
        $rest = ltrim($str, ", \n");
        $rest = rtrim($rest, ", \n)");

        if (preg_match('/^(.*?) \)\s*}}/msx', $rest, $mm)) {
            $rest = rtrim($mm[1], " \t\n");
        }

        $yaml = '';
        $counter = 100;
        while ($rest && ($counter-- > 0)) {
            $key = self::parseArgKey($rest, $delim);
            $ch = ltrim($rest);
            $ch = $ch[0]??'';
            if ($ch !== ':') {
                $yaml .= "- $key\n";
                $rest = ltrim($rest, " $delim\n");
            } else {
                $rest = ltrim(substr($rest, 1));
                $value = self::parseArgValue($rest, $delim);
                if (trim($value) !== '') {
                    $yaml .= "$key: $value\n";
                } else {
                    $yaml .= "$key:\n";
                }
            }
        }

        $options = Yaml::decode($yaml);

        // case a value was written in '{...}' notation -> unpack:
        if (str_contains($yaml, '<raw>')) {
            foreach ($options as $key => $value) {
                if (str_starts_with($value, '<raw>')) {
                    $options[$key] = self::unshieldStr($value, true);
                }
            }
        }

        return $options;
    } // parseArgumentStr


    /**
     * Parses the key part of 'key: value'
     * @param string $rest
     * @param string $delim
     * @return string
     */
    private static function parseArgKey(string &$rest, string $delim): string
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
            $pattern = "[^$delim\n:]+";
            if (preg_match("/^ ($pattern) (.*) /xms", $rest, $m)) {
                $key = $m[1];
                $rest = $m[2];
            }
        }
        return "'$key'";
    } // parseArgKey


    /**
     * Parses the value part of 'key: value'
     * @param string $rest
     * @param string $delim
     * @return string
     * @throws Exception
     */
    private static function parseArgValue(string &$rest, string $delim): string
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
                $value = $m[1];
                $rest = ltrim($m[3], ', ');
            }

            // case '{'-wrapped value -> shield value from Yaml-compiler (workaround for bug in Yaml-compiler):
        } elseif ($ch1 === '{') {
            $p = self::strPosMatching($rest, 0, '{', '}');
            $value = substr($rest, $p[0]+1, $p[1]-$p[0]-1);
            $rest = substr($rest, $p[1]+1);
            return self::shieldStr($value);

        } else {
            // case value without key:
            $pattern = "[^$delim\n]+";
            if (preg_match("/^ ($pattern) (.*) /xms", $rest, $m)) {
                $value = $m[1];
                $rest = ltrim($m[2], ', ');
            }
        }
        $pattern = "^[$delim\n]+";
        $rest = preg_replace("/$pattern/", '', $rest);
        return $value;
    } // parseArgValue


    /**
     * Returns positions of opening and closing patterns, ignoring shielded patters (e.g. \{{ )
     * @param string $str
     * @param int $p0
     * @param string $pat1
     * @param string $pat2
     * @return array|false[]
     * @throws Exception
     */
    private static function strPosMatching(string $str, int $p0 = 0, string $pat1 = '{{', string $pat2 = '}}'): array
    {

        if (!$str) {
            return [false, false];
        }
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