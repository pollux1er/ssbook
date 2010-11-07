<?php

class UserBet extends AppModel {
	var $name = 'UserBet';
	var $belongsTo = array(
		'Score' => array(
			'className' => 'Score',
			'foreignKey' => 'scoreid',
			'type' => 'LEFT OUTER'
		)
	);

	public function possibleTypes() {
		return array(
		    'moneyline',
		    'half_moneyline',
		    'second_moneyline',
		    'spread',
		    'half_spread',
		    'second_spread',
		    'total',
		    'half_total',
		    'second_total',
		    'parlay',
		    'teaser'
		);
	}

	public function possibleDirections() {
		return array(
		    'home',
		    'visitor',
		    'over',
		    'under'
		);
	}

	/**
	 * expects (type, direction, spread, risk, odds, scoreid, book, parlay, game_date)
	 */
	public function persist($userid, &$bet) {
		$this->create();
		$save = array(
			'userid' => $userid,
			'scoreid' => $bet['scoreid'],
			'game_date' => $bet['date_std'],
			'type' => $bet['type'],
			'direction' => $bet['direction'],
			'spread' => $bet['spread'],
			'odds' => $bet['odds'],
			'risk' => $bet['risk'],
			'parlayid' => isset($bet['parlayid']) ? $bet['parlayid'] : null,
			'pt' => isset($bet['pt']) ? $bet['pt'] : null
		);
		if (!empty($bet['book'])) {
			$save['sourceid'] = $this->getSaveSource($bet['book']);
			$bet['sourceid'] = $save['sourceid'];
		}
		$success = $this->save($save);
		if ($success) {
			$bet['id'] = $this->id;
		}
		return $success;
	}	

	private function getSaveSource($name) {
		if (empty($this->SourceType)) {
			App::import('Model', 'SourceType');
			$this->SourceType = new SourceType();
		}
		return $this->SourceType->getOrSet($name);
	}

	public function getAll($userid, $parlayids = null, $cond = array()) {

		$cond = array_merge($cond, array('userid' => $userid, 'parlayid' => $parlayids));
		$bets = $this->find('all', array(
			'conditions' => $cond
		));

		App::import('Model', 'LeagueType');
		$this->LeagueType = new LeagueType();
		App::import('Model', 'SourceType');
		$this->SourceType = new SourceType();

		foreach ($bets as &$bet) {
			$bet['Score']['league'] = $this->LeagueType->getName($bet['Score']['league']);
			$bet['UserBet']['source'] = $this->SourceType->getName($bet['UserBet']['sourceid']);
			$bet['UserBet']['bet'] = self::buildBet($bet['UserBet']);
			if ($bet['UserBet']['type'] == 'parlay' || $bet['UserBet']['type'] == 'teaser') {
				$bet['UserBet']['Parlay'] = $this->getParlays($userid, $bet['UserBet']['id']);
			}
			$nullRisk = is_null($bet['UserBet']['risk']);
			if ($nullRisk) {
				$bet['UserBet']['risk'] = 1;
			}
			$winning = self::calcWinning($bet['Score'], $bet['UserBet']);
			$bet['UserBet']['winning'] = $winning;
		}
		return $bets;
	}
	
	public function getParlays($userid, $id) {
		return $this->getAll($userid, $id);
	}

	public static function calcWinning($score, $bet) {
		App::import('Vendor', 'calculator/winning');
		$w = new Winning($score, $bet);
		return $w->process();
	}

	public static function buildBet($bet) {
		$ret = "";
		switch ($bet['type']) {
		case 'spread':
		case 'half_spread':
		case 'total':
		case 'half_total':
			$ret .= $bet['spread'];
		case 'half_moneyline':
		case 'moneyline':
			$ret .= "({$bet['odds']})";
		}
		return $ret;
	}
}
