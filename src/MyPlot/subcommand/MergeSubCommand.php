<?php
declare(strict_types=1);
namespace MyPlot\subcommand;

use pocketmine\command\CommandSender;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class MergeSubCommand extends SubCommand
{
	/**
	 * @param CommandSender $sender
	 *
	 * @return bool
	 */
	public function canUse(CommandSender $sender) : bool {
		return ($sender instanceof Player) and ($sender->hasPermission("myplot.command.middle"));
	}

	/**
	 * @param Player $sender
	 * @param string[] $args
	 *
	 * @return bool
	 */
	public function execute(CommandSender $sender, array $args) : bool {
		if(empty($args)) {
			return false;
		}
		$plot = $this->getPlugin()->getPlotByPosition($sender);
		if($plot === null) {
			$sender->sendMessage(TextFormat::RED . $this->translateString("notinplot"));
			return true;
		}
		if($plot->owner !== $sender->getName() and !$sender->hasPermission("myplot.admin.merge")) {
			$sender->sendMessage(TextFormat::RED . $this->translateString("notowner"));
			return true;
		}
		switch(strtolower($args[0])) {
			case "north":
			case $this->translateString("merge.north"):
				$direction = Vector3::SIDE_NORTH;
				$args[0] = $this->translateString("merge.north");
			break;
			case "south":
			case $this->translateString("merge.south"):
				$direction = Vector3::SIDE_NORTH;
				$args[0] = $this->translateString("merge.south");
			break;
			case "east":
			case $this->translateString("merge.east"):
				$direction = Vector3::SIDE_NORTH;
				$args[0] = $this->translateString("merge.east");
			break;
			case "west":
			case $this->translateString("merge.west"):
				$direction = Vector3::SIDE_NORTH;
				$args[0] = $this->translateString("merge.west");
			break;
			default:
				$sender->sendMessage(TextFormat::RED . $this->translateString("merge.direction"));
				return true;
		}
		if($this->getPlugin()->mergePlots($plot, $direction)) {
			$plot = TextFormat::GREEN . $plot . TextFormat::WHITE;
			$sender->sendMessage($this->translateString("merge.success", [$plot, $args[0]]));
		}else{
			$sender->sendMessage(TextFormat::RED . $this->translateString("error"));
		}
		return false;
	}
}