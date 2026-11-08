<?php
if (!defined('MODX_BASE_PATH')) {
    exit('-');
}
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');
/**
 * Sitemap
 *
 * Outputs a machine readable site map for search engines and robots.
 *
 * @category snippet
 * @version 1.2.1 (2026-11-08)
 * @license LGPL
 * @author Grzegorz Adamiak [grad], ncrossland, DivanDesign (http://www.DivanDesign.biz), yama
 * @internal @modx_category Navigation
 *
 * @param startid {integer} - Id of the 'root' document from which the sitemap starts. Default: 0.
 * @param format {string} - Which format of sitemap to use: sp (Sitemap Protocol used by Google), txt (text file with list of URLs), ulli (HTML list). Default: sp.
 * @param seeThruUnpub {0; 1} - See through unpublished documents. Default: 1.
 * @param priority {string} - Name of TV which sets the relative priority of the document. If there is no such TV, this parameter will not be used. Default: 'sitemap_priority'.
 * @param changefreq {string} - Name of TV which sets the change frequency. If there is no such TV this parameter will not be used. Default: 'sitemap_changefreq'.
 * @param excludeTemplates {comma separated string} - Documents based on which templates should not be included in the sitemap. Comma separated list of template names or ids. Default: ''.
 * @param excludeTV {string} - Name of TV (boolean type) which sets document exclusion form sitemap. If there is no such TV this parameter will not be used. Default: 'sitemap_exclude'.
 * @param xsl {string; integer} - URL to the XSL style sheet or doc ID of the XSL style sheet. Default: ''.
 * @param excludeWeblinks {0; 1} - Should weblinks be excluded? You may not want to include links to external sites in your sitemap, and Google gives warnings about multiple redirects to pages within your site. Default: 1.
 */

/*
Supports the following formats:

- Sitemap Protocol used by Google Sitemaps
  (http://www.google.com/webmasters/sitemaps/)

- URL list in text format
  (e.g. Yahoo! submission)
*/

include_once __DIR__ . '/sitemap.class.php';

/* Parameters */
if (!isset($startid))          $startid = 0;
if (!isset($priority))         $priority = 'sitemap_priority';
if (!isset($changefreq))       $changefreq = 'sitemap_changefreq';
if (!isset($excludeTemplates)) $excludeTemplates = '';
if (!isset($excludeTV))        $excludeTV = 'sitemap_exclude';
if (!isset($xsl))              $xsl = '';
if (!isset($excludeWeblinks))  $excludeWeblinks = 1;

$seeThruUnpub = (isset($seeThruUnpub) && $seeThruUnpub == '0') ? false : true;
$format       = (isset($format) && ($format != 'ror')) ? $format : 'sp';
if (is_numeric($xsl)) $xsl = $modx->makeUrl($xsl);
/* End parameters */

$smap = new evoSiteMapSnippet($excludeTemplates);
$docs = $smap->getDocs($startid, $priority, $changefreq, $excludeTV, $seeThruUnpub);

// filter out documents which shouldn't be included
$_ = array();
foreach ($docs as $docid => $doc) {
    if (!isset($smap->templates[$doc['template']]))                 continue;
    if (!empty($doc[$excludeTV]))                                   continue;
    if (isset($doc[$changefreq]) && $doc[$changefreq] == 'exclude') continue;
    if (!$doc['published'])                                         continue;
    if (!$doc['template'])                                          continue;
    if (!$doc['searchable'])                                        continue;
    if ($excludeWeblinks && $doc['type'] == 'reference')            continue;
    if ($docid == $modx->documentIdentifier)                        continue;

    // URL は出力する文書の分だけ作る（除外する文書の分まで makeUrl() を呼ばない）
    $doc['url'] = $smap->getUrl($docid);
    if ($doc['url'] === '')                                         continue;

    $_[$docid] = $doc;
}
$docs = $_;
unset($_);

$site_editedon = $smap->get_site_editedon();
if ($site_editedon && isset($docs[$modx->config['site_start']])) {
    $docs[$modx->config['site_start']]['editedon'] = $site_editedon;
}

// build sitemap in specified format
// ---------------------------------------------

$output = array();
switch ($format) {
    case 'ulli': // UL List
        $output[] = '<ul class="sitemap">';
        foreach ($docs as $doc) {
            $output[] = '<li class="sitemap"><a href="' . $doc['url'] . '" class="sitemap">' . $doc['pagetitle'] . '</a></li>';
        }
        $output[] = '</ul>';
        break;

    case 'txt': // plain text list of URLs
        foreach ($docs as $doc) {
            $output[] = $doc['url'];
        }
        break;

    case 'ror': // TODO
    default: // Sitemap Protocol
        $output[] = '<?xml version="1.0" encoding="' . $modx->config['modx_charset'] . '"?>';
        if ($xsl) $output[] = '<?xml-stylesheet type="text/xsl" href="' . $xsl . '"?>';

        $output[] = '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // XML の実体参照だけを使う（htmlentities() は &eacute; など XML に無い実体参照を作る）
        $charset = $modx->config['modx_charset'] ?: 'UTF-8';
        $esc = function ($value) use ($charset) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, $charset);
        };
        foreach ($docs as $doc) {
            $output[] = '    <url>';
            $output[] = '        <loc>' . $esc($doc['url']) . '</loc>';
            if ($doc['editedon'])
                $output[] = '        <lastmod>' . date('Y-m-d', $doc['editedon']) . '</lastmod>';
            // 空の priority・changefreq はサイトマップの規格に合わないので書かない
            if ((string)$doc[$priority] !== '')
                $output[] = '        <priority>' . $esc($doc[$priority]) . '</priority>';
            if ((string)$doc[$changefreq] !== '')
                $output[] = '        <changefreq>' . $esc($doc[$changefreq]) . '</changefreq>';
            $output[] = '    </url>';
        }

        $output[] = '</urlset>';
}

return implode("\n", $output);
