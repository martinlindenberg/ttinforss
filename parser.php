<?php
/**
 * Parserklasse
 */
class Parser {

    protected $_config;
    protected $_arguments;

    protected function _parseConfig(){
        $data = $this->_getFileData(dirname(__FILE__).'/config.json');
        $this->_config = json_decode($data);
    }

    public function __construct($arguments){
        $this->_arguments = $arguments;
    }

    protected function _getLeagueIds(){
        $result = array();
        foreach ($this->_config->leagues as $league) {
            $result[$league->id] = $league->name;
        }

        return $result;
    }

    protected function _getUrl(){
        return $this->_config->url->domain;
    }

    protected function _getPlaceholder(){
        return $this->_config->url->placeholder;
    }

    protected function _getFileNames($leagues, $url){
//        $fileNames = array ('1352' => dirname(__FILE__) . '/data.html',);

        foreach ($leagues as $id => $name) {
            $fileNames[$id] = str_replace($this->_getPlaceholder(), $id, $url);
        }

        return $fileNames;
    }

    protected function _getFilterKeys () {
        return $this->_config->keywords;
    }

    protected function _getTtl () {
        return $this->_config->ttl;
    }

    protected function _isStartFromToday () {
        return $this->_config->startFromToday;
    }

    protected function _getOrderBy () {
        return $this->_config->orderByKey;
    }
    
    public function run () {
        $this->_parseConfig();

        $rss = $this->getValidCache();
        if ($rss) {
            return $this->showRss($rss);
        }

        $leagues = $this->_getLeagueIds();
        $url = $this->_getUrl();
        $fileNames = $this->_getFileNames($leagues, $url);

        $fullLeagues = array();
        $cacheable = true;
        foreach ($fileNames as $leagueId => $fileName) {
            if (array_key_exists(1, $this->_arguments) && $this->_arguments[1] == '--verbose') {
                echo "\n" . 'parse ' . $leagues[$leagueId] ."...\n";                
            }

            try {
                $fullLeagues[$leagueId] = $this->parseFile($fileName, $leagueId);    
            } catch (Exception $error) {
                $cacheable = false;
            }
        }

        $myLeagues = $this->filterTeams($fullLeagues, $this->_getFilterKeys());
        $myLeagues = $this->filterStartFromToday($myLeagues, $this->_isStartFromToday());
        $myLeagues = $this->orderBy($myLeagues, $this->_getOrderBy());
        $rss = $this->_export($myLeagues);

        if ($cacheable) {
            $this->_cacheResults($rss, $this->_getTtl());
        }

        $this->showRss($rss);
    }

    protected function showRss($rss){
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $rss;
    }

    protected function getValidCache(){
        if (!file_exists($this->_getCacheFile())) {
            return false;
        } else {
            try {
                $data = file_get_contents($this->_getCacheFile());
                $data = json_decode($data);

                if (isset ($data->ttl) && isset($data->rss) && $data->ttl > time()) {
                    return $data->rss . '<!-- cached result -->';
                }
                return false;
            } catch (Exception $e) {
                return false;
            }
        }
    }

    protected function _getCacheFile(){
        return dirname(__FILE__) . '/cache.json';
    }

    protected function _cacheResults($data, $ttl){
        file_put_contents(
            $this->_getCacheFile(), 
            json_encode(
                array(
                    'ttl' => time() + $ttl,
                    'rss' => $data,
                )
            )
        );
    }

    protected function _export($data) {
        $result = '<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0">
    <channel>
        <title>RSS TT-MOL Spieltage</title>
        <description>HTML-Parser Daten</description>
        <link></link>
        <lastBuildDate>' . date('r') . '</lastBuildDate>
        <pubDate>' . date('r') . '</pubDate>
        <ttl>3600</ttl>        
';
        $leagues = $this->_getLeagueIds();

        foreach ($data as $key => $game) {
            $leagueId = $game['leagueId'];
            $leagueLink = str_replace($this->_getPlaceholder(), $leagueId, $this->_getUrl());

            try {
                if ($game['date'] == 'verlegt') {
                    $gameDate = $game['date'];
                } else {
                    $gameDate = new DateTime($game['date']);    
                }
            } catch (Exception $e) {
                echo '<pre>\$e = ' . print_r($e, 1) . '</pre>';
                echo '<pre>\$game = ' . print_r($game, 1) . '</pre>';
                die();
            }
            
            $description = 'Punktspiel in der ' . $leagues[$leagueId];
            if (!$gameDate instanceof DateTime) {
                $description .= ' VERLEGT';
            } else {
                $description .= ' am ' . $gameDate->format('d.m.Y');
            }

            if ($game['result'] != '') {
                $description .= ' (Ergebnis: ' . $game['result'] .')';
            }

            $result .= '
        <item>
            <title>' . ($this->_isHomeMatch($game) ? 'Heimspiel: ' : 'Auswärts: ') . $game['teams'][0] . ' - ' . $game['teams'][1] . '</title>
            <description>' . $description . '</description>
            <link>' . $leagueLink . '</link>
            <guid isPermaLink="true">' . $key . '</guid>
            <pubDate>' . ($gameDate instanceof DateTime ? $gameDate->format('r') : '') . '</pubDate>
        </item>                    
            ';                    
        }

        $result .= '    
    </channel>
</rss>        
        ';

        return $result;
    }

    protected function _isHomeMatch ($game) {
        $teamNames = $this->_getFilterKeys();
        $checkTeamName = strtolower($game['teams'][0]);
        foreach ($teamNames as $teamName) {
            $checkKey = strtolower($teamName);
            if (strpos($checkTeamName, $checkKey) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * strukturiert die liste anders: statt liga => [games] wird nur games => [] 
     */
    public function filterTeams($fullLeagues, $teamNames){
        $result = array();
        foreach ($fullLeagues as $leagueId => $games) {
            foreach ($games as $playDate) {
                $t0 = strtolower($playDate['teams'][0]);
                $t1 = strtolower($playDate['teams'][1]);
                $key = md5($t0 . $t1 . $playDate['date'] . $leagueId);

                foreach ($teamNames as $teamName) {
                    $teamCheck = strtolower($teamName);
                    if (strpos($t0, $teamCheck) > -1 || strpos($t1, $teamCheck) > -1) {
                        $result[$key] = $playDate;
                    }
                }
            }
        }

        return $result;
    }

    public function filterStartFromToday($fullLeagues, $isStartFromToday){
        if (!$isStartFromToday) {
            return $fullLeagues;
        }

        $result = array();
        foreach ($fullLeagues as $key => $game) {
            if ($game['date'] == 'verlegt') {
                $result[$key] = $game;
            } else {
                $playDate = new DateTime($game['date']);    
                $today = new DateTime(date('Y-m-d 00:00:00'));

                if ($playDate->format('U') > $today->format('U')) {
                    $result[$key] = $game;
                }                
            }
        }

        return $result;
    }

    public function orderBy($fullLeagues, $orderByKey){
        if ($orderByKey == 'none') {
            return $fullLeagues;
        }

        $result = array();
        $sort = array();
        foreach ($fullLeagues as $hash => $game) {
            $sortKey = $game[$orderByKey];
            $sort[$sortKey][$hash] = $game;
            ksort($sort);
        }

        foreach ($sort as $sortKey => $items) {
            foreach ($items as $hash => $item) {
                $result[$hash] = $item;
            }
        }

        return $result;
    }

    public function parseFile ($fileName, $leagueId) {
        $data = $this->_getFileData($fileName);
        $singleGames = $this->_getSingleGames($data);

        $games = array();
        foreach ($singleGames as $gameHtml) {
            $date = $this->_getDate($gameHtml);
            $teams = $this->_getTeams($gameHtml);
            $result = $this->_getResult($gameHtml);

            $games[] = array(
                'date' => $date,
                'teams' => $teams,
                'result' => $result,
                'leagueId' => $leagueId,
            );
        }

        return $games;
    }

    protected function _getFileData ($fileName) {
        return file_get_contents($fileName);
    }   

    protected function _getSingleGames ($html) {
        $gameBlocks = explode('<tr class="tth3">', $html); 
        unset($gameBlocks[0]);

        return $gameBlocks;
    }

    protected function _getDate ($gameHtml) {
        $date = explode('<a title="Spieldatum &auml;ndern"', $gameHtml);

        $date = explode('</a></td>', $date[1]);
        $date = explode('&nbsp;', $date[0]);
        if (count($date) != 2) {
            return 'verlegt';
        }

        $date = trim($date[1]);
        $parts = explode('.', $date);
        if (count($parts) != 3) {
            return 'verlegt';
        } 

        $year = (2000 + $parts[2]);
        $month = $parts[1];
        $day = $parts[0];
        return $year . '-' . $month . '-' . $day;
    }

    protected function _getTeams ($gameHtml) {
        $teams = explode('<a title="Einzelspielbericht', $gameHtml);
        $teams = explode('</a></td>', $teams[1]);
        $teams = explode('">', $teams[0]);
        $teams = trim(utf8_encode($teams[1]));
        return explode(' - ', $teams);
    }

    protected function _getResult($gameHtml) {
        $teams = explode('<a title="Einzelspielbericht', $gameHtml);
        $teams = explode('</a></td>', $teams[1]);
        $result = explode('<img src="', $teams[1]);
        $result = trim(str_replace('<td>', '', $result[0]));
        return trim($result);
    }
}

$parser = new Parser($argv);
$parser->run();