<?php

namespace ChestShop;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest as TileChest;
use pocketmine\block\Chest as BlockChest;

class EventListener implements Listener
{
    private $plugin;
    private $databaseManager;

    public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
    {
        $this->plugin = $plugin;
        $this->databaseManager = $databaseManager;
    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();

        switch ($block->getID()) {
            case Block::SIGN_POST:
            case Block::WALL_SIGN:
                if (($shopInfo = $this->databaseManager->selectByCondition([
                        "signX" => $block->getX(),
                        "signY" => $block->getY(),
                        "signZ" => $block->getZ()
                    ])) === false) return;
                if ($shopInfo['shopOwner'] === $player->getName()) {
                    $player->sendMessage("Cannot purchase from your own shop!");
                    return;
                }
                $buyerMoney = $this->plugin->getServer()->getPluginManager()->getPlugin("PocketMoney")->getMoney($player->getName());
                if (!is_numeric($buyerMoney)) { // Probably $buyerMoney is instance of SimpleError
                    $player->sendMessage("Couldn't get your money data!");
                    return;
                }
                if ($buyerMoney < $shopInfo['price']) {
                    $player->sendMessage("Your money is not enough!");
                    return;
                }
                /** @var TileChest $chest */
                $chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
                $itemNum = 0;
                $pID = $shopInfo['productID'];
                $pMeta = $shopInfo['productMeta'];
                for ($i = 0; $i < BlockChest::SLOTS; $i++) {
                    $item = $chest->getItem($i);
                    // use getDamage() method to get metadata of item
                    if ($item->getID() === $pID and $item->getDamage() === $pMeta) $itemNum += $item->getCount();
                }
                if ($itemNum < $shopInfo['saleNum']) {
                    $player->sendMessage("This shop is out of stock!");
                    $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])->sendMessage("Your ChestShop is out of stock! Replenish ID:$pID!");
                    return;
                }

                $player->getInventory()->addItem((int)Item::get($shopInfo['productID'], (int)$shopInfo['saleNum'], (int)$shopInfo['productMeta']));

                $tmpNum = $shopInfo['saleNum'];
                for ($i = 0; $i < BlockChest::SLOTS; $i++) {
                    $item = $chest->getInventory()->getItem($i);
                    // Use getDamage() method to get metadata of item
                    if ($item->getID() === $pID and $item->getDamage() === $pMeta) {
                        if ($item->getCount() <= $tmpNum) {
                            $chest->getInventory()->setItem($i, Item::get(Item::AIR, 0, 0));
                            $tmpNum -= $item->getCount();
                        } else {
                            $chest->getInventory()->setItem($i, Item::get($item->getID(), $pMeta, $item->getCount() - $tmpNum));
                            break;
                        }
                    }
                }
                $this->plugin->getServer()->getPluginManager()->getPlugin("PocketMoney")->payMoney($player->getName(), $shopInfo['shopOwner'], $shopInfo['price']);

                $player->sendMessage("Completed transaction");
                $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])->sendMessage("{$player->getName()} purchased ID:$pID:$pMeta {$shopInfo['price']}PM");
                break;

            case Block::CHEST:
                $shopInfo = $this->databaseManager->selectByCondition([
                    "chestX" => $block->getX(),
                    "chestY" => $block->getY(),
                    "chestZ" => $block->getZ()
                ]);
                if ($shopInfo !== false && $shopInfo['shopOwner'] !== $player->getName()) {
                    $player->sendMessage("This chest has been protected!");
                    $event->setCancelled();
                }
                break;

            default:
                break;
        }
    }

    public function onPlayerBreakBlock(BlockBreakEvent $event)
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();

        switch ($block->getID()) {
            case Block::SIGN_POST:
            case Block::WALL_SIGN:
                $condition = [
                    "signX" => $block->getX(),
                    "signY" => $block->getY(),
                    "signZ" => $block->getZ()
                ];
                $shopInfo = $this->databaseManager->selectByCondition($condition);
                if ($shopInfo !== false) {
                    if ($shopInfo['shopOwner'] !== $player->getName()) {
                        $player->sendMessage("This sign has been protected!");
                        $event->setCancelled();
                    } else {
                        $this->databaseManager->deleteByCondition($condition);
                        $player->sendMessage("Closed your ChestShop");
                    }
                }
                break;

            case Block::CHEST:
                $condition = [
                    "chestX" => $block->getX(),
                    "chestY" => $block->getY(),
                    "chestZ" => $block->getZ()
                ];
                $shopInfo = $this->databaseManager->selectByCondition($condition);
                if ($shopInfo !== false) {
                    if ($shopInfo['shopOwner'] !== $player->getName()) {
                        $player->sendMessage("This chest has been protected!");
                        $event->setCancelled();
                    } else {
                        $this->databaseManager->deleteByCondition($condition);
                        $player->sendMessage("Closed your ChestShop");
                    }
                }
                break;

            default:
                break;
        }
    }

    public function onSignChange(SignChangeEvent $event)
    {
        $shopOwner = $event->getPlayer()->getName();
        $saleNum = $event->getLine(1);
        $price = $event->getLine(2);
        $productData = explode(":", $event->getLine(3));
        $pID = $this->isItem($id = array_shift($productData)) ? $id : false;
        $pMeta = ($meta = array_shift($productData)) ? $meta : 0;

        $sign = $event->getBlock();

        // Check sign format...
        if ($event->getLine(0) !== "") return;
        if (!is_numeric($saleNum) or $saleNum <= 0) return;
        if (!is_numeric($price) or $price < 0) return;
        if ($pID === false) return;
        if (($chest = $this->getSideChest($sign)) === false) return;

        $productName = isset(Block::$list[$pID]) ? Block::$list[$pID]->getName() : Item::$list[$pID]->getName();
        $event->setLine(0, $shopOwner);
        $event->setLine(1, "Amount:$saleNum");
        $event->setLine(2, "Price:$price");
        $event->setLine(3, "$productName:$pMeta");

        $this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
    }

    private function getSideChest(Position $pos)
    {
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
        if ($block->getID() === Block::CHEST) return $block;
        return false;
    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        if (isset(Block::$list[$id])) return true;
        return false;
    }
} 