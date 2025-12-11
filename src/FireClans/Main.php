<?php

declare(strict_types=1);

namespace FireClans;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;

class Main extends PluginBase implements Listener {

    private Config $clans;
    private Config $members;
    private Config $deposits;
    private ?object $economy = null;
    
    private const DAILY_INTEREST_RATE = 0.02;
    private array $invites = [];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        @mkdir($this->getDataFolder());
        
        $this->clans = new Config($this->getDataFolder() . "clans.yml", Config::YAML);
        $this->members = new Config($this->getDataFolder() . "members.yml", Config::YAML);
        $this->deposits = new Config($this->getDataFolder() . "deposits.yml", Config::YAML);
        
        $economyPlugin = $this->getServer()->getPluginManager()->getPlugin("BedrockEconomy");
        if ($economyPlugin !== null && $economyPlugin->isEnabled()) {
            $this->economy = $economyPlugin;
            $this->getLogger()->info(TF::GREEN . "Hooked into BedrockEconomy!");
        } else {
            $this->getLogger()->warning("BedrockEconomy not found! Economy features disabled.");
        }
        
        $this->getScheduler()->scheduleRepeatingTask(new InterestTask($this), 20 * 60 * 60 * 24);
        
        $this->getLogger()->info(TF::GREEN . "FireClans by Firekid846 enabled!");
        $this->getLogger()->info(TF::YELLOW . "Daily Interest Rate: " . (self::DAILY_INTEREST_RATE * 100) . "%");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        if ($command->getName() !== "clan") {
            return false;
        }

        if (count($args) < 1) {
            $this->sendHelp($sender);
            return true;
        }

        $action = strtolower($args[0]);

        switch ($action) {
            case "create":
                return $this->createClan($sender, $args);
            case "disband":
                return $this->disbandClan($sender);
            case "invite":
                return $this->inviteMember($sender, $args);
            case "accept":
                return $this->acceptInvite($sender);
            case "deny":
                return $this->denyInvite($sender);
            case "leave":
                return $this->leaveClan($sender);
            case "kick":
                return $this->kickMember($sender, $args);
            case "promote":
                return $this->promoteMember($sender, $args);
            case "demote":
                return $this->demoteMember($sender, $args);
            case "deposit":
                return $this->depositMoney($sender, $args);
            case "withdraw":
                return $this->withdrawMoney($sender, $args);
            case "balance":
            case "bal":
                return $this->checkBalance($sender);
            case "bank":
                return $this->checkClanBank($sender);
            case "info":
                return $this->clanInfo($sender, $args);
            case "list":
                return $this->listClans($sender);
            case "members":
                return $this->listMembers($sender);
            case "chat":
            case "c":
                return $this->toggleClanChat($sender);
            case "sethome":
                return $this->setClanHome($sender);
            case "home":
                return $this->teleportClanHome($sender);
            case "settag":
                return $this->setClanTag($sender, $args);
            default:
                $this->sendHelp($sender);
                return true;
        }
    }

    private function createClan(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan create <name>");
            return true;
        }

        $playerName = $sender->getName();
        
        if ($this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're already in a clan!");
            $sender->sendMessage(TF::GRAY . "Use /clan leave to leave your current clan");
            return true;
        }

        $clanName = $args[1];
        
        if (strlen($clanName) > 16 || strlen($clanName) < 3) {
            $sender->sendMessage(TF::RED . "Clan name must be 3-16 characters!");
            return true;
        }
        
        if ($this->clans->exists($clanName)) {
            $sender->sendMessage(TF::RED . "Clan name already taken!");
            return true;
        }

        $this->clans->set($clanName, [
            "leader" => $playerName,
            "tag" => substr($clanName, 0, 4),
            "members" => [$playerName],
            "officers" => [],
            "bank" => 0,
            "created" => time(),
            "home" => null
        ]);
        $this->clans->save();

        $this->members->set($playerName, [
            "clan" => $clanName,
            "role" => "Leader",
            "deposited" => 0,
            "interest" => 0,
            "joined" => time()
        ]);
        $this->members->save();

        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $sender->sendMessage(TF::GREEN . "✓ Clan created successfully!");
        $sender->sendMessage(TF::YELLOW . "Clan Name: " . TF::WHITE . $clanName);
        $sender->sendMessage(TF::YELLOW . "Leader: " . TF::WHITE . $playerName);
        $sender->sendMessage(TF::YELLOW . "Tag: " . TF::WHITE . "[" . substr($clanName, 0, 4) . "]");
        $sender->sendMessage(TF::GRAY . "Use /clan invite <player> to add members!");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function disbandClan(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];
        
        if ($memberData["role"] !== "Leader") {
            $sender->sendMessage(TF::RED . "Only the clan leader can disband the clan!");
            return true;
        }

        $clanData = $this->clans->get($clanName);
        
        foreach ($clanData["members"] as $member) {
            $this->members->remove($member);
        }
        
        $this->clans->remove($clanName);
        $this->clans->save();
        $this->members->save();

        $sender->sendMessage(TF::RED . "✗ Clan disbanded!");
        
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if (in_array($player->getName(), $clanData["members"])) {
                $player->sendMessage(TF::RED . "Your clan has been disbanded by the leader!");
            }
        }

        return true;
    }

    private function inviteMember(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan invite <player>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        
        if (!in_array($memberData["role"], ["Leader", "Officer"])) {
            $sender->sendMessage(TF::RED . "Only leaders and officers can invite members!");
            return true;
        }

        $targetName = $args[1];
        $target = $this->getServer()->getPlayerByPrefix($targetName);
        
        if ($target === null) {
            $sender->sendMessage(TF::RED . "Player not found!");
            return true;
        }

        if ($this->members->exists($target->getName())) {
            $sender->sendMessage(TF::RED . "That player is already in a clan!");
            return true;
        }

        $clanName = $memberData["clan"];
        $this->invites[$target->getName()] = $clanName;

        $sender->sendMessage(TF::GREEN . "✓ Invite sent to " . $target->getName());
        
        $target->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $target->sendMessage(TF::YELLOW . "You've been invited to join clan:");
        $target->sendMessage(TF::AQUA . $clanName);
        $target->sendMessage(TF::GREEN . "/clan accept" . TF::GRAY . " - Accept invite");
        $target->sendMessage(TF::RED . "/clan deny" . TF::GRAY . " - Deny invite");
        $target->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function acceptInvite(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!isset($this->invites[$playerName])) {
            $sender->sendMessage(TF::RED . "You don't have any pending clan invites!");
            return true;
        }

        $clanName = $this->invites[$playerName];
        unset($this->invites[$playerName]);

        if (!$this->clans->exists($clanName)) {
            $sender->sendMessage(TF::RED . "That clan no longer exists!");
            return true;
        }

        $clanData = $this->clans->get($clanName);
        $clanData["members"][] = $playerName;
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $this->members->set($playerName, [
            "clan" => $clanName,
            "role" => "Member",
            "deposited" => 0,
            "interest" => 0,
            "joined" => time()
        ]);
        $this->members->save();

        $sender->sendMessage(TF::GREEN . "✓ You joined clan: " . TF::AQUA . $clanName);
        
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($this->members->exists($player->getName())) {
                $pData = $this->members->get($player->getName());
                if ($pData["clan"] === $clanName) {
                    $player->sendMessage(TF::GREEN . "✓ " . $playerName . " joined the clan!");
                }
            }
        }

        return true;
    }

    private function denyInvite(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!isset($this->invites[$playerName])) {
            $sender->sendMessage(TF::RED . "You don't have any pending clan invites!");
            return true;
        }

        unset($this->invites[$playerName]);
        $sender->sendMessage(TF::YELLOW . "Clan invite denied.");

        return true;
    }

    private function leaveClan(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];
        
        if ($memberData["role"] === "Leader") {
            $sender->sendMessage(TF::RED . "Leaders cannot leave! Use /clan disband or transfer leadership first.");
            return true;
        }

        $clanData = $this->clans->get($clanName);
        $clanData["members"] = array_values(array_diff($clanData["members"], [$playerName]));
        
        if (isset($clanData["officers"])) {
            $clanData["officers"] = array_values(array_diff($clanData["officers"], [$playerName]));
        }
        
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $this->members->remove($playerName);
        $this->members->save();

        $sender->sendMessage(TF::YELLOW . "You left clan: " . $clanName);
        
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($this->members->exists($player->getName())) {
                $pData = $this->members->get($player->getName());
                if ($pData["clan"] === $clanName) {
                    $player->sendMessage(TF::RED . "✗ " . $playerName . " left the clan!");
                }
            }
        }

        return true;
    }

    private function kickMember(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan kick <player>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        
        if (!in_array($memberData["role"], ["Leader", "Officer"])) {
            $sender->sendMessage(TF::RED . "Only leaders and officers can kick members!");
            return true;
        }

        $targetName = $args[1];
        
        if (!$this->members->exists($targetName)) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        $targetData = $this->members->get($targetName);
        
        if ($targetData["clan"] !== $memberData["clan"]) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        if ($targetData["role"] === "Leader") {
            $sender->sendMessage(TF::RED . "You cannot kick the clan leader!");
            return true;
        }

        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);
        $clanData["members"] = array_values(array_diff($clanData["members"], [$targetName]));
        
        if (isset($clanData["officers"])) {
            $clanData["officers"] = array_values(array_diff($clanData["officers"], [$targetName]));
        }
        
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $this->members->remove($targetName);
        $this->members->save();

        $sender->sendMessage(TF::GREEN . "✓ Kicked " . $targetName . " from the clan!");
        
        $target = $this->getServer()->getPlayerExact($targetName);
        if ($target !== null) {
            $target->sendMessage(TF::RED . "You were kicked from clan: " . $clanName);
        }

        return true;
    }

    private function promoteMember(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan promote <player>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        
        if ($memberData["role"] !== "Leader") {
            $sender->sendMessage(TF::RED . "Only the leader can promote members!");
            return true;
        }

        $targetName = $args[1];
        
        if (!$this->members->exists($targetName)) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        $targetData = $this->members->get($targetName);
        
        if ($targetData["clan"] !== $memberData["clan"]) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        if ($targetData["role"] === "Officer") {
            $sender->sendMessage(TF::RED . "That player is already an officer!");
            return true;
        }

        $targetData["role"] = "Officer";
        $this->members->set($targetName, $targetData);
        $this->members->save();

        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);
        if (!isset($clanData["officers"])) {
            $clanData["officers"] = [];
        }
        $clanData["officers"][] = $targetName;
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $sender->sendMessage(TF::GREEN . "✓ Promoted " . $targetName . " to Officer!");
        
        $target = $this->getServer()->getPlayerExact($targetName);
        if ($target !== null) {
            $target->sendMessage(TF::GREEN . "✓ You've been promoted to Officer!");
        }

        return true;
    }

    private function demoteMember(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan demote <player>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        
        if ($memberData["role"] !== "Leader") {
            $sender->sendMessage(TF::RED . "Only the leader can demote members!");
            return true;
        }

        $targetName = $args[1];
        
        if (!$this->members->exists($targetName)) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        $targetData = $this->members->get($targetName);
        
        if ($targetData["clan"] !== $memberData["clan"]) {
            $sender->sendMessage(TF::RED . "That player is not in your clan!");
            return true;
        }

        if ($targetData["role"] !== "Officer") {
            $sender->sendMessage(TF::RED . "That player is not an officer!");
            return true;
        }

        $targetData["role"] = "Member";
        $this->members->set($targetName, $targetData);
        $this->members->save();

        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);
        if (isset($clanData["officers"])) {
            $clanData["officers"] = array_values(array_diff($clanData["officers"], [$targetName]));
            $this->clans->set($clanName, $clanData);
            $this->clans->save();
        }

        $sender->sendMessage(TF::GREEN . "✓ Demoted " . $targetName . " to Member!");
        
        $target = $this->getServer()->getPlayerExact($targetName);
        if ($target !== null) {
            $target->sendMessage(TF::YELLOW . "You've been demoted to Member.");
        }

        return true;
    }

    private function depositMoney(Player $sender, array $args): bool {
        if ($this->economy === null) {
            $sender->sendMessage(TF::RED . "Economy system not available!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan deposit <amount>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $amount = (int)$args[1];
        
        if ($amount <= 0) {
            $sender->sendMessage(TF::RED . "Amount must be positive!");
            return true;
        }

        $balance = $this->economy->getMoney($playerName);
        
        if ($balance < $amount) {
            $sender->sendMessage(TF::RED . "You don't have enough money!");
            $sender->sendMessage(TF::GRAY . "Your balance: $" . number_format($balance));
            return true;
        }

        $this->economy->takeMoney($playerName, $amount);

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];
        
        $memberData["deposited"] += $amount;
        $this->members->set($playerName, $memberData);
        $this->members->save();

        $clanData = $this->clans->get($clanName);
        $clanData["bank"] += $amount;
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $sender->sendMessage(TF::GREEN . "✓ Deposited $" . number_format($amount) . " to clan bank!");
        $sender->sendMessage(TF::GRAY . "Your total deposits: $" . number_format($memberData["deposited"]));
        $sender->sendMessage(TF::GRAY . "Clan bank total: $" . number_format($clanData["bank"]));

        return true;
    }

    private function withdrawMoney(Player $sender, array $args): bool {
        if ($this->economy === null) {
            $sender->sendMessage(TF::RED . "Economy system not available!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan withdraw <amount>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];

        $amount = (int)$args[1];
        
        if ($amount <= 0) {
            $sender->sendMessage(TF::RED . "Amount must be positive!");
            return true;
        }

        $availableBalance = $memberData["deposited"] + $memberData["interest"];
        
        if ($amount > $availableBalance) {
            $sender->sendMessage(TF::RED . "You can't withdraw more than your balance!");
            $sender->sendMessage(TF::GRAY . "Your balance: $" . number_format($availableBalance));
            $sender->sendMessage(TF::GRAY . "(Deposits: $" . number_format($memberData["deposited"]) . " + Interest: $" . number_format($memberData["interest"]) . ")");
            return true;
        }

        $clanData = $this->clans->get($clanName);
        
        if ($amount > $clanData["bank"]) {
            $sender->sendMessage(TF::RED . "Clan bank doesn't have enough money!");
            return true;
        }

        $this->economy->addMoney($playerName, $amount);

        if ($amount <= $memberData["interest"]) {
            $memberData["interest"] -= $amount;
        } else {
            $remaining = $amount - $memberData["interest"];
            $memberData["interest"] = 0;
            $memberData["deposited"] -= $remaining;
        }
        
        $this->members->set($playerName, $memberData);
        $this->members->save();

        $clanData["bank"] -= $amount;
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $sender->sendMessage(TF::GREEN . "✓ Withdrew $" . number_format($amount) . " from clan bank!");
        $sender->sendMessage(TF::GRAY . "New balance: $" . number_format($this->economy->getMoney($playerName)));

        return true;
    }

    private function checkBalance(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $total = $memberData["deposited"] + $memberData["interest"];

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Your Clan Balance ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "Deposits: " . TF::GREEN . "$" . number_format($memberData["deposited"]));
        $sender->sendMessage(TF::YELLOW . "Interest Earned: " . TF::GREEN . "$" . number_format($memberData["interest"]));
        $sender->sendMessage(TF::YELLOW . "Total Available: " . TF::GREEN . "$" . number_format($total));
        $sender->sendMessage(TF::GRAY . "Use /clan withdraw <amount> to withdraw");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function checkClanBank(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Clan Bank ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "Total Bank: " . TF::GREEN . "$" . number_format($clanData["bank"]));
        $sender->sendMessage(TF::GRAY . "Interest Rate: 2% daily");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function clanInfo(Player $sender, array $args): bool {
        $playerName = $sender->getName();
        
        if (count($args) > 1) {
            $clanName = $args[1];
            if (!$this->clans->exists($clanName)) {
                $sender->sendMessage(TF::RED . "Clan not found!");
                return true;
            }
        } else {
            if (!$this->members->exists($playerName)) {
                $sender->sendMessage(TF::RED . "You're not in a clan! Specify clan name.");
                return true;
            }
            $memberData = $this->members->get($playerName);
            $clanName = $memberData["clan"];
        }

        $clanData = $this->clans->get($clanName);
        $created = date("Y-m-d", $clanData["created"]);

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Clan Info ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "Name: " . TF::AQUA . $clanName);
        $sender->sendMessage(TF::YELLOW . "Tag: " . TF::WHITE . "[" . $clanData["tag"] . "]");
        $sender->sendMessage(TF::YELLOW . "Leader: " . TF::WHITE . $clanData["leader"]);
        $sender->sendMessage(TF::YELLOW . "Members: " . TF::WHITE . count($clanData["members"]) . "/20");
        $sender->sendMessage(TF::YELLOW . "Bank: " . TF::GREEN . "$" . number_format($clanData["bank"]));
        $sender->sendMessage(TF::YELLOW . "Created: " . TF::GRAY . $created);
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function listClans(Player $sender): bool {
        $clans = $this->clans->getAll();
        
        if (empty($clans)) {
            $sender->sendMessage(TF::YELLOW . "No clans exist yet!");
            return true;
        }

        usort($clans, function($a, $b) {
            $bankA = is_array($a) && isset($a["bank"]) ? (int)$a["bank"] : 0;
            $bankB = is_array($b) && isset($b["bank"]) ? (int)$b["bank"] : 0;
            return $bankB - $bankA;
        });

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Top Clans ━━━━━━━");
        $i = 1;
        foreach (array_slice(array_keys($clans), 0, 10) as $clanName) {
            $data = $clans[$clanName];
            $sender->sendMessage(TF::YELLOW . "$i. " . TF::AQUA . $clanName . TF::GRAY . " ($" . number_format($data["bank"]) . ")");
            $i++;
        }
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function listMembers(Player $sender): bool {
        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Clan Members ━━━━━━━");
        
        $sender->sendMessage(TF::YELLOW . "Leader:");
        $sender->sendMessage(TF::WHITE . "  • " . $clanData["leader"]);
        
        if (!empty($clanData["officers"])) {
            $sender->sendMessage(TF::YELLOW . "Officers:");
            foreach ($clanData["officers"] as $officer) {
                $sender->sendMessage(TF::WHITE . "  • " . $officer);
            }
        }
        
        $members = array_diff($clanData["members"], [$clanData["leader"]], $clanData["officers"] ?? []);
        if (!empty($members)) {
            $sender->sendMessage(TF::YELLOW . "Members:");
            foreach ($members as $member) {
                $sender->sendMessage(TF::WHITE . "  • " . $member);
            }
        }
        
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function toggleClanChat(Player $sender): bool {
        $sender->sendMessage(TF::YELLOW . "Clan chat feature coming soon!");
        return true;
    }

    private function setClanHome(Player $sender): bool {
        $sender->sendMessage(TF::YELLOW . "Clan home feature coming soon!");
        return true;
    }

    private function teleportClanHome(Player $sender): bool {
        $sender->sendMessage(TF::YELLOW . "Clan home feature coming soon!");
        return true;
    }

    private function setClanTag(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /clan settag <tag>");
            return true;
        }

        $playerName = $sender->getName();
        
        if (!$this->members->exists($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a clan!");
            return true;
        }

        $memberData = $this->members->get($playerName);
        
        if ($memberData["role"] !== "Leader") {
            $sender->sendMessage(TF::RED . "Only the leader can change the clan tag!");
            return true;
        }

        $tag = $args[1];
        
        if (strlen($tag) > 4) {
            $sender->sendMessage(TF::RED . "Tag must be 4 characters or less!");
            return true;
        }

        $clanName = $memberData["clan"];
        $clanData = $this->clans->get($clanName);
        $clanData["tag"] = $tag;
        $this->clans->set($clanName, $clanData);
        $this->clans->save();

        $sender->sendMessage(TF::GREEN . "✓ Clan tag set to: [" . $tag . "]");

        return true;
    }

    public function applyDailyInterest(): void {
        $allMembers = $this->members->getAll();
        
        foreach ($allMembers as $playerName => $data) {
            if ($data["deposited"] > 0) {
                $interest = (int)($data["deposited"] * self::DAILY_INTEREST_RATE);
                $data["interest"] += $interest;
                $this->members->set($playerName, $data);
                
                $clanData = $this->clans->get($data["clan"]);
                $clanData["bank"] += $interest;
                $this->clans->set($data["clan"], $clanData);
            }
        }
        
        $this->members->save();
        $this->clans->save();
        
        $this->getLogger()->info("Applied daily 2% interest to all clan deposits!");
    }

    private function sendHelp(Player $sender): void {
        $sender->sendMessage(TF::GOLD . "━━━━━━━ Clan Commands ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "/clan create <n>" . TF::GRAY . " - Create clan");
        $sender->sendMessage(TF::YELLOW . "/clan disband" . TF::GRAY . " - Disband clan");
        $sender->sendMessage(TF::YELLOW . "/clan invite <p>" . TF::GRAY . " - Invite player");
        $sender->sendMessage(TF::YELLOW . "/clan accept" . TF::GRAY . " - Accept invite");
        $sender->sendMessage(TF::YELLOW . "/clan leave" . TF::GRAY . " - Leave clan");
        $sender->sendMessage(TF::YELLOW . "/clan kick <p>" . TF::GRAY . " - Kick member");
        $sender->sendMessage(TF::YELLOW . "/clan promote <p>" . TF::GRAY . " - Promote to officer");
        $sender->sendMessage(TF::YELLOW . "/clan demote <p>" . TF::GRAY . " - Demote officer");
        $sender->sendMessage(TF::YELLOW . "/clan deposit <$>" . TF::GRAY . " - Deposit money");
        $sender->sendMessage(TF::YELLOW . "/clan withdraw <$>" . TF::GRAY . " - Withdraw money");
        $sender->sendMessage(TF::YELLOW . "/clan balance" . TF::GRAY . " - Your balance");
        $sender->sendMessage(TF::YELLOW . "/clan bank" . TF::GRAY . " - Clan bank total");
        $sender->sendMessage(TF::YELLOW . "/clan info [clan]" . TF::GRAY . " - Clan info");
        $sender->sendMessage(TF::YELLOW . "/clan list" . TF::GRAY . " - Top clans");
        $sender->sendMessage(TF::YELLOW . "/clan members" . TF::GRAY . " - List members");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    protected function onDisable(): void {
        $this->clans->save();
        $this->members->save();
        $this->deposits->save();
    }
}

class InterestTask extends Task {
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function onRun(): void {
        $this->plugin->applyDailyInterest();
    }
}
