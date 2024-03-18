<?php
namespace PgFactory\MarkdownPlus;

use cebe\markdown\MarkdownExtra;
use Exception;
use Kirby\Exception\InvalidArgumentException;
use function PgFactory\PageFactory\explodeTrim;
use function PgFactory\PageFactory\getFile;
use function PgFactory\PageFactory\shieldStr;

include_once __DIR__ . '/MdPlusHelper.php';

/*
 * MarkdownPlus extends \cebe\markdown\MarkdownExtra
 * Why not Kirby's native ParsedownExtra?
 *  -> no access to array of lines surrounding current line -> not possible to inject lines
 *  -> required by DivBlock pattern
 */

 // HTML tags that must not have a closing tag:
const MDPMD_SINGLETON_TAGS =   'img,input,br,hr,meta,embed,link,source,track,wbr,col,area';
const DEFAULT_TABULATOR_WIDTH = '6em';
const INLINE_ELEMENTS = "a,abbr,acronym,b,bdo,big,br,button,cite,code,dfn,em,i,img,input,kbd,label,map,object,'.
'output,q,samp,script,select,small,span,strong,sub,sup,textarea,time,tt,var,skip,";
  // 'skip' is a pseudo tag used by MarkdownPlus.
const ABBREVIATIONS_FILE    = 'site/custom/variables/abbreviations.txt';

class MarkdownPlus extends MarkdownExtra
{
    private static int $asciiTableInx   = 1;
    private static int $imageInx        = 0;
    private static int $tabulatorInx    = 1;

    private string $divblockChars;
    private bool $compileCodeBlocks;
    private bool $enableIcons;
    private bool $enableSmartypants;
    private bool $isParagraphContext;
    private string $sectionIdentifier;
    private bool $removeComments;
    public  static $lang;
    private static array $abbr = [];

    /**
     */
    public function __construct()
    {
        $options = kirby()->option('pgfactory.markdownplus.options');
        $this->divblockChars =      $options['divblockChars'] ?? '@%';
        $this->compileCodeBlocks =  $options['compileCodeBlocks'] ?? true;
        $this->enableSmartypants =  $options['enableSmartypants'] ?? true;
        $this->enableIcons =        $options['enableIcons'] ?? true;

        if ($this->enableIcons) {
            MdPlusHelper::findAvailableIcons();
        }

        $lang = kirby()->language();
        self::$lang = $lang ? $lang->code() : '';

        $this->loadAbbreviations();
    } // __construct


    /**
     * Compiles a markdown string to HTML:
     * @param string $str
     * @param bool $omitPWrapperTag
     * @param string $sectionIdentifier
     * @return string
     * @throws Exception
     */
    public function compile(string $str, bool $omitPWrapperTag = false, string $sectionIdentifier = '', $removeComments = true):string
    {
        if (!$str) {
            return '';
        }
        $this->sectionIdentifier = $sectionIdentifier;
        $this->removeComments = $removeComments;
        $this->isParagraphContext = false;
        $str = $this->preprocess($str);
        $html = parent::parse($str);
        return $this->postprocess($html, $omitPWrapperTag);
    } // compile


    /**
     * Compiles inline level string, i.e. like inside a paragraph
     * @param string $str
     * @param bool $omitPWrapperTag
     * @return string
     * @throws Exception
     */
    public function compileParagraph(string $str, bool $omitPWrapperTag = false):string
    {
        if (trim($str) === '') {
            return '';
        }
        $this->isParagraphContext = true;
        $this->removeComments = true;
        $str = $this->preprocess($str);
        $html = parent::parseParagraph($str);
        return $this->postprocess($html, $omitPWrapperTag);
    } // compile



    // === AsciiTable ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyAsciiTable(string $line): bool
    {
        // asciiTable starts with '|==='
        if (strncmp($line, '|===', 4) === 0) {
            return true;
        }
        return false;
    }

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeAsciiTable(array $lines, int $current): array
    {
        $block = [
            'asciiTable',
            'content' => [],
            'args' => false
        ];
        $firstLine = $lines[$current];
        if (preg_match('/^\|===*\s+(.*)$/', $firstLine, $m)) {
            $block['args'] = $m[1];
        }
        for($i = $current + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (strncmp($line, '|===', 4) !== 0) {
                $block['content'][] = $line;
            } else {
                // stop consuming when second '|===' found
                break;
            }
        }
        return [$block, $i];
    }

    /**
     * @param array $block
     * @return string
     * @throws Exception
     */
    protected function renderAsciiTable(array $block): string
    {
        // parse table source, convert to 2D-table:
        list($table, $nCols, $nRows, $rowAttribs) = $this->parseTableSource($block['content']);

        // prepare table attributes:
        list($caption, $attrsStr) = $this->prepareTableAttributes($block['args']);
        if ($attrsStr === null) {
            return '';
        }

        // now render the table:
        $out = "<table $attrsStr>\n";
        $out .= $caption;

        // render header as defined in first row, e.g. |# H1|H2
        $row = 0;
        if (isset($table[0][0]) && (($table[0][0][0]??'') === '#')) {
            $row = 1;
            $table[0][0] = substr($table[0][0],1);
            $out .= "  <thead>\n    <tr>\n";
            for ($col = 0; $col < $nCols; $col++) {
                $cell = $table[0][$col] ?? '';
                $cell = self::compileParagraph($cell, omitPWrapperTag: true);
                $out .= "\t\t<th class='mdp-col-".($col+1)."'>$cell</th>\n";
            }
            $out .= "    </tr>\n  </thead>\n";
        }

        // render table body:
        $out .= "  <tbody>\n";
        for (; $row < $nRows; $row++) {
            $rowAttrib = $rowAttribs[$row]??'';
            $out .= "\t<tr$rowAttrib>\n";
            $colspan = 1;
            for ($col = 0; $col < $nCols; $col++) {
                $cell = $table[$row][$col] ?? '';
                if ($cell === '>') {    // colspan?  e.g. |>|
                    $colspan++;
                    continue;
                } elseif ($cell) {
                    $cell = self::compile($cell, omitPWrapperTag: true);
                }
                $colspanAttr = '';
                if ($colspan > 1) {
                    $colspanAttr = " colspan='$colspan'";
                }
                $out .= "\t\t<td class='mdp-row-".($row+1)." mdp-col-".($col+1)."'$colspanAttr>\n\t\t\t$cell\t\t</td>\n";
                $colspan = 1;
            }
            $out .= "\t</tr>\n";
        }

        $out .= "  </tbody>\n";
        $out .= "</table><!-- /asciiTable -->\n";

        return $out;
    } // AsciiTable

    /**
     * @param array $content
     * @return array
     * @throws Exception
     */
    private function parseTableSource(array $content): array
    {
        $table = [];
        $rowAttribs = [];
        $nCols = 0;
        $row = 0;
        $col = -1;

        // parse source, transform into 2D-array:
        for ($i = 0; $i < sizeof($content); $i++) {
            $line = $content[$i];

            if (strncmp($line, '|---', 4) === 0) {  // new row
                // check for row class, like "|--- ":
                $line = ltrim(substr($line, 4), ' -');
                if ($line) {
                    if (preg_match('/\{:(.*)}/', trim($line), $m)) {
                        $line = $m[1];
                    }
                    $args = MdPlusHelper::parseInlineBlockArguments($line);
                    $rowAttribs[$row+1] = $args['htmlAttrs'];
                }
                $row++;
                $col = -1;
                continue;
            }

            if (isset($line[0]) && ($line[0] === '|')) {  // next cell starts
                $line = substr($line, 1);
                $cells = preg_split('/\s(?<!\\\)\|/', $line); // pattern is ' |'
                foreach ($cells as $cell) {
                    if ($cell && ($cell[0] === '>')) {
                        $cells2 = explode('|', $cell);
                        foreach ($cells2 as $c) {
                            $col++;
                            $table[$row][$col] = $c;
                        }
                        unset($cells2);
                        unset($c);
                    } else {
                        $col++;
                        $table[$row][$col] = str_replace('\|', '|', $cell);
                    }
                }

            } else {
                if ($col < 0) {
                    throw new Exception("Error in AsciiTable: cell definition needs leading '|' (in \"$line\")");
                }
                $table[$row][$col] .= "\n$line";
            }
            $nCols = max($nCols, $col);
        }
        $nCols++;
        $nRows = $row + 1;
        unset($cells);
        return array($table, $nCols, $nRows, $rowAttribs);
    } // parseTableSource

    /**
     * @param string $args
     * @return array|null[]
     */
    private function prepareTableAttributes(string $args): array
    {
        $inx = self::$asciiTableInx++;
        $caption = trim($args);
        $caption = preg_replace('/\{:(.*?)}/', "$1", $caption);
        if ($caption) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($caption);
            if (($attrs['tag'] === 'skip') || ($attrs['lang'] && ($attrs['lang'] !== kirby()->language()->code()))) {
                return array(null, null);
            }
            $caption = $attrs['text'];
            $caption = "\t  <caption>$caption</caption>\n";
            $attrsStr = $attrs['htmlAttrs'];
            $attrsStr = preg_replace('/class=["\'].*?["\']/', '', $attrsStr);
            $class = "mdp-table mdp-table-$inx";
            if ($attrs['class']) {
                $class .= ' ' . $attrs['class'];
            }
            if (!$attrs['id']) {
                $attrsStr = "id='mdp-table-$inx' " . $attrsStr;
            }
            $attrsStr .= " class='$class'";
        } else {
            $attrsStr = "id='mdp-table-$inx' class='mdp-table mdp-table-$inx'";
        }
        return array($caption, $attrsStr);
    } // prepareTableAttributes




    // === DivBlock ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyDivBlock(string $line): bool
    {
        // if a line starts with at least 3 marker-chars it is identified as a div-block
        // fence chars e.g. ':$@' -> defined in PageFactory::$config['divblockChars']
        $marker = $line[0]??' ';
        if (str_contains($this->divblockChars, $marker) &&
                preg_match("/^$marker{3,10}\s+\S/", $line)) {
            return true;
        }
        return false;
    } // identifyDivBlock

    /**
     * @param array $lines
     * @param int $current
     * @return array
     * @throws Exception
     */
    protected function consumeDivBlock(array $lines, int $current): array
    {
        $line = rtrim($lines[$current]);
        // create block array
        $block = [
            'divBlock',
            'content' => [],
            'marker' => $line[0],
            'attributes' => '',
            'literal' => false,
            'mdpBlockType' => true
        ];

        // detect class or id and fence length (can be more than 3 backticks)
        $depth = 0;
        $marker = $block['marker'];
        $pattern = preg_quote($marker).'{3,10}';
        if (preg_match("/($pattern)(.*)/",$line, $m)) {
            $fence = $m[1];
            $rest = trim($m[2]);
            if ($rest && ($rest[0] === '{')) {      // non-mdp block: e.g. "::: {#id}
                $block['mdpBlockType'] = false;
                $depth = 1;
                $rest = trim(str_replace(['{','}'], '', $rest));
            }
        } else {
            throw new Exception("Error in Markdown source line $current: $line");
        }
        $attrs = MdPlusHelper::parseInlineBlockArguments($rest);

        $tag = $attrs['tag'];
        $isInlineTag = str_contains(INLINE_ELEMENTS, ",$tag,");
        if ($isInlineTag) {
            if ($attrs['literal'] === null) {
                $attrs['literal'] = true;
            }
        }

        $block['tag'] = $tag;
        $block['attributes'] = $attrs['htmlAttrs'];
        $block['lang'] = $attrs['lang'];
        $block['literal'] = $attrs['literal'];
        $block['meta'] = '';
        $block['inline'] = $isInlineTag;

        // consume all lines until end-tag, e.g. @@@
        $count = count($lines);
        $content = $attrs['text']."\n" ?: '';
        for($i = $current + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match("/^($pattern)\s*(.*)/", $line, $m)) { // it's a potential fence line
                $fenceEndCandidate = $m[1];
                $rest = $m[2];
                if ($fence === $fenceEndCandidate) {    // end tag we have to consider:
                    if ($rest !== '') {    // case nested or consequitive block
                        if ($block['mdpBlockType']) {   // mdp-style -> consecutive block starts:
                            $i--;
                            break;
                        }
                        $depth++;

                    } else {                    // end of block
                        $depth--;
                        if ($depth < 1) {       // only in case of non-mdpBlocks we may have to skip nested end-tags:
                            break;
                        }
                    }
                }
            }
            $content .= "$line\n";
        }

        if ($content) {
            if ($block['literal']) {
                $block['content'][0] = MdPlusHelper::shieldStr($content, 'block');

            } elseif ($attrs['inline'] || $isInlineTag) {
                $content = $this->compileEmbeddedDivBlock($content);
                $content = self::compileParagraph($content, true);
                $block['content'][0] = MdPlusHelper::shieldStr($content, 'inline');

            } else {
                $block['content'][0] = MdPlusHelper::shieldStr($content, 'md');
            }
        } else {
            $block['content'][0] = '';
        }
        return [$block, $i];
    } // consumeDivBlock

    /**
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function compileEmbeddedDivBlock(string $str): string
    {
        $lines = explode("\n", $str);
        $block = false;
        $str = '';
        foreach ($lines as $line) {
            if ($block === false) {
                $marker = $line[0]??' ';
                if (str_contains($this->divblockChars, $marker) &&
                    preg_match("/^$marker{3,10}(.*)/", $line, $m)) {
                    $fence = $m[1];
                    $l = strlen($fence);
                    $block = '';
                    $attrs = MdPlusHelper::parseInlineBlockArguments($line);
                } else {
                    $str .= "$line\n";
                }
            } else {
                if (substr($line, 0, $l) === $fence) {
                    if ($attrs['inline']??false) {
                        $html = self::compileParagraph($block, true);
                    } else {
                        $html = self::compile($block, true);
                    }
                    $tag = $attrs['tag'] ?: 'div';
                    $_tag = str_contains(MDPMD_SINGLETON_TAGS, $tag)? '': "</$tag>";
                    $str .= "<$tag{$attrs['htmlAttrs']}>\n$html\n$_tag\n";
                    $block = false;
                } else {
                    $block .= $line;
                }
            }
        }
        return $str;
    } // compileEmbeddedDivBlock

    /**
     * @param array $block
     * @return string
     */
    protected function renderDivBlock(array $block): string
    {
        $tag = $block['tag'];
        $attrs = $block['attributes'];

        // $tag == 'skip' means omit from output:
        if ($tag === 'skip') {
            return '';
        }

        // exclude blocks with lang option set but is not current language:
        if ($block['lang'] && ($block['lang'] !== self::$lang)) {
            return '';
        }

        $out = $block['content'][0];

        if (str_contains($block['meta'], 'html')) {
            return $out;
        }


        if (($tag === '') && !$attrs) {
            return "$out\n\n";
        } else {
            $tag = $tag?: 'div';
            $_tag = str_contains(MDPMD_SINGLETON_TAGS, $tag)? '': "</$tag>";

            if ($block['inline']) {
                return "<$tag$attrs>$out$_tag";
            } else {
                return "\n\n<$tag$attrs>\n$out\n$_tag<!-- $tag$attrs -->\n\n\n";
            }
        }
    } // renderDivBlock




    // === Tabulator ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyTabulator(string $line): bool
    {
        if (preg_match('/(\s\s|\t) ([.\d]{1,6}[\w%]{1,2})? >> [\s\t]/x', $line)) { // identify patterns like '{{ tab( 7em ) }}'
            return true;
        }
        return false;
    } // identifyTabulator

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeTabulator(array $lines, int $current): array
    {
        $block = [
            'tabulator',
            'content' => [[]],
            'widths' => [],
        ];

        // consume following lines containing >>
        $p = 0;
        for($i = $current, $count = count($lines); $i <= $count-1; $i++) {
            $line = $lines[$i];
            if (preg_match_all('/([.\d]{1,6}[\w%]{1,2})? >> [\s\t]/x', $line, $m)) {
                $parts = preg_split('/[\s\t]* ([.\d]{1,6}[\w%]{1,2})? >> [\s\t]/x', $line);
                foreach ($parts as $n => $elem) {
                    $block['content'][$p][$n] = $elem;
                    $block['widths'][$p][$n] = $m[1][$n]??false;
                }
                $p++;
            } else {
                if (!$line) {
                    break;
                }
                $block['content'][$p-1][$n] .= "\n".$line;
            }
        }
        return [$block, $i];
    } // consumeTabulator


    /**
     * @param array $block
     * @return string
     * @throws Exception
     */
    protected function renderTabulator(array $block): string
    {
        $inx = self::$tabulatorInx++;
        $out = '';
        $style = '';
        $widths = $block['widths']??[];
        foreach ($block['content'] as $n => $parts) {
            $n++;
            $last = sizeof($parts) - 1;
            $line = '';
            foreach ($parts as $p => $elem) {
                $i = $p + 1;
                $lastClass = ($p === $last) ? ' tt-last' : '';
                $elem = self::compileParagraph($elem);
                $line .= "<div class='tt$i$lastClass'>$elem</div>";
                if ($w = ($widths[$n-1][$p]??false)) {
                    $style .= "--tt$i-width: $w;";
                }
            }
            $out .= "<div class='mdp-tabulator-wrapper mdp-tabulator-wrapper-$n'>\n$line\n</div>\n";
        }
        if ($style) {
            $style = shieldStr(" style='$style'");
        }
        return "<div class='mdp-tabulator-outer-wrapper mdp-tabulator-outer-wrapper-$inx'$style>\n$out\n</div><!-- /mdp-tabulator-outer-wrapper-$inx -->\n";
    } // renderTabulator



    // === DefinitionList ==================
    /**
     * @param string $line
     * @param array $lines
     * @param int $current
     * @return bool
     */
    protected function identifyDefinitionList(string $line, array $lines, int $current): bool
    {
        // if next line starts with ': ', it's a dl:
        if (isset($lines[$current+1]) && strncmp($lines[$current+1]??'', ': ', 2) === 0) {
            return true;
        } elseif (preg_match('/^\{:.*?}$/', $line) && strncmp($lines[$current+2]??'', ': ', 2) === 0) {
            return true;
        }
        return false;
    } // identifyDefinitionList

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeDefinitionList(array $lines, int $current): array
    {
        // create block array
        $block = [
            'definitionList',
            'content' => [],
            'attrs' => '',
        ];

        if (preg_match('/^\{:(.*?)}$/', $lines[$current], $m)) {
            $block['attrs'] = $m[1];
            $current++;
        }

        // consume all lines until 2 empty line
        $nEmptyLines = 0;
        $dt = -1;
        for($i = $current, $count = count($lines); $i < $count-1; $i++) {
            if (!$lines[$i]) {
                if ($nEmptyLines++ < 1) {
                    continue;
                }
                break;
            }
            if (($lines[$i][0] !== ':') && (($lines[$i+1][0]??' ') === ':')) {
                $dt++;
                $block['content'][$dt]['dt'] = $lines[$i++];
                $block['content'][$dt]['dd'] = substr($lines[$i],1);
                while (($lines[$i+1][0]??' ') === ':') {
                    $i++;
                    $block['content'][$dt]['dd'] .= "\n".substr($lines[$i],1);
                }
                $nEmptyLines = 0;
            } else {
                break;
            }
        }
        return [$block, $i-1];
    } // consumeDefinitionList

    /**
     * @param array $block
     * @return string
     * @throws Exception
     */
    protected function renderDefinitionList(array $block): string
    {
        $attrsStr = '';
        if ($block['attrs']) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($block['attrs']);
            $attrsStr = $attrs['htmlAttrs'];
        }
        $out = '';
        foreach ($block['content'] as $item) {
            $dt = trim(self::compileParagraph($item['dt'], true));
            $out .= "\t<dt>$dt</dt>\n";

            $dd = self::compile($item['dd']);
            $out .= "\t<dd>\n$dd\t</dd>\n\n";
        }
        $out = $this->catchAndInjectTagAttributes($out);
        $out = <<<EOT

<dl$attrsStr>
$out</dl>


EOT;
        return $out;
    } // renderDefinitionList



    // === OrderedList ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyOrderedList(string $line): bool
    {
        if (preg_match('/^\d+ !? \. /x', $line)) {
            return true;
        }
        return false;
    } // identifyOrderedList

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeOrderedList(array $lines, int $current): array
    {
        // create block array
        $block = [
            'orderedList',
            'content' => [],
            'start' => false,
        ];

        // consume all lines until 2 empty line
        for($i = $current, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (!preg_match('/^\d+!?\./', $line)) {  // empty line
                    break;
            } elseif (preg_match('/^(\d+)!\.\s*(.*)/', $line, $m)) {
                $block['start'] = $m[1];
                $line = $m[2];
            } elseif (preg_match('/^(\d+)\.\s*(.*)/', $line, $m)) {
                $line = $m[2];
            }
            $block['content'][] = $line;
        }
        return [$block, $i];
    } // consumeOrderedList

    /**
     * @param array $block
     * @return string
     * @throws Exception
     */
    protected function renderOrderedList(array $block): string
    {
        $out = '';
        $start = '';
        if ($block['start'] !== false) {
            $start = " start='{$block['start']}'";
        }
        foreach ($block['content'] as $line) {
                $line = self::compile($line, true);
                $line = trim($line);
                $out .= "<li>$line</li>\n";
        }
        $out = "<ol$start>\n$out</ol>\n";
        return $out;
    } // renderOrderedList



    /**
     * @marker ~~
     */

    protected function parseStrike(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing ~~)
        if (preg_match('/^~~(.+?)~~/', $markdown, $matches)) {
            return [
                ['strike', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '~~'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderStrike(array $element): string
    {
        return '<del>' . $this->renderAbsy($element[1]) . '</del>';
    }




    /**
     * @marker ~
     */

    protected function parseSubscript(string $markdown): array
    {
        if (preg_match('/^~(.{1,9}?)~/', $markdown, $matches)) {
            return [
                ['subscript', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '~'], 1];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderSubscript(array $element): string
    {
        return '<sub>' . $this->renderAbsy($element[1]) . '</sub>';
    }



    /**
     * @marker ^^
     */
    protected function parseKbd(string $markdown): array
    {
        if (preg_match('/^\^\^(.{1,5}?)\^\^/', $markdown, $matches)) {
            return [
                ['kbd', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '^^'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderKbd(array $element): string
    {
        return '<kbd>' . $this->renderAbsy($element[1]) . '</kbd>';
    }



    /**
     * @marker ^
     */
    protected function parseSuperscript(string $markdown): array
    {
        if (preg_match('/^\^(.{1,20}?)\^/', $markdown, $matches)) {
            return [
                ['superscript', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '^'], 1];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderSuperscript(array $element): string
    {
        return '<sup>' . $this->renderAbsy($element[1]) . '</sup>';
    }



    /**
     * @marker ==
     */
    protected function parseMarked(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing ==)
        if (preg_match('/^==(.+?)==/', $markdown, $matches)) {
            return [
                ['marked', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '=='], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderMarked(array $element): string
    {
        return '<mark>' . $this->renderAbsy($element[1]) . '</mark>';
    }



    /**
     * @marker ++
     */
    protected function parseInserted(string $markdown): array
    {
        if (preg_match('/^\+\+(.+?)\+\+/', $markdown, $matches)) {
            return [
                ['inserted', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '++'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderInserted(array $element): string
    {
        return '<ins>' . $this->renderAbsy($element[1]) . '</ins>';
    }



    /**
     * @marker __
     */
    protected function parseUnderlined(string $markdown): array
    {
        if (preg_match('/^__(.+?)__/', $markdown, $matches)) {
            return [
                ['underlined', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '__'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderUnderlined(array $element): string
    {
        return '<u>' . $this->renderAbsy($element[1]) . '</u>';
    }



    /**
     * @marker ``
     */
    protected function parseDoubleBacktick(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing `)
        if (preg_match('/^``(?!`)(.+?)``/', $markdown, $matches)) {
            return [
                ['doublebacktick', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '``'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderDoubleBacktick(array $element): string
    {
        return "<samp>" . $this->renderAbsy($element[1]) .  "</samp>";
    }




    /**
     * @marker ![
     *
     * ![alt text](img.jpg "Caption...")
     */
    protected function parseImage($markdown)
    {
        if (preg_match('/^!\[ (.+?) ]\( ( (.+?) (["\']) (.+?) \4) \s* \)/x', $markdown, $matches)) {
            return [
                ['image', $matches[1].']('.$matches[2]],
                strlen($matches[0])
            ];
        } elseif (preg_match('/^!\[ (.+?) ]\( (.+?) \)/x', $markdown, $matches)) {
            return [
                ['image', $matches[1].']('.$matches[2]],
                strlen($matches[0])
            ];
        }
        return [['text', '!['], 2];
    }

    /**
     * @param $element
     * @return mixed|string
     * @throws InvalidArgumentException
     */
    protected function renderImage($element)
    {
        self::$imageInx++;
        $str = $element[1];
        list($alt, $src) = explode('](', $str);
        if (preg_match('/^ (["\']) (.+) \1 \s* /x', $alt, $m)) {
            $alt = $m[2];
        }
        if (preg_match('/^ (["\']) (.+) \1 \s* /x', $src, $m)) {
            $src = $m[2];
        }
        $alt = str_replace(['"', "'"], ['&quot;','&apos;'], $alt);

        $caption = '';
        if (preg_match('/^ (.*?) \s+ (.*) /x', $src, $m)) {
            $src = $m[1];
            $caption = $m[2];
            if (preg_match('/^ (["\']) (.+) \1 \s* /x', $src, $mm)) {
                $src = $mm[2];
            }
            if (preg_match('/^ (["\']) (.+) \1 \s* /x', $caption, $mm)) {
                $caption = $mm[2];
            }
            $caption = str_replace(['"', "'"], ['&quot;','&apos;'], $caption);
        }

        if (!str_contains($src, '/')) {
            $src = page()->file($src)->url();
        }

        $attr = "src:'$src', alt:'$alt', caption:'$caption'";
        if (function_exists('PgFactory\\MarkdownPlus\\img')) {
            $str = $this->processByMacro('img', $attr);
        } elseif ($caption) {
            $str = "<img src='$src' alt='$alt'>";
            $str = "<figure>$str<figcaption>$caption</figcaption></figure>";
        } else {
            $str = "<img src='$src' alt='$alt'>";
        }
        return $str;
    } // renderImage



    /**
     * @marker [
     *
     * [link text](https://www.google.com)
     */
    protected function parseLink($markdown)
    {
        if (preg_match('/^\[ ([^]]+) ]\(([^)]+) \)/x', $markdown, $matches)) {
            $linkText = $matches[1];
            $link = $matches[2];
            $title = '';
            // extract optional title:
            if (preg_match('/(.*?)\s+(.*)/', $link, $m)) {
                $link = $m[1];
                $title = trim($m[2], '"\'');
            }
            return [
                ['link', $link, $linkText, $title], strlen($matches[0])
            ];
        }
        return [['text', '['], 1];
    }

    /**
     * @param $element
     * @return mixed|string
     * @throws InvalidArgumentException
     */
    protected function renderLink($element)
    {
        $link = trim($element[1], '"\'');
        $linkText = $element[2];
        $title = preg_replace('/^ ([\'"]) (.*) \1 $/x', "$2", $element[3]);

        if (function_exists('PgFactory\\MarkdownPlus\\link')) {
            $attr = "url:'$link', ";
            $q = (!str_contains($linkText, "'")) ? "'" : '"';
            $attr .= "text:$q$linkText$q, ";
            $q = (!str_contains($title, "'")) ? "'" : '"';
            $attr .= "title:$q$title$q";
            $str = $this->processByMacro('link', $attr);
        } else {
            $title = $title ? " title='$title'" : '';
            $str = "<a href='$link'$title>$linkText</a>";
        }
        return $str;
    } // renderLink





    /**
     * @marker :
     */
    protected function parseIcon(string $markdown): array
    {
        if ($this->enableIcons && preg_match('/^:(\w+):/', $markdown, $matches)) {
            if (MdPlusHelper::iconExists($matches[1])) {
                return [
                    ['icon', $matches[1]],
                    strlen($matches[0])
                ];
            }
        }
        return [['text', ':'], 1];
    }

    /**
     * @param array $element
     * @return string
     * @throws Exception
     */
    protected function renderIcon(array $element): string
    {
        $iconName = $element[1];
        return MdPlusHelper::renderIcon($iconName);
    } // renderIcon




    //=== Pre- and Post-Processing ===================================
    /**
     * Applies Pre-Processing: shielding chars, includes, handling variables, fixing HTML
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function preprocess(string $str): string
    {
        if ($this->removeComments) {
            $str = MdPlusHelper::removeCStyleComments($str);
            $str = MdPlusHelper::zapFileEND($str);
        }

        $str = $this->handleFrontmatter($str);

        $str = $this->handleAbbreviations($str);

        $str = $this->handleShieldedCharacters($str);

        $str = $this->handleIncludes($str);

        $str = $this->fixCebeBugs($str);

        $str = $this->handleLineBreaks($str);

        // {{...}} alone on line -> add NLs around it:
        return preg_replace('/(\n{{.*}}\n)/U', "\n$1\n", $str);
    } // preprocess


    /**
     * Applies Post-Processing: kirbyTags, SmartyPants, fixing HTML, attribute injection
     * @param string $str
     * @param bool $omitPWrapperTag
     * @return string
     * @throws Exception
     */
    private function postprocess(string $str, bool $omitPWrapperTag = false): string
    {
        // lines that contain but a variable or macro (e.g. "<p>{{ lorem( help ) }}</p>") -> remove enclosing P-tags:
        $str = preg_replace('|<p> ({{ .*? }}) </p>|xms', "$1", $str);

        // check for kirbytags, get them compiled:
        $str = $this->handleKirbyTags($str);

        $str = $this->_compileCodeBlocks($str);

        // handle smartypants:
        if (kirby()->option('smartypants')) {
            $str = $this->smartypants($str);
        }

        // remove outer <p> tags if requested:
        if ($omitPWrapperTag) {
            $str = preg_replace('|^ (\s*) <p> ( .* ) </p> (\s*) |xms', "$1$2$3", $str);
        }

        $str = $this->catchAndInjectTagAttributes($str); // ... {: .cls}

        $str = MdPlusHelper::unshieldStr($str);

        $str = str_replace(['<literal>','</literal>'], '', $str);

        // clean up shielded characters, e.g. '@#123;''@#123;' to '&#123;' :
        return preg_replace('/@#(\d+);/m', "&#$1;", $str);
    } // postprocess


    /**
     * Applies (PageFactory-)SmartyPants translation:
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function smartypants(string $str): string
    {
        if (!$this->enableSmartypants) {
            return $str;
        }
        $smartypants =    [
                '/(?<!-)-&gt;/ms'  => '&rarr;',
                '/(?<!=)=&gt;/ms'  => '&rArr;',
                '/(?<!!)&lt;-/ms'  => '&larr;',
                '/(?<!=)&lt;=/ms'  => '&lArr;',
                '/(?<!\.)\.\.\.(?!\.)/ms'  => '&hellip;',
                '/(?<!-|!)--(?!-|>)/ms'  => '&ndash;', // in particular: <!-- -->
                '/(?<!-)---(?!-)/ms'  => '&mdash;',
                '/(?<!&lt;)&lt;<(?!&lt;)/ms'  => '&#171;',      // <<
                '/(?<!&lt;)&lt;&lt;(?!&lt;)/ms'  => '&#171;',   // <<
                '/(?<!&gt;)>&gt;(?!&gt;)/ms'  => '&#187;',      // >>
                '/(?<!&gt;)&gt;&gt;(?!&gt;)/ms'  => '&#187;',   // >>
                '/\bEURO\b/ms'  => '&euro;',
                //'/sS/ms'  => 'ß',
                '|1/4|ms'  => '&frac14;',
                '|1/2|ms'  => '&frac12;',
                '|3/4|ms'  => '&frac34;',
                '|0/00|ms'  => '&permil;',
                '/(?<!,),,(?!,)/ms'  => '„',
                "/(?<!')''(?!')/ms"  => '”',
                "/(?<!`)``(?!`)/ms"  => '“',
                "/(?<!~)~~(?!~)/ms"  => '≈',
                '/\bINFINITY\b/ms'  => '∞',
        ];

        $out = '';
        list($p1, $p2) = MdPlusHelper::strPosMatching($str, 0, '<tt>', '</tt>');
        if ($p1 !== false) {
            while ($p1 !== false) {
                $s1 = substr($str, 0, $p1);
                $s1 = preg_replace(array_keys($smartypants), array_values($smartypants), $s1);
                $s2 = substr($str, $p1, ($p2 - $p1 + 6));
                $s3 = substr($str, $p2 + 6);
                $out .= "$s1$s2";
                $str = $s3;
                list($p1, $p2) = MdPlusHelper::strPosMatching($str, 0, '<tt>', '</tt>');
                if ($p1 === false) {
                    $s3 = preg_replace(array_keys($smartypants), array_values($smartypants), $s3);
                    $out .= $s3;
                }
            }
        } else {
            $out = preg_replace(array_keys($smartypants), array_values($smartypants), $str);
        }

        return $out;
    } // smartypants


    /**
     * Converts shielded characters (\c) to their unicode equivalent
     * @param string $str
     * @return string
     */
    private function handleShieldedCharacters(string $str): string
    {
        $p = 0;
        while ($p=strpos($str, '\\', $p)) {
            $ch = $str[$p+1]??'';
            if (!$ch) {
                break;
            }
            if ($ch === "\n") {
                $unicode = '<br>';
            } else {
                $o = ord($str[$p + 1]);
                $unicode = "@#$o;";
            }
            $str = substr($str, 0, $p) . $unicode . substr($str, $p+2);
            $p += 2;
        }
        return $str;
    } // handleShieldedCharacters


    /**
     * Executes embedded include instructions, e.g. (include: xy)
     * @param string $str
     * @return string
     * @throws InvalidArgumentException
     */
    private function handleIncludes(string $str): string
    {
        // pattern: "(include: -incl.md)"
        if (preg_match_all('/\(include: (.*?) \)/x', $str, $m)) {
            foreach ($m[1] as $i => $value) {
                $val = $this->handleInclude($value, ' ');
                $str = str_replace($m[0][$i], $val, $str);
            }
        }
        return $str;
    } // handleIncludes


    /**
     * Fixes irregular behavior of cebe/markdown compiler:
     * -> ul and ol not recognized if no empty line before pattern
     * @param string $str
     * @return string
     */
    private function fixCebeBugs(string $str): string
    {
        $lines = explode("\n", $str);
        foreach ($lines as $i => $line) {
            if (!$line) {
                continue;
            } elseif (str_starts_with($line, '- ')) {
                if (($lines[$i-1]??false) && preg_match('/^[^\-\s].*/',$lines[$i-1])) {
                    $lines[$i-1] .= "\n";
                }
            } elseif (preg_match('/^\d+!?\./', $line, $m)) {
                if (($lines[$i-1]??false) && !preg_match('/^\d+!?\./', $lines[$i-1], $m)) {
                    $lines[$i-1] .= "\n";
                }
            } elseif (($line[0] === '<') && ($line[strlen($line)-1] === '>')) {
                $lines[$i] = "<literal>$line</literal>";
            }
        }
        return implode("\n", $lines);
    } // fixCebeBugs


    /**
     * Intercepts and applies attribute injection instructions of type "{: }"
     * @param string $str
     * @return string
     */
    private function catchAndInjectTagAttributes(string $str): string
    {
        if (!str_contains($str, '{:')) {
            return $str;
        }


        // special case: string starts with attrib-def, i.e. no surrounding tags:
        if (preg_match('|^\s*(?<!\\\){:(.*?)}\s*(.*)|', $str, $m)) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($m[1]);
            $attrsStr = $attrs['htmlAttrs'];
            if ($this->isParagraphContext) {
                $str = "<span$attrsStr>$m[2]</span>";
            } else {
                $str = "<div$attrsStr>$m[2]</div>";
            }
            return $str;

        // case string contains no leading HTML tag -> wrap it:
        } elseif (!preg_match('/(?<!\\\)<\w+/', $str)) {
            if ($this->isParagraphContext) {
                $str = "<span>$str</span>";
            } else {
                $str = "<div>$str</div>";
            }
        }

        // run through HTML line by line:
        $lines = explode("\n", $str);
        $attrDescr = false;
        $nLines = sizeof($lines);
        for ($i=0; $i<$nLines; $i++) {
            $line = &$lines[$i];
            
            // case attribs found and not consumed yet -> apply to following tag:
            if ($attrDescr) {
                if (preg_match('/(\s*)(<.*?>)/', $line, $mm)) {
                    $line = $mm[1].$this->applyAttributes($line, $attrDescr, $mm[2]);
                }
                $attrDescr = false;
                continue;
            }

            // line with nothing but attr descriptor:
            if (preg_match('|^<p> \s* {:(.*?)} \s* </p> $|x', $line, $m)) {
                $attrDescr = $m[1];
                unset($lines[$i]);
                continue;
            }

            if (!preg_match('|(.*) {:(.*?)} (.*)|x', $line, $m)) {
                continue;
            }
            $attrDescr = $m[2];
            $line = $m[1].$m[3];
            if (preg_match('/(\s*)(<.*?>)/', $line, $mm)) {
                $line = $mm[1].$this->applyAttributes($line, $attrDescr, $mm[2]);
                $attrDescr = false;
            }

        }
        return implode("\n", $lines);
    } // catchAndInjectTagAttributes


    /**
     * Helper for catchAndInjectTagAttributes()
     * @param string $line
     * @param string $attribs
     * @param string $tag
     * @return string
     */
    private function applyAttributes(string $line, string $attribs, string $tag): string
    {
        $attrs = MdPlusHelper::parseInlineBlockArguments($attribs);
        
        if ($p = strpos($line, '>')) {
            if ($line[$p-1] === '/') {
                $elem = rtrim(substr($line, 0, $p-1));
            } else {
                $elem = rtrim(substr($line, 0, $p));
            }
        } else {
            $elem = $line;
        }
        $rest = substr(trim($line), strlen($tag));

        // handle tag=skip:
        if ($attrs['tag'] === 'skip') {
            return '';
        }

        // handle id:
        if ($value = $attrs['id']??false) {
            if (preg_match("/\sid= ['\"] .*? ['\"]/x", $elem, $mm)) {
                $elem = str_replace($mm[0], '', $elem);
            }
            $elem .= " id='$value'";
        }

        // handle class:
        if ($value = $attrs['class']??false) {
            if (str_contains($elem, 'class=')) {
                $elem = preg_replace("/(class=['\"])/", "$1$value ", $elem);
            } else {
                $elem .= " class='$value'";
            }
        }

        // handle style:
        if ($value = $attrs['style']??false) {
            $value = rtrim($value, '; ').'; ';
            if (str_contains($elem, 'style=')) {
                $elem = preg_replace("/(style=['\"])/", "$1$value ", $elem);
            } else {
                $elem .= " style='$value'";
            }
        }

        // misc attrs:
        if ($attrs['attr']??false) {
            foreach ($attrs['attr'] as $k => $v) {
                if (!str_contains($elem,"$k='$v'")) {
                    $elem .= " $k='$v'";
                }
            }
        }

        return "$elem>$rest";
    } // applyAttributes


    /**
     * Translates line-break code ' BR ' to HTML <br>
     * @param string $str
     * @return string
     */
    private function handleLineBreaks(string $str): string
    {
        return preg_replace("/(\\\\\n|\s(?<!\\\)BR\s)/m", "<br>\n", $str);
    } // handleLineBreaks


    /**
     * Executes include instructions
     * @param string $argsStr
     * @param string $delim
     * @return string
     * @throws InvalidArgumentException
     */
    private function handleInclude(string $argsStr, string $delim = ','): string
    {
        $args = MdPlusHelper::parseArgumentStr($argsStr, $delim);
        $file = $args[0]??false;
        if (!$file) {
            return '';
        }

        if (is_dir($file)) {
            $file = rtrim($file,'/').'/*';
        }

        $out = $this->includeFile($file, $args);

        if ($args['literal']??false) {
            $out = MdPlusHelper::shieldStr($out);
            $out = "<pre>$out</pre>";
        }
        return $out;
    } // handleInclude


    /**
     * Helper for handleInclude(): includes files of type md, txt and html
     * @param string $files
     * @param array $args
     * @return string
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function includeFile(string $files, array $args = []): string
    {
        $files = trim($files);
        if (str_starts_with($files, 'http')) {
            return $this->renderIframe($files);
        }

        $dir = $this->determineFilesToInclude($files, $args['exclude']??null);
        if (!$dir) {
            return '';
            //throw new Exception("Error: no files found for '$files'");
        }

        list($out, $i) = $this->doIncludeFiles($dir);

        // if multiple files, wrap each in a <section tag (unless option 'literal')
        if (!($args['literal']??false) && (sizeof($dir) > 1)) {
            $tag = $args['wrapperTag'] ?? 'section';
            $customClass = $args['class'] ?? '';
            $class = ' class="mdp-section-' . ($i + 1) . " $customClass\"";
            $str = <<<EOT


<$tag$class>
$out</$tag>


EOT;
        } else {
            if ($args['literal']??false) {
                $str = htmlentities($out);
            } else {
                $str = "\n\n$out\n\n";
            }
        }
        return $str;
    } // includeFile


    /**
     * Helper for includeFile()
     * @param string $files
     * @return string
     */
    private function renderIframe(string $files): string
    {
        $str = <<<EOT

<iframe src='$files' class="mdp-iframe"></iframe>

EOT;

        return $str;
    } // renderIframe


    /**
     * Helper for includeFile()
     * @param string $files
     * @param $exclude    regex pattern to identify what should be excluded
     * @return string[]
     */
    private function determineFilesToInclude(string $files, mixed $exclude = null): array
    {
        if (!str_contains($files, '/')) {
            $files = page()->root() . '/' . $files;
        }
        if (is_file($files)) {
            $dir = [$files];
        } else {
            $dir = MdPlusHelper::getDir($files, $exclude);
        }
        return $dir;
    } // determineFilesToInclude


    /**
     * Helper for includeFile()
     * @param array $dir
     * @return array
     * @throws InvalidArgumentException
     */
    private function doIncludeFiles(array $dir): array
    {
        $out = '';
        foreach ($dir as $i => $file) {
            $str = MdPlusHelper::loadFile($file, 'cstyle');
            if (!file_exists($file)) {
                throw new Exception("Error: file '$file' not found for including.");
            }
            $ext = MdPlusHelper::fileExt($file);
            if ($ext === 'txt') {
                $str = "<pre>$str</pre>";
                $out .= MdPlusHelper::shieldStr($str, 'block') . "\n";

            } elseif ($ext === 'html') {
                $str = MdPlusHelper::removeHtmlComments($str);
                list($p1, $p2) = MdPlusHelper::strPosMatching($str, 0, '<body', '</body>');
                $p1 = strpos($str, '>', $p1) + 1;
                $str = substr($str, $p1, ($p2 - $p1));
                $out .= MdPlusHelper::shieldStr($str, 'block') . "\n";
            } elseif ($ext === 'md') {
                $out .= MdPlusHelper::shieldStr($str, 'md') . "\n";
            } else {
                $out .= MdPlusHelper::shieldStr($str, 'block') . "\n";
            }
        }
        return array($out, $i);
    } // doIncludeFiles



    /**
     * Intercepts kirbytag patterns and sends them to Kirby.
     * Exceptions are 'link' and 'image', they are executed by corresponding macros
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function handleKirbyTags(string $str): string
    {
        if (preg_match_all('/( \( (date|email|file|gist|image|link|tel|twitter|video) : .*? \) )/xms', $str, $m)) {
            foreach ($m[1] as $i => $value) {
                // check whether it's part of a macro call, skip if so:
                $pat = '\{\{\s*[\w-]+'.str_replace('|', '\\|', preg_quote($value));
                if (preg_match("|$pat|", $str)) {
                    continue;
                }

                $value = strip_tags(str_replace("\n", ' ', $value));
                $res = false;

                // intercept '(link:' and process by link() macro:
                if (preg_match('/^ \(link: \s* ["\']? ([^\s"\']+) ["\']? (.*) \) /x', $value, $mm)) {
                    $args = "url:'$mm[1]' $mm[2]";
                    $res = $this->processByMacro('link', $args);

                // intercept '(image:' and process by img() macro:
                } elseif (preg_match('/^ \(image: \s* ["\']? ([^\s"\']+) ["\']? (.*) \) /x', $value, $mm)) {
                    $args = "src:'$mm[1]' $mm[2]";
                    $res = $this->processByMacro('img', $args);

                }
                if ($res === false) {
                    // (file: ~/assets/test.pdf text: Download File)
                    $res = kirby()->kirbytags($value);
                }
                $str = str_replace($m[0][$i], $res, $str);
            }
        }
        return $str;
    } // handleKirbyTags


    /**
     * Helper for handleKirbyTags(): calls "macro-" functions
     * @param string $macroName
     * @param string $argStr
     * @return mixed
     * @throws Exception
     */
    private function processByMacro(string $macroName, string $argStr): mixed
    {
        if (class_exists('PageFactory')) {
            throw new Exception("Unable to execute macro '$macroName', PageFactory not installed.");
        }
        // insert commas between arguments:
        if (!str_contains($argStr, ',')) {
            $argStr = preg_replace('/(\s\w+:)/', ",$1", $argStr);
        }
        $macroName = "PgFactory\\PageFactory\\$macroName";
        if (function_exists($macroName)) {
            $str = $macroName($argStr);
        } else {
            $str = false;
        }
        return $str;
    } // processByMacro


    /**
     * Extracts fields (or frontmatter elements): css, js, assets etc assigns them to page variables.
     * @param string $str
     * @return string
     */
    private function handleFrontmatter(string $str): string
    {
        while (preg_match('/^ \s* (\w+) : (.*?) \s* \n----\n (.*)/xms', $str, $m)) {
            $key = $m[1];
            $value = $m[2];
            if (str_contains($key, 'css')) {
                $value = $this->handleSectionRefs($value);
            }
            page()->$key()->value .= $value;
            $str = $m[3];
        }
        return $str;
    } // handleFrontmatter


    /**
     * Tries to import abbreviation definitions from file 'site/custom/variables/abbreviations.txt'
     * @return void
     */
    private function loadAbbreviations()
    {
        if (file_exists(ABBREVIATIONS_FILE)) {
            $lines = explode("\n", getFile(ABBREVIATIONS_FILE));
            foreach ($lines as $line) {
                if (!$line) {
                    continue;
                }
                list($key, $value) = explodeTrim(':', $line);
                self::$abbr[$key] = $value;
            }
        }
    } // loadAbbreviations


    /**
     * First, looks for abbreviation definitions in markdown.
     * Second, replaces all instances where content matches one the abbreviations.
     * @param string $str
     * @return string
     */
    private function handleAbbreviations(string $str): string
    {
        while (preg_match('/\*\[(.+?)]:\s*(.*?)\n/xms', $str, $m)) {
            $key = $m[1];
            $value = $m[2];
            self::$abbr[$key] = $value;

            $str = str_replace($m[0], '', $str);
        }

        // check whether abbreviations are available:
        if (self::$abbr) {
            // for all instances, inject HTML <abbr>:
            foreach (self::$abbr as $key => $value) {
                $value = "<abbr title='$value'>$key</abbr>";
                $str = preg_replace("/\b$key\b/m", $value, $str);
            }
        }

        return $str;
    } // handleAbbreviations


    /**
     * Helper for handleFrontmatter(): looks out for '#this' and '.this' and replaces this with 'mdp-section-N'
     * @param string $value
     * @return string
     */
    private function handleSectionRefs(string $value): string
    {
        if ($this->sectionIdentifier) {
            $value = str_replace(['#this','.this'],
                ["#$this->sectionIdentifier", ".$this->sectionIdentifier"], $value);
        }
        return $value;
    } // handleSectionRefs


    /**
     * If config option 'compileCodeBlocks' active, parses output, finds <code> elements and
     * applies md-compilation to the content -> permits to format code-blocks, e.g. highlight
     * @param string $str
     * @return string
     */
    private function _compileCodeBlocks(string $str): string
    {
        if ($this->compileCodeBlocks) {
            if (preg_match_all('|<code>(.*?)</code>|ms', $str, $m)) {
                foreach ($m[1] as $i => $value) {
                    $value = parent::parseParagraph($value);
                    $value = "<code>$value</code>";
                    $str = str_replace($m[0][$i], $value, $str);
                }
            }
        }
        return $str;
    } // _compileCodeBlocks

} // MarkdownPlus
