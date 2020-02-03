<?php
declare(strict_types=1);
namespace ChestShop;

//use onebone\economyapi\EconomyAPI;
use onebone\economyapi\event\Issuer;

class ChestShopIssuer implements Issuer {
	public function __toString(): string {
		return "ChestShop";
	}
}