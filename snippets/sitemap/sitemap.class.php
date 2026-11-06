<?php
if (!defined('MODX_BASE_PATH')) {
    exit('-');
}

/**
 * Sitemap スニペットの文書の収集
 *
 * 文書・TV の有無・別名は最初に 1 回ずつ DB から読み、あとはメモリ上で処理する。
 * 文書ごとに DB へ問い合わせると、文書の多いサイトでは数万回になるため。
 */
class evoSiteMapSnippet
{
    /** @var array 出力してよいテンプレート（id => 名前） */
    public $templates = array();

    /** @var array TV 名 => 1|0（その TV があるか） */
    private $tvExists = array();

    /** @var array 'all'|'active' => [親の id => [子の行, ...]] */
    private $children = array();

    public function __construct($excludeTemplates = '')
    {
        $this->templates = $this->getTemplates($excludeTemplates);
    }

    /**
     * 除外するテンプレート（id か名前のカンマ区切り）を除いたテンプレートの一覧
     * 存在しないテンプレート（削除済みなど）の文書も出力しない
     */
    public function getTemplates($excludeTemplates)
    {
        global $modx;

        $templates = array();
        $rs = $modx->db->select('id, templatename', '[+prefix+]site_templates');
        while ($row = $modx->db->getRow($rs)) {
            $templates[$row['id']] = $row['templatename'];
        }

        foreach (explode(',', $excludeTemplates) as $template) {
            $template = trim($template);
            if ($template === '') {
                continue;
            }
            if (preg_match('@^[0-9]+$@', $template)) {
                unset($templates[$template]);
                continue;
            }
            $id = array_search($template, $templates, true);
            if ($id !== false) {
                unset($templates[$id]);
            }
        }
        return $templates;
    }

    /**
     * $startid の下の文書（孫以下も含む）を、文書 id をキーにして返す
     * 並びは、親のあとにその子孫が続く順（子は menuindex の順）
     */
    public function getDocs($startid, $priority, $changefreq, $excludeTV, $seeThruUnpub)
    {
        foreach (array($priority, $changefreq, $excludeTV) as $tvName) {
            $this->tvExists($tvName);
        }

        $docs = array();
        foreach ($this->getChildren($startid, $seeThruUnpub) as $doc) {
            $docid = (int)$doc['id'];
            $doc = $this->forgeDoc($doc, $priority, $changefreq, $excludeTV);

            $children = $this->getDocs($docid, $priority, $changefreq, $excludeTV, $seeThruUnpub);
            if ($children) {
                $childrenMax = 0;
                foreach ($children as $child) {
                    if ($childrenMax < $child['editedon']) {
                        $childrenMax = $child['editedon'];
                    }
                }
                if ($doc['editedon'] < $childrenMax) {
                    $doc['editedon'] = $childrenMax;
                }
            }
            // array_merge() は数値のキーを振り直すので使わない
            $docs[$docid] = $doc;
            $docs += $children;
        }
        return $docs;
    }

    /**
     * priority・changefreq・除外の TV の値を足す（URL は出力する文書だけ後で作る）
     */
    public function forgeDoc($doc, $priority, $changefreq, $excludeTV)
    {
        global $modx;

        $docid = (int)$doc['id'];
        $siteStart = (int)$modx->config['site_start'];
        if (!$doc['editedon']) {
            $doc['editedon'] = $doc['publishedon'];
        }

        $date_diff = round(($_SERVER['REQUEST_TIME'] - (int)$doc['editedon']) / 86400);

        if ($this->tvExists($priority))   $doc[$priority] = $this->getTV($docid, $priority);
        elseif ($docid === $siteStart)    $doc[$priority] = '1.0';
        elseif ($date_diff < 7)           $doc[$priority] = '0.9';
        elseif ($date_diff < 14)          $doc[$priority] = '0.8';
        elseif ($doc['parent'] == 0)      $doc[$priority] = '0.6';
        elseif ($doc['isfolder'])         $doc[$priority] = '0.4';
        elseif (1000 < $date_diff)        $doc[$priority] = $doc['hasImage'] ? '0.4' : '0.3';
        else                              $doc[$priority] = '0.5';

        if ($this->tvExists($changefreq)) $doc[$changefreq] = $this->getTV($docid, $changefreq);
        elseif ($docid === $siteStart)    $doc[$changefreq] = 'always';
        elseif ($doc['isfolder'])         $doc[$changefreq] = 'always';
        elseif (365 < $date_diff)         $doc[$changefreq] = 'never';
        elseif (180 < $date_diff)         $doc[$changefreq] = 'yearly';
        elseif (60 < $date_diff)          $doc[$changefreq] = 'monthly';
        elseif (14 < $date_diff)          $doc[$changefreq] = 'weekly';
        elseif ($date_diff)               $doc[$changefreq] = 'daily';
        else                              $doc[$changefreq] = 'never';

        $doc[$excludeTV] = $this->tvExists($excludeTV) ? $this->getTV($docid, $excludeTV) : false;

        return $doc;
    }

    /**
     * 文書の URL。トップページは site_url
     */
    public function getUrl($docid)
    {
        global $modx;

        static $preloaded = false;
        if (!$preloaded) {
            $this->preloadAliasListing();
            $preloaded = true;
        }

        if ($docid == $modx->config['site_start']) {
            return $modx->config['site_url'];
        }
        return trim((string)$modx->makeUrl($docid, '', '', 'full'));
    }

    /**
     * TV の値（@INHERIT なら親をたどる）
     */
    public function getTV($docid, $tv_name)
    {
        global $modx;

        $output = '';
        while ($pid = $modx->getDocument($docid, 'parent')) {
            $tv = $modx->getTemplateVar($tv_name, '*', $docid);
            if (($tv['value'] && substr($tv['value'], 0, 8) != '@INHERIT') || !$tv['value']) {
                $output = $tv['value'];
                break;
            }
            $output = trim(substr($tv['value'], 8));
            $docid = $pid['parent'];
        }
        return $output;
    }

    public function get_site_editedon()
    {
        global $modx;

        $where = 'privateweb=0 AND published=1 AND deleted=0';
        $rs = $modx->db->select('editedon', '[+prefix+]site_content', $where, 'editedon DESC', 1);
        return $modx->db->getValue($rs);
    }

    /**
     * 親の直下の文書（削除済み・Web ユーザー限定を除く。$seeThruUnpub でなければ非公開も除く）
     * 最初の呼び出しで全件を 1 回の問い合わせで読み、親ごとに分けて使い回す
     */
    private function getChildren($parent, $seeThruUnpub)
    {
        global $modx;

        $key = $seeThruUnpub ? 'all' : 'active';
        if (!isset($this->children[$key])) {
            $fields = "id,editedon,template,published,searchable,pagetitle,type,isfolder,parent,publishedon,content LIKE '%<img%' as hasImage";
            $where = 'deleted=0 AND privateweb=0';
            if (!$seeThruUnpub) {
                $where .= ' AND published=1';
            }
            $this->children[$key] = array();
            $rs = $modx->db->select($fields, '[+prefix+]site_content', $where, 'parent ASC, menuindex ASC');
            while ($row = $modx->db->getRow($rs)) {
                $this->children[$key][$row['parent']][] = $row;
            }
        }
        return isset($this->children[$key][$parent]) ? $this->children[$key][$parent] : array();
    }

    private function tvExists($tvName)
    {
        global $modx;

        if (!isset($this->tvExists[$tvName])) {
            $rs = $modx->db->select('name', '[+prefix+]site_tmplvars', sprintf("name='%s'", $modx->db->escape($tvName)));
            $this->tvExists[$tvName] = $modx->db->getRecordCount($rs) ? 1 : 0;
        }
        return $this->tvExists[$tvName];
    }

    /**
     * $modx->aliasListing（makeUrl() が使う別名の一覧）を、1 回の問い合わせで全件埋める
     *
     * makeUrl() は、一覧に無い文書の別名を親フォルダごとに DB へ問い合わせる。
     * 要素の形と path の作り方は DocumentParser::getAliasListing() と同じ。
     * すでに入っている文書は上書きしない。getAliasListing() の無い本体では何もしない
     */
    private function preloadAliasListing()
    {
        global $modx;

        if (!method_exists($modx, 'getAliasListing') || !isset($modx->aliasListing) || !is_array($modx->aliasListing)) {
            return;
        }

        $rows = array();
        $rs = $modx->db->select('id,alias,isfolder,parent', '[+prefix+]site_content');
        while ($row = $modx->db->getRow($rs)) {
            $rows[(int)$row['id']] = $row;
        }
        $useAliasPath = $modx->config['use_alias_path'] == '1';

        $listing = array();
        foreach (array_keys($rows) as $id) {
            // path の決まっていない祖先を集め、根の方から順に決める
            $chain = array();
            $cur = $id;
            while (isset($rows[$cur]) && !isset($listing[$cur]) && count($chain) < 100) {
                $chain[] = $cur;
                $cur = (int)$rows[$cur]['parent'];
            }
            foreach (array_reverse($chain) as $docid) {
                $row = $rows[$docid];
                $parent = (int)$row['parent'];
                $path = '';
                if (0 < $parent && $useAliasPath) {
                    if (!isset($listing[$parent])) {
                        continue 2; // 親が無い・循環している文書は埋めずに本体に任せる
                    }
                    $p = $listing[$parent];
                    $path = (0 < $p['parent'] && $p['path'] != '') ? $p['path'] . '/' . $p['alias'] : $p['alias'];
                }
                $listing[$docid] = array(
                    'id'       => $docid,
                    'alias'    => $row['alias'] == '' ? $docid : $row['alias'],
                    'parent'   => $parent,
                    'isfolder' => (int)$row['isfolder'],
                    'path'     => $path,
                );
            }
        }
        foreach ($listing as $docid => $info) {
            if (!isset($modx->aliasListing[$docid])) {
                $modx->aliasListing[$docid] = $info;
            }
        }
    }
}
