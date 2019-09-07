<?php
declare(strict_types=1);
namespace MyPlot\task;

use MyPlot\MyPlot;
use MyPlot\Plot;
use pocketmine\block\Block;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;

class RoadFillTask extends Task {
	/** @var MyPlot $plugin */
	private $plugin;
	private $base, $level, $height, $bottomBlock, $plotFillBlock, $plotFloorBlock, $plotBeginPos, $xMax, $zMax, $maxBlocksPerTick, $pos;

	/**
	 * PlotMergeTask constructor.
	 *
	 * @param MyPlot $plugin
	 * @param Plot $start
	 * @param Plot $end
	 * @param int $maxBlocksPerTick
	 */
	public function __construct(MyPlot $plugin, Plot $start, Plot $end, int $maxBlocksPerTick = 256) {
		$this->plugin = $plugin;
		$this->base = $start;
		$this->plotBeginPos = $plugin->getPlotPosition($start);
		$endPos = $plugin->getPlotPosition($end);
		$this->level = $this->plotBeginPos->getLevel();
		$plotLevel = $plugin->getLevelSettings($end->levelName);
		$plotSize = $plotLevel->plotSize;
		$this->xMax = $endPos->x + $plotSize;
		$this->zMax = $endPos->z + $plotSize;
		$this->height = $plotLevel->groundHeight;
		$this->bottomBlock = $plotLevel->bottomBlock;
		$this->plotFillBlock = $plotLevel->plotFillBlock;
		$this->plotFloorBlock = $plotLevel->plotFloorBlock;
		$this->maxBlocksPerTick = $maxBlocksPerTick;
		$this->pos = new Vector3($this->plotBeginPos->x, 0, $this->plotBeginPos->z);
	}

	public function onRun(int $currentTick) {
		$blocks = 0;
		while($this->pos->x < $this->xMax) {
			while($this->pos->z < $this->zMax) {
				if($this->plugin->getPlotByPosition(Position::fromObject($this->pos, $this->level)) === null) {
					while($this->pos->y < $this->level->getWorldHeight()) {
						if($this->pos->y === 0) {
							$block = $this->bottomBlock;
						}elseif($this->pos->y < $this->height) {
							$block = $this->plotFillBlock;
						}elseif($this->pos->y === $this->height) {
							$block = $this->plotFloorBlock;
						}else{
							$block = Block::get(Block::AIR);
						}
						$this->level->setBlock($this->pos, $block, false, false);
						$blocks++;
						if($blocks >= $this->maxBlocksPerTick) {
							$this->plugin->getScheduler()->scheduleDelayedTask($this, 1);
							return;
						}
						$this->pos->y++;
					}
					$this->pos->y = 0;
				}
				$this->pos->z++;
			}
			$this->pos->z = $this->plotBeginPos->z;
			$this->pos->x++;
		}
	}
}