<?php

class BetsController extends AppController {
	var $name = 'Bets';
	var $uses = array('LeagueType', 'Odd', 'Score', 'SourceType', 'UserBet', 'Tag', 'UserBetsTag', 'User');
	var $components = array('RequestHandler');

	public function beforeFilter() {
		$this->Auth->allow('view');
		parent::beforeFilter();
	}

	public function index() {
	}
	
	public function v($id = null) {
		if (empty($id)) {
			$this->Session->setFlash('Invalid id');
		}
		$this->UserBet->id = $id;
		$scoreid = $this->UserBet->read('scoreid');
		$this->Score->id = $id;
		$score = $this->Score->read();
		$this->set('score', $score);
	}

	public function modify() {
		$form = $this->params['form'];
		$ids = array_keys($this->params['form']['tag']);

		if (!empty($form['Delete'])) {
			$this->deleteMass($ids);
		} else if (!empty($form['Tag'])) {
			$this->tag($ids);
		}
		
		$this->redirect($this->referer());
	}

	private function deleteMass($ids) {
		$success = 0;

		foreach ($ids as $id) {
			$this->UserBet->id = $id;
			if ($this->UserBet->delete()) {
				$success++;
			}
		}

		if ($success > 0) {
			$this->Session->setFlash("Deleted $success bet(s)");
		} else {
			$this->Session->setFlash("No bets removed");
		}
	}

	public function shortlink() {
		$url = $this->urlGetVar('shorturl', false);
		$this->set('url', $url);
		if (!empty($url)) {
			App::import('vendor', 'google_short_link');
			$GoogleShortLink = new GoogleShortLink(
				'api',
				Configure::read('google.shortlink.hostname'),
				Configure::read('google.shortlink.secret')
			);
			$shorturl = $GoogleShortLink->createHashedShortLink($url);
			$this->set('shorturl', $shorturl);
		}
	}

	private function tag($ids) {
		if (empty($ids)) {
			return false;
		}
		
		$tagname = trim($this->params['form']['tagvalue']);

		// None is a reserved tag name
		if (strtolower($tagname) == 'none') {
			$this->Session->setFlash("Unable to give tag name of None");
		}
				
		if ($this->Tag->saveBetsWithTag($tagname, $ids)) {
			$this->Session->setFlash("Saved $tagname");
		}
	}

	public function delete($id = null) {
		if (empty($id)) {
			$this->Session->setFlash('Invalid id');
		}
		$this->delete(array($id));
		$this->redirect($this->referer());
	}

	private function superbar($params, $date) {
		$text = $params['text'];
		$text = strtolower(" $text "); //give us some working room
		$match = array();
		if (preg_match('%((0?[1-9]|1[012])(:[0-5]\d){0,2}\s*([AP]M|[ap]m))%', $text, $match)) {
			$text = str_replace($match[0], '', $text);
		}

		$options = array();
		if (preg_match('@[0-9]+/[0-9]+/[0-9]+@', $text, $match)) {
			$mstr = $match[0];
			$strdate = strtotime($mstr);
			if ($strdate > strtotime('2010-01-01')) {
				$text = str_replace($mstr, '', $text);
				$options['game_date'] = date('Y-m-d', $strdate);
			}
		}
		if (!empty($params['startdate']) && !empty($params['enddate'])) {
			$options['game_date'] = array(
			    date('Y-m-d 00:00:00', strtotime($params['startdate'])),
			    date('Y-m-d 23:59:59', strtotime($params['enddate']))
			);
		}
		if (!isset($options['game_date'])) {
			$options['close_date'] = $date;
		}
		if (strpos($text, ' v ') !== false) {
			$teams = explode('v', $text);
			$options['home'] = trim($teams[0]);
			$options['visitor'] = trim($teams[1]);
			$text = '';
		} else if (strpos($text, ' @ ') !== false) {
			$teams = explode('@', $text);
			$options['home'] = trim($teams[1]);
			$options['visitor'] = trim($teams[0]);
			$text = '';
		} else if (($lid = $this->LeagueType->contains($text)) !== false) {
			$options['league'] = $lid;
			$text = '';
		}
		$text = trim($text);
		if (!empty($text)) {
			if (($rawt = strtotime($text)) > strtotime('2010-01-01')) {
				$options['game_date'] = date('Y-m-d', $rawt);
			} else {
				$options['name'] = $text;
			}
		}		
			
		$scores = $this->Score->matchOption($options);
		$this->set('scores', $scores);
	}

	private function getbet($params) {
		//$this->Score->query("SET time_zone = 'US/Central';");
		if (!empty($params['scoreid'])) {
			$score = $this->Score->findById($params['scoreid']);
			$score = $score['Score'];
			$bet = array(
				'scoreid' => $score['id'],
				'home' => $score['home'],
				'visitor' => $score['visitor'],
				'league' => $score['league'],
				'game_date' => $score['game_date'],
				'type' => 'spread'
			);
			$odds = $this->Odd->latest($params['scoreid']);
			$bet['odds'] = array();
			foreach ($odds as $odd) {
				$odd = $odd['Odd'];
				$bet['odds'][] = $odd;
			}
		}
		$this->set('bet', $bet);
	}

	public function createbets() {
		$form = $this->params['form'];
		$form = $form + array('parlay' => array(), 'direction' => array(), 'spread' => array());
		$bets = array();
		foreach (array_keys($form['type']) as $iden) {
			list($dbkey, $num) = explode('_', $iden);
			if (!isset($form['direction'][$iden])) {
				$form['direction'][$iden] = null;
			}
			if (!isset($form['spread'][$iden])) {
				$form['spread'][$iden] = null;
			}
			if (!isset($form['parlay'][$iden])) {
				$form['parlay'][$iden] = null;
			}
			$bet = array(
				'type' => $form['type'][$iden],
				'direction' => $form['direction'][$iden],
				'spread' => $form['spread'][$iden],
				'risk' => $form['risk'][$iden],
				'odds' => $form['odds'][$iden],
				'key' => $dbkey,
				'scoreid' => str_replace('SS', '', $dbkey),
				'book' => $form['book'][$iden],
				'date_std' => isset($form['date_std'][$iden]) ? $form['date_std'][$iden] : null,
				'parlay' => $this->parseParlay($form['parlay'][$iden]),
				'tag' => $form['tag'][$iden]
			);
			if ($bet['type'] == 'parlay' || $bet['type'] == 'teaser') {
				$date_std = 0;
				foreach ($bet['parlay'] as $p) {
					$date_std = max($date_std, strtotime($p['date_std']));
				}
				$bet['date_std'] = gmdate('Y-m-d H:i:s', $date_std);
				$bet['scoreid'] = null;
				$userid = $this->Auth->user('id');
				$this->UserBet->persist($userid, $bet);
				$saveParlays = array();
				foreach ($bet['parlay'] as $iden => $p) {
					list($dbkey, $num) = explode('_', $iden);
					$p['parlayid'] = $bet['id'];
					$p['scoreid'] = str_replace('SS', '', $dbkey);
					$p['pt'] = $bet['type'];
					$saveParlays[] = $p;
				}
				$this->saveBets($saveParlays);
			} else {
				$bets[] = $bet;
			}
		}
		list($saveBets, $unsavedBets) = $this->saveBets($bets);
		
		$this->set('savedBets', $saveBets);
		$this->set('unsavedBets', $unsavedBets);
		
		// Flash redirect
		App::import('Helper', 'Html');
		$html = new HtmlHelper();
		$link = $html->link('View your bets', '/bets/view#Bets');
		$this->Session->setFlash("Bet(s) entered successfully. $link");
		$this->redirect('/bets/');
	}

	private function saveBets($bets) {
		$saved = $notSaved = array();
		$userid = $this->Auth->user('id');
		
		foreach ($bets as $bet) {
			if ($this->UserBet->persist($userid, $bet)) {
				$saved[] = $bet;				
			} else {
				$notSaved[] = $bet;
			}
		}
		return array($saved, $notSaved);
	}

	private function parseParlay($parlays) {
		if (empty($parlays)) {
			return false;
		}
		$out = array();
		foreach ($parlays as $key => $game) {
			$gameinfo = explode(';', $game);
			$i = array();
			foreach ($gameinfo as $row) {
				list($k, $v) = explode('=', $row);
				$i[$k] = $v;
			}
			$out[$key] = $i;
		}
		return $out;
	}

	private function getStartEnd($params) {
		$startdate = $params['startdate'];
		$enddate = $params['enddate'];
		$rawStart = strtotime($startdate);
		$rawEnd = strtotime($enddate);
		if ($rawStart === false || $rawStart < strtotime('2008-01-01')) {
			$startdate = date('Y-m-d');
			$rawStart = strtotime($startdate);
		}
		if ($rawEnd === false || $rawEnd < $rawStart) {
			$enddate = date('Y-m-d', $rawStart);
		}
		$enddate = date('Y-m-d 23:59:59', strtotime($enddate));
		return array($startdate, $enddate);
	}

	private function accorselect($params) {
		list($startdate, $enddate) = $this->getStartEnd($params);
		$leagues = $this->Score->findScoresBetweenDates($startdate, $enddate);
		$this->set('leagues', $leagues);
		$this->set('startdate', date('n/j/Y', strtotime($startdate)));
		$this->set('enddate', date('n/j/Y', strtotime($enddate)));
	}

	public function ajax($action = '') {
		$params = $this->params['url'];
		$date = date('Y-m-d H:i:s'); //today for right now

		switch ($action) {
		case 'superbar':
			$this->superbar($params, $date);
			break;
		case 'getbet':
			$this->getbet($params);
			break;
		case 'accorselect':
			$this->accorselect($params);
			break;
		}
		$this->render("ajax_$action");
	}

	private function winLossTie(&$bets) {
		$record = array(
		    'win' => 0,
		    'loss' => 0,
		    'tie' => 0,
		    'dollarsWon' => 0
		);
		foreach ($bets as $bet) {
			$winning = $bet['winning'];
			if (!is_null($winning)) {
				if ($winning == 0) {
					$record['tie']++;
				} else if ($winning > 0) {
					$record['win']++;
				} else {
					$record['loss']++;
				}
				$record['dollarsWon'] += $winning;
			}
		}
		$record['winningPercentage'] = safe_div($record['win'], ($record['win']+$record['loss']+$record['tie']));
		return $record;
	}

	private function allStats(&$bets) {
		$allStats = array(
		    'earned' => 0,
		    'num' => 0,
		    'bet' => 0,
		    'odds' => 0
		);
		foreach ($bets as $bet) {
			$winning = $bet['winning'];
			if (!is_null($winning)) {
				$allStats['num']++;
				$allStats['earned'] += $winning;
				$allStats['bet'] += $bet['risk'];

				$odds = $bet['odds'];
				if ($odds > 0) {
					$allStats['odds'] += ($odds-100);
				} else {
					$allStats['odds'] += ($odds+100);
				}
			}
		}
		
		$odds = safe_div($allStats['odds'], $allStats['num']);
		$avgOdds = ($odds > 0) ? $odds + 100 : $odds - 100;
		$allStats['avgOdds'] = $avgOdds;
		$allStats['breakEven'] = ($avgOdds > 0) ? (100/(100+$avgOdds)) : (-$avgOdds/(-$avgOdds + 100));
		$allStats['avgEarned'] = safe_div($allStats['earned'], $allStats['num']);
		$allStats['avgBet'] = safe_div($allStats['bet'], $allStats['num']);
		$allStats['roi'] = safe_div($allStats['avgEarned'], $allStats['avgBet']);

		return $allStats;
	}

	private function minNull($left, $right) {
		if (is_null($left)) {
			return $right;
		}
		if (is_null($right)) {
			return $left;
		}
		return min($left, $right);
	}

	private function graphData($bets) {
		$earnedData = array();
		$dateTimeMin = null;
		foreach ($bets as $bet) {
			$winning = $bet['winning'];
			$dateTime = strtotime($bet['date']);
			$dateTimeMin = $this->minNull($dateTimeMin, $dateTime);
			
			$date = date('Y-m-d', $dateTime);
			if (!is_null($winning)) {
				if (!isset($earnedData[$date])) {
					$earnedData[$date] = 0;
				}
				$earnedData[$date] += $winning;
			}
		}
		$earned = 0;
		$graphData = array();
		if (!empty($earnedData)) {
			$graphData[] = array(strtotime(date('Y-m-d', $dateTimeMin)." -1 day")*1000, 0);
			ksort($earnedData);
			foreach ($earnedData as $Ymd => $earnings) {
				$earned += $earnings;
				$graphData[] = array(strtotime($Ymd)*1000, $earned);
			}
		}
		return array($graphData);
	}
	
	private function _sortDate($betl, $betr) {
		$l = strtotime($betl['date']);
		$r = strtotime($betr['date']);
		if ($l == $r) {
			return 0;
		} else {
			return $l > $r ? 1 : -1;
		}
	}

	private function fixCond($cond) {
		$ret = array();
		$fixedCond = array();
		$possibleTypes = $this->getBetTypes();
		$retAnd = array();
		foreach ($cond as $key => $val) {
			if ($val !== false) {
				switch ($key) {
				case 'league':
					$vals = explode(',', $val);
					$set = array();
					foreach ($vals as &$val) {
						if (strtolower($val) == 'mixed') {
							$fixedCond[$key][] = $val;
						} else {
							$sqlval = $this->LeagueType->contains($val);
							if ($sqlval !== false) {
								if (!isset($fixedCond[$key])) {
									$fixedCond[$key] = array();
								}
								$fixedCond[$key][] = $val;
								$set[] = $sqlval;
							}
						}
					}
					if (!empty($set)) {
						$retAnd[] = array('or' => array(
						    array($key => $set),
						    array($key => null)
						));
					}
					break;
				case 'book':
					$vals = explode(',', $val);
					$set = array();
					$bookAnd = array();
					foreach ($vals as &$val) {
						if (strtolower($val) == 'none') {
							$fixedCond[$key][] = $val;
							$bookAnd[] = array('UserBet.sourceid' => null);
						}

						$sqlval = $this->SourceType->contains($val);
						if ($sqlval !== false) {
							if (!isset($fixedCond[$key])) {
								$fixedCond[$key] = array();
							}
							$fixedCond[$key][] = $val;
							$set[] = $sqlval;
						}
					}
					if (!empty($set)) {
						$bookAnd[] = array('UserBet.sourceid' => $set);
					}
					if (!empty($bookAnd)) {
						$retAnd[] = array('or' => $bookAnd);
					}
					break;
				case 'type':
					$vals = explode(',', $val);
					$set = array();
					$fixedCond[$key] = array();
					foreach ($vals as &$val) {
						if (isset($possibleTypes[$val])) {
							$fixedCond[$key][] = $val;
							$set[] = $val;
						}
					}
					if (!empty($set)) {
						$ret[$key] = $set;
					}
					break;
				case 'beton':
				case 'tag':
					$vals = explode(',', $val);
					if (!empty($vals)) {
						$fixedCond[$key] = $vals;
					}
					break;
				case 'game_date':
					$vals = explode(',', $val);
					$sqlkey = 'UserBet.game_date';
					if (!empty($vals) && count($vals) == 2) {
						list($gte, $lte) = $vals;
						$fixVals = array();
						if (!numberSafeEmpty($gte)) {
							$ret[$sqlkey.' >='] = date('Y-m-d', strtotime($gte));
						}
						$fixVals['gte'] = $gte;
						if (!numberSafeEmpty($lte)) {
							$ret[$sqlkey.' <='] = date('Y-m-d 23:59:59', strtotime($lte));
						}
						$fixVals['lte'] = $lte;
						if (!empty($fixVals)) {
							$fixedCond[$key] = $fixVals;
						}
					}
					break;
				case 'spread':
				case 'odds':
				case 'risk':
					$vals = explode(',', $val);					
					if (!empty($vals) && count($vals) == 2) {
						list($gte, $lte) = $vals;
						$fixVals = array();
						if (!numberSafeEmpty($gte)) {
							$ret[$key.' >='] = $gte;
						}
						$fixVals['gte'] = $gte;
						if (!numberSafeEmpty($lte)) {
							$ret[$key.' <='] = $lte;
						}
						$fixVals['lte'] = $lte;
						if (!empty($fixVals)) {
							$fixedCond[$key] = $fixVals;
						}
					}					
					break;
				case 'winning':
					$vals = explode(',', $val);
					if (!empty($vals) && count($vals) == 2) {
						list($gte, $lte) = $vals;
						$fixVals = array();
						$fixVals['gte'] = $gte;
						$fixVals['lte'] = $lte;
						$fixedCond[$key] = $fixVals;
					}
					break;
				default:
					$vals = explode(',', $val);					
					if (!empty($vals)) {
						$fixedCond[$key] = $vals;
						$ret[$key] = $vals;
					}
				}
			}
		}
		if (!empty($retAnd)) {
			$ret['and'] = $retAnd;
		}
		return array($ret, $fixedCond);
	}

	private function getBetTypes() {
		return $this->UserBet->possibleTypes();
	}

	private function setFilters(&$bets, $distinct, $range) {
		$betTypes = $this->getBetTypes();
		$distincts = array();
		foreach ($bets as $bet) {
			foreach ($distinct as $key) {
				if (!isset($distincts[$key])) {
					$distincts[$key] = array();
				}
				if (!empty($bet[$key])) {
					if ($key == 'tag') {
						foreach (explode(',', $bet[$key]) as $v) {
							$distincts[$key][trim($v)] = $v;
						}
					} else {
						$v = $bet[$key];
						if ($key == 'type') {
							$v = $betTypes[$v];
						}
						$distincts[$key][$bet[$key]] = $v;
					}
				}
			}
		}

		$ret = array();
		foreach ($distinct as $key) {
			if (isset($distincts[$key])) {
				if (in_array($key, array('book', 'tag'))) {
					$distincts[$key]['none'] = 'None';
				}
				$list = $distincts[$key];
				ksort($list);
				$ret[$key] = array('list' => $list);
			}
		}
		foreach ($range as $key) {
			if (isset($ret[$key])) {
				$ret[$key]['range'] = true;
			} else {
				$ret[$key] = array('range' => true);
			}
		}
		
		return $ret;
	}

	private function getCondAsMap($cond) {
		$ret = array();
		foreach ($cond as $key => $rows) {
			// If is array and array of values, not hash map. Then convert to a
			// key value map where all labels are true
			if (is_array($rows) && isset($rows[0])) {
				$ret[$key] = array_combine(array_values($rows), array_fill(0, count($rows), true));
			} else {
				$ret[$key] = $rows;
			}
		}
		return $ret;
	}

	private function reformatBets(&$bets) {
		$ret = array();
		foreach ($bets as $bet) {
			$ret[] = $this->reformatBet($bet);
		}
		return $ret;
	}

	private function getBetOn($userBet, $score) {
		switch ($userBet['type']) {
		case 'moneyline':
		case 'half_moneyline':
		case 'second_moneyline':
		case 'spread':
		case 'half_spread':
		case 'second_spread':
			return ($userBet['direction'] == 'home') ? $score['home'] : $score['visitor'];
		case 'total':
		case 'half_total':
		case 'second_total':
			return Inflector::humanize($userBet['direction']);
		case 'parlay':		
			return count($userBet['Parlay']).' Team Parlay';
		case 'teaser':
			return count($userBet['Parlay']).' Team Teaser';
		}
	}

	private function floatOrNull($num) {
		if (is_null($num)) {
			return null;
		}
		return (float)$num;
	}

	private function reformatBet($bet) {
		$userBet = $bet['UserBet'];
		$score = $bet['Score'];

		$userBetGameDate = strtotime($userBet['game_date']);
		$scoreGameDate = strtotime($score['game_date']);

		$parlayBetGameDate = 0;
		$parlays = false;
		if (!empty($userBet['Parlay'])) {
			$parlays = $this->reformatBets($userBet['Parlay']);
			foreach ($parlays as $row) {
				$parlayBetGameDate = max($parlayBetGameDate, strtotime($row['date']));
			}
		}
		$tags = array();
		foreach ($bet['Tag'] as $tag) {
			$tags[] = trim($tag['name']);
		}

		$fields = array(
		    'betid' => $userBet['id'],
		    'scoreid' => $score['id'],
		    'date' => date('Y-m-d H:i:s', max($userBetGameDate, $scoreGameDate, $parlayBetGameDate)),
		    'league' => $this->getLeague($userBet, $score['league']),
		    'beton' => $this->getBetOn($userBet, $score),
		    'type' => $userBet['type'],
		    'line' => $this->floatOrNull($userBet['spread']),
		    'home' => $score['home'],
		    'visitor' => $score['visitor'],
		    'risk' => $userBet['risk'],
		    'odds' => $userBet['odds'],
		    'winning' => $userBet['winning'],
		    'book' => $userBet['source'],
		    'direction' => $userBet['direction'],
		    'tag' => implode(',', $tags),
		    'parlays' => $parlays,
		    'created' => $userBet['created']
		);
		return $fields;
	}
	
	private function getLeague($userBet, $league) {
		if (!empty($userBet['Parlay'])) {
			foreach ($userBet['Parlay'] as $parlay) {
				if (empty($league)) {
					$league = $parlay['Score']['league'];
				}
				if ($league != $parlay['Score']['league']) {
					return 'Mixed';
				}
			}
		}
		return $league;
	}

	// Each cond is or.
	private function betMatchCond($betval, $cond, $key) {
		if ($key == 'winning') {
			$match = true;
			if (!numberSafeEmpty($cond['gte'])) {
				$match = $match && ($betval >= $cond['gte']);
			}
			if (!numberSafeEmpty($cond['lte'])) {
				$match = $match && ($betval <= $cond['lte']);
			}
			return $match;
		} else {
			foreach ($cond as $match) {
				if ($key == 'tag') {
					if (strtolower($match) == 'none' && empty($betval)) {
						return true;
					}
					if (in_array($match, explode(',', $betval))) {
						return true;
					}
				}  else if ($match == $betval) {
					return true;
				}
			}
		}
		return false;
	}

	// Each key is and
	private function isMatchingBet($bet, $cond, $keys) {
		foreach ($keys as $key) {
			if (isset($cond[$key])) {
				if (!$this->betMatchCond($bet[$key], $cond[$key], $key)) {
					return false;
				}
			}
		}
		return true;
	}

	private function filterNonSql(&$bets, $cond, $keys) {
		$ret = array();
		$i = 0;
		foreach ($keys as $key) {
			if (isset($cond[$key])) {
				$i++;
			}
		}
		// No non sql filters
		if ($i <= 0) {
			return $bets;
		}
		foreach ($bets as $bet) {
			if ($this->isMatchingBet($bet, $cond, $keys)) {
				$ret[] = $bet;
			}
		}
		return $ret;
	}

	private function getPublicUserId() {
		$share = $this->urlGetVar('share', null);
		if (empty($share)) {
			return null;
		}
		$str = Security::cipher(base64_decode($share), 'publicid');
		if (strpos($str, 'p=') === 0) {
			list($p, $id) = explode('=', $str);
			return $id;
		}
		return null;
	}

	private function getShare($id) {
		return base64_encode(Security::cipher("p=$id", 'publicid'));
	}

	public function view() {
		
		$cond = array(
		    'home' => $this->urlGetVar('home'),
		    'visitor' => $this->urlGetVar('visitor'),
		    'type' => $this->urlGetVar('type'),
		    'league' => $this->urlGetVar('league'),
		    'beton' => $this->urlGetVar('beton'),
		    'book' => $this->urlGetVar('book'),
		    'tag' => $this->urlGetVar('tag'),
		    'risk' => $this->urlGetVar('risk'),
		    'game_date' => $this->urlGetVar('game_date'),
		    'odds' => $this->urlGetVar('odds'),
		    'spread' => $this->urlGetVar('spread'),
		    'winning' => $this->urlGetVar('winning')
		);		
		list($sqlcond, $cond) = $this->fixCond($cond);
		$this->set('cond', $cond);
		$this->set('condAsMap', $this->getCondAsMap($cond));

		$sort = $this->urlGetVar('sort', 'default,desc');
		if (strpos($sort, ',') !== false) {
			list($this->sortKey, $this->sortDir) = explode(',', $sort);
		} else {
			$this->sortKey = $sort;
			$this->sortDir = 'desc';
		}
		$this->set('sortKey', $this->sortKey);
		$this->set('sortDir', $this->sortDir);

		$publicuserid = $this->getPublicUserId();
		$userid = empty($publicuserid) ? $this->Auth->user('id') : $publicuserid;
		$viewingUser = $this->User->findById($userid);
		$this->set('viewingUser', $viewingUser);

		$this->set('isPublic', !empty($publicuserid));
		$this->set('share', $this->getShare($userid));
		if (empty($viewingUser)) {
			$this->redirect('/');
		}

		// For filters we set them to the list of all bets
		$bets = $this->UserBet->getAll($userid);
		$bets = $this->reformatBets($bets);
		$filters = $this->setFilters($bets,
			array('home', 'visitor', 'type', 'league', 'beton', 'book', 'tag'),
			array('risk', 'game_date', 'spread', 'odds', 'risk', 'winning'));
		$this->set('filters', $filters);

		// Display only sql, and nonsql matching bets
		$bets = $this->UserBet->getAll($userid, null, $sqlcond);
		$bets = $this->reformatBets($bets);
		$bets = $this->filterNonSql($bets, $cond, array('beton', 'league', 'tag', 'winning'));
		usort($bets, array($this, '_sort_bets'));

		$record = $this->winLossTie($bets);
		$this->set('record', $record);
		$allStats = $this->allStats($bets);
		$this->set('allStats', $allStats);
		$groupStats = $this->getGroupStats($bets);
		$this->set('groupStats', $groupStats);
		$analysisStats = $this->getAnalysisStats($bets);
		$this->set('analysisStats', $analysisStats);
		$this->set('graphData', $this->graphData($bets));

		$this->set('betTypes', $this->getBetTypes());

		$this->set('bets', $bets);
	}

	private function getAnalysisStats(&$bets) {
		$analys = array();
		foreach($bets as $bet) {
			list($type, $stat) = $this->analyseBet($bet);
			if (!empty($stat)) {
				if (!isset($analys[$type])) {
					$analys[$type] = array();
				}
				if (!isset($analys[$type][$stat])) {
					$analys[$type][$stat] = array();
				}
				$analys[$type][$stat][] = $bet;
			}
		}
		$ret = array();
		foreach ($analys as $type => $stats) {
			foreach ($stats as $stat => &$theBets) {
				$ret[$type][$stat] = array();
				$ret[$type][$stat] = $this->winLossTie($theBets);
			}
		}
		return $ret;
	}

	private function analyseBet($bet) {
		$type = $bet['type'];
		$stat = false;
		if (!in_array($type, array('parlay','teaser'))) {
			$stat = $bet['direction'];
			if (!in_array($type, array('total','half_total','second_total'))) {
				$stat = $stat.'_'.($this->isFavorite($bet) ? 'favorite' : 'underdog');
			}
		}
		return array($type, $stat);
	}

	private function isFavorite(&$bet) {
		if ($bet['line'] > 0) {
			return false;
		} else if ($bet['line'] < 0) {
			return true;
		} else {
			return $bet['odds'] < 0;
		}
	}

	private function getGroupStats(&$bets) {
		$groupStats = array(
		    'Odds' => $this->calcGroupStats($bets, array(
			'odds' => array(
			    new CalcBetween(false, -201),
			    new CalcBetween(-200, -125),
			    new CalcBetween(-124, -111),
			    new CalcBetween(-110, -101),
			    new CalcBetween(100, 110),
			    new CalcBetween(111, 124),
			    new CalcBetween(125, 185),
			    new CalcBetween(186, 300),
			    new CalcBetween(301, false)
			)
		    )),
		    'Risk' => $this->calcGroupStats($bets, array(
			'risk' => array(
			    new CalcBetween(false, 100),
			    new CalcBetween(101, 200),
			    new CalcBetween(201, 300),
			    new CalcBetween(301, 400),
			    new CalcBetween(401, 500),
			    new CalcBetween(501, 600),
			    new CalcBetween(601, 700),
			    new CalcBetween(701, 800),
			    new CalcBetween(801, 900),
			    new CalcBetween(901, 1000),
			    new CalcBetween(1001, 1100),
			    new CalcBetween(1101, 1200),
			    new CalcBetween(1201, false)
			)
		    )),
		    'Bet Type' => $this->calcGroupStats($bets, array(
			'type' => array(
			    new CalcIn('spread', 'half_spread', 'second_spread'),
			    new CalcIn('total', 'half_total', 'second_total'),
			    new CalcIn('moneyline', 'half_moneyline', 'second_moneyline'),
			    new CalcIn('parlay'),
			    new CalcIn('teaser')
			)
		    ))
		);
		return $groupStats;
	}

	private function calcGroupStats(&$bets, $conditions) {
		$matches = array();
		foreach ($conditions as $field => $cond) {
			foreach ($cond as $k => $val) {
				$matches[$k] = array('CalcStat' => $val, 'matching' => array());
			}
			foreach ($bets as $bet) {
				foreach ($cond as $k => $CalcStat) {
					if ($CalcStat->matches($bet[$field])) {
						$matches[$k]['matching'][] = $bet;
					}
				}
			}
			break; // We only support 1 condition at this point
		}
		$ret = array();
		foreach ($matches as $match) {
			$ret[] = array(
			    'CalcStat' => $match['CalcStat'],
			    'record' => $this->winLossTie($match['matching'])
			);
		}
		return $ret;
	}

	private function _sort_bets_null($oleft, $oright) {
		if (is_null($oleft) ^ is_null($oright)) {
			return is_null($oleft) ? 1 : -1; // null is considered larger that not null
		}
		return 0;
	}

	private function _cmp_bets($left, $right, $asc) {
		if ($left == $right) {
			return 0;
		}
		if ($left > $right) {
			return $asc ? 1 : -1;
		} else {
			return $asc ? -1 : 1;
		}
	}

	private function _sort_bets($oleft, $oright) {
		
		// Default sort is 1. Winning Null 2. Datetime
		$default = $this->sortKey == 'default';
		$sortKey = $default ? 'date' : $this->sortKey;

		$left = $oleft[$sortKey];
		$right = $oright[$sortKey];
		$asc = $this->sortDir == 'asc';
		if ($default) {
			$null = $this->_sort_bets_null($oleft['winning'], $oright['winning']);
			if ($null != 0) {
				return $asc ? $null : -1 * $null;
			}
		}
		return $this->_cmp_bets($left, $right, $asc);
	}
}

interface CalcStat {
	public function matches($val);
}

class CalcBetween implements CalcStat {
	private $start;
	private $stop;
	public function __construct($start, $stop) {
		$this->start = $start;
		$this->stop = $stop;
	}
	public function getDef() {
		return array($this->start, $this->stop);
	}
	public function matches($val) {
		if (is_null($val)) {
			return false;
		}
		if ($this->start === false) {
			return $val <= $this->stop;
		}
		if ($this->stop === false) {
			return $val >= $this->start;
		}
		return $this->start <= $val && $val <= $this->stop;
	}
}

class CalcIn implements CalcStat {
	private $args;
	public function __construct() {
		$this->args = func_get_args();
	}
	public function getDef() {
		return Inflector::humanize($this->args[0]);
	}
	public function matches($val) {
		return in_array($val, $this->args);
	}
}
