<?php
declare(strict_types=1);
namespace MyPlot\provider;

use MyPlot\MyPlot;
use MyPlot\Plot;

class SQLiteDataProvider extends DataProvider
{
	/** @var \SQLite3 $db */
	private $db;
	/** @var \SQLite3Stmt */
	private $sqlGetPlot, $sqlSavePlot, $sqlSavePlotById, $sqlRemovePlot, $sqlRemovePlotById, $sqlGetPlotsByOwner, $sqlGetPlotsByOwnerAndLevel, $sqlGetExistingXZ, $sqlMergePlots, $sqlUnmergeByPlots, $sqlGetMergedBase, $sqlGetMergedPlots;

	/**
	 * SQLiteDataProvider constructor.
	 *
	 * @param MyPlot $plugin
	 * @param int $cacheSize
	 */
	public function __construct(MyPlot $plugin, int $cacheSize = 0) {
		parent::__construct($plugin, $cacheSize);
		$this->db = new \SQLite3($this->plugin->getDataFolder() . "plots.db");
		$this->db->exec("CREATE TABLE IF NOT EXISTS plots
			(id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, X INTEGER, Z INTEGER, name TEXT,
			 owner TEXT, helpers TEXT, denied TEXT, biome TEXT, pvp INTEGER);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS merges
			(id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, X1 INTEGER, Z1 INTEGER, X2 INTEGER UNIQUE, Z2 INTEGER UNIQUE);");
		try{
			$this->db->exec("ALTER TABLE plots ADD pvp INTEGER;");
		}catch(\Exception $e) {
			// nothing :P
		}
		$this->sqlGetPlot = $this->db->prepare("SELECT id, name, owner, helpers, denied, biome, pvp FROM plots WHERE level = :level AND X = :X AND Z = :Z;");
		$this->sqlSavePlot = $this->db->prepare("INSERT OR REPLACE INTO plots (id, level, X, Z, name, owner, helpers, denied, biome, pvp) VALUES
			((SELECT id FROM plots WHERE level = :level AND X = :X AND Z = :Z),
			 :level, :X, :Z, :name, :owner, :helpers, :denied, :biome, :pvp);");
		$this->sqlSavePlotById = $this->db->prepare("UPDATE plots SET name = :name, owner = :owner, helpers = :helpers, denied = :denied, biome = :biome, pvp = :pvp WHERE id = :id;");
		$this->sqlRemovePlot = $this->db->prepare("DELETE FROM plots WHERE level = :level AND X = :X AND Z = :Z;");
		$this->sqlRemovePlotById = $this->db->prepare("DELETE FROM plots WHERE id = :id;");
		$this->sqlGetPlotsByOwner = $this->db->prepare("SELECT * FROM plots WHERE owner = :owner;");
		$this->sqlGetPlotsByOwnerAndLevel = $this->db->prepare("SELECT * FROM plots WHERE owner = :owner AND level = :level;");
		$this->sqlGetExistingXZ = $this->db->prepare("SELECT X, Z FROM plots WHERE (
				level = :level
				AND (
					(abs(X) = :number AND abs(Z) <= :number) OR
					(abs(Z) = :number AND abs(X) <= :number)
				)
			);");
		$this->sqlMergePlots = $this->db->prepare("INSERT OR REPLACE INTO merges (level, X1, Z1, X2, Z2) VALUES (:level, :x1, :z1, :x2, :z2)");
		$this->sqlUnmergeByPlots = $this->db->prepare("DELETE FROM merges WHERE level = :level AND X2 = :X AND Z2 = :Z;");
		$this->sqlGetMergedBase = $this->db->prepare("SELECT * FROM merges WHERE level = :level AND X2 = :X and Z2 = :Z;");
		$this->sqlGetMergedPlots = $this->db->prepare("SELECT * FROM merges WHERE level = :level AND X1 = :X and Z1 = :Z;");
		$this->plugin->getLogger()->debug("SQLite data provider registered");
	}

	/**
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function savePlot(Plot $plot) : bool {
		$helpers = implode(",", $plot->helpers);
		$denied = implode(",", $plot->denied);
		if($plot->id >= 0) {
			$stmt = $this->sqlSavePlotById;
			$stmt->bindValue(":id", $plot->id, SQLITE3_INTEGER);
		}else{
			$stmt = $this->sqlSavePlot;
			$stmt->bindValue(":level", $plot->levelName, SQLITE3_TEXT);
			$stmt->bindValue(":X", $plot->X, SQLITE3_INTEGER);
			$stmt->bindValue(":Z", $plot->Z, SQLITE3_INTEGER);
		}
		$stmt->bindValue(":name", $plot->name, SQLITE3_TEXT);
		$stmt->bindValue(":owner", $plot->owner, SQLITE3_TEXT);
		$stmt->bindValue(":helpers", $helpers, SQLITE3_TEXT);
		$stmt->bindValue(":denied", $denied, SQLITE3_TEXT);
		$stmt->bindValue(":biome", $plot->biome, SQLITE3_TEXT);
		$stmt->bindValue(":pvp", $plot->pvp, SQLITE3_INTEGER);
		$stmt->reset();
		$result = $stmt->execute();
		if($result === false) {
			return false;
		}
		$this->cachePlot($plot);
		return true;
	}

	/**
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function deletePlot(Plot $plot) : bool {
		if($plot->id >= 0) {
			$stmt = $this->sqlRemovePlotById;
			$stmt->bindValue(":id", $plot->id, SQLITE3_INTEGER);
		}else{
			$stmt = $this->sqlRemovePlot;
			$stmt->bindValue(":level", $plot->levelName, SQLITE3_TEXT);
			$stmt->bindValue(":X", $plot->X, SQLITE3_INTEGER);
			$stmt->bindValue(":Z", $plot->Z, SQLITE3_INTEGER);
		}
		$stmt->reset();
		$result = $stmt->execute();
		if($result === false) {
			return false;
		}
		$plot = new Plot($plot->levelName, $plot->X, $plot->Z);
		$this->cachePlot($plot);
		return true;
	}

	/**
	 * @param string $levelName
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Plot
	 */
	public function getPlot(string $levelName, int $X, int $Z) : Plot {
		if(($plot = $this->getPlotFromCache($levelName, $X, $Z)) !== null) {
			return $plot;
		}
		$this->sqlGetPlot->bindValue(":level", $levelName, SQLITE3_TEXT);
		$this->sqlGetPlot->bindValue(":X", $X, SQLITE3_INTEGER);
		$this->sqlGetPlot->bindValue(":Z", $Z, SQLITE3_INTEGER);
		$this->sqlGetPlot->reset();
		$result = $this->sqlGetPlot->execute();
		if($val = $result->fetchArray(SQLITE3_ASSOC)) {
			if($val["helpers"] === null or $val["helpers"] === "") {
				$helpers = [];
			}else{
				$helpers = explode(",", (string) $val["helpers"]);
			}
			if($val["denied"] === null or $val["denied"] === "") {
				$denied = [];
			}else{
				$denied = explode(",", (string) $val["denied"]);
			}
			$pvp = is_numeric($val["pvp"]) ? (bool)$val["pvp"] : null;
			$plot = new Plot($levelName, $X, $Z, (string) $val["name"], (string) $val["owner"], $helpers, $denied, (string) $val["biome"], $pvp, (int) $val["id"]);
		}else{
			$plot = new Plot($levelName, $X, $Z);
		}
		$this->cachePlot($plot);
		return $plot;
	}

	/**
	 * @param string $owner
	 * @param string $levelName
	 *
	 * @return array
	 */
	public function getPlotsByOwner(string $owner, string $levelName = "") : array {
		if($levelName === "") {
			$stmt = $this->sqlGetPlotsByOwner;
		}else{
			$stmt = $this->sqlGetPlotsByOwnerAndLevel;
			$stmt->bindValue(":level", $levelName, SQLITE3_TEXT);
		}
		$stmt->bindValue(":owner", $owner, SQLITE3_TEXT);
		$plots = [];
		$stmt->reset();
		$result = $stmt->execute();
		while($val = $result->fetchArray(SQLITE3_ASSOC)) {
			$helpers = explode(",", (string) $val["helpers"]);
			$denied = explode(",", (string) $val["denied"]);
			$pvp = is_numeric($val["pvp"]) ? (bool)$val["pvp"] : null;
			$plots[] = new Plot((string) $val["level"], (int) $val["X"], (int) $val["Z"], (string) $val["name"], (string) $val["owner"], $helpers, $denied, (string) $val["biome"], $pvp, (int) $val["id"]);
		}
		// Remove unloaded plots
		$plots = array_filter($plots, function($plot) {
			return $this->plugin->isLevelLoaded($plot->levelName);
		});
		// Sort plots by level
		usort($plots, function($plot1, $plot2) {
			return strcmp($plot1->levelName, $plot2->levelName);
		});
		return $plots;
	}

	/**
	 * @param Plot $base
	 * @param Plot[] $plots
	 *
	 * @return bool
	 */
	public function mergePlots(Plot $base, Plot ...$plots) : bool {
		foreach($plots as $plot) {
			$stmt = $this->sqlMergePlots;
			$stmt->bindValue(":level",$base->levelName, SQLITE3_TEXT);
			$stmt->bindValue(":x1", $base->X, SQLITE3_INTEGER);
			$stmt->bindValue(":z1", $base->Z, SQLITE3_INTEGER);
			$stmt->bindValue(":x2", $plot->X, SQLITE3_INTEGER);
			$stmt->bindValue(":z2", $plot->Z, SQLITE3_INTEGER);
			$stmt->reset();
			$result = $stmt->execute();
			if($result === false) {
				continue;
			}
		}
		return true;
	}

	/**
	 * @param Plot[] $plots
	 *
	 * @return bool
	 */
	public function unMergePlots(Plot ...$plots) : bool {
		foreach($plots as $plot) {
			$stmt = $this->sqlUnmergeByPlots;
			$stmt->bindValue(":level", $plot->levelName, SQLITE3_TEXT);
			$stmt->bindValue(":X", $plot->X, SQLITE3_INTEGER);
			$stmt->bindValue(":Z", $plot->Z, SQLITE3_INTEGER);
			$stmt->reset();
			$result = $stmt->execute();
			if($result === false) {
				continue;
			}
		}
		return true;
	}

	/**
	 * @param Plot $plot
	 *
	 * @return Plot[]
	 */
	public function getMergedPlots(Plot $plot) : array {
		/** @var Plot[] $plots */
		$plots = [];
		$stmt = $this->sqlGetMergedPlots;
		$stmt->bindValue(":level", $plot->levelName, SQLITE3_TEXT);
		$stmt->bindValue(":X", $plot->X, SQLITE3_INTEGER);
		$stmt->bindValue(":Z", $plot->Z, SQLITE3_INTEGER);
		$stmt->reset();
		$result = $stmt->execute();
		if($result === false) {
			return $this->getMergedPlots($this->getMergedBase($plot)); // recursion?
		}
		while($val = $result->fetchArray(SQLITE3_ASSOC)) {
			$plots[] = $this->getPlot($val["level"], (int)$val["X1"], (int)$val["X2"]);
		}
		return $plots;
	}

	/**
	 * @param Plot $plot
	 *
	 * @return Plot
	 */
	public function getMergedBase(Plot $plot) : Plot {
		$stmt = $this->sqlGetMergedBase;
		$stmt->bindValue(":level", $plot->levelName, SQLITE3_TEXT);
		$stmt->bindValue(":X", $plot->X, SQLITE3_INTEGER);
		$stmt->bindValue(":Z", $plot->Z, SQLITE3_INTEGER);
		$stmt->reset();
		$result = $stmt->execute();
		if($result === false) {
			return $plot; // base is the given plot
		}
		$val = $result->fetchArray(SQLITE3_ASSOC);
		return $this->getPlot($val["level"], (int)$val["X1"], (int)$val["X2"]);
	}

	/**
	 * @param string $levelName
	 * @param int $limitXZ
	 *
	 * @return Plot|null
	 */
	public function getNextFreePlot(string $levelName, int $limitXZ = 0) : ?Plot {
		$this->sqlGetExistingXZ->bindValue(":level", $levelName, SQLITE3_TEXT);
		$i = 0;
		$this->sqlGetExistingXZ->bindParam(":number", $i, SQLITE3_INTEGER);
		for(; $limitXZ <= 0 or $i < $limitXZ; $i++) {
			$this->sqlGetExistingXZ->reset();
			$result = $this->sqlGetExistingXZ->execute();
			$plots = [];
			while($val = $result->fetchArray(SQLITE3_NUM)) {
				$plots[$val[0]][$val[1]] = true;
			}
			if(count($plots) === max(1, 8 * $i)) {
				continue;
			}
			if($ret = self::findEmptyPlotSquared(0, $i, $plots)) {
				list($X, $Z) = $ret;
				$plot = new Plot($levelName, $X, $Z);
				$this->cachePlot($plot);
				return $plot;
			}
			for($a = 1; $a < $i; $a++) {
				if($ret = self::findEmptyPlotSquared($a, $i, $plots)) {
					list($X, $Z) = $ret;
					$plot = new Plot($levelName, $X, $Z);
					$this->cachePlot($plot);
					return $plot;
				}
			}
			if($ret = self::findEmptyPlotSquared($i, $i, $plots)) {
				list($X, $Z) = $ret;
				$plot = new Plot($levelName, $X, $Z);
				$this->cachePlot($plot);
				return $plot;
			}
		}
		return null;
	}

	public function close() : void {
		$this->db->close();
		$this->plugin->getLogger()->debug("SQLite database closed!");
	}
}