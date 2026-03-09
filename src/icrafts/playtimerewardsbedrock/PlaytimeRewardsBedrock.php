<?php

declare(strict_types=1);

namespace icrafts\playtimerewardsbedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use function array_filter;
use function array_key_exists;
use function array_rand;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function max;
use function min;
use function mt_rand;
use function strtolower;
use function trim;

final class PlaytimeRewardsBedrock extends PluginBase implements Listener
{
    private const PLAYERS_KEY = "players";

    private Config $players;

    /** @var array<string, int> */
    private array $unusedMinutes = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->saveResource("players.yml");

        $this->players = new Config(
            $this->getDataFolder() . "players.yml",
            Config::YAML,
            [self::PLAYERS_KEY => []],
        );

        $raw = $this->players->get(self::PLAYERS_KEY, []);
        $this->unusedMinutes = is_array($raw)
            ? $this->normalizePlayerMap($raw)
            : [];

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function (): void {
                $this->tickPlaytime();
            }),
            20 * 60,
        );
    }

    public function onDisable(): void
    {
        $this->savePlayerData();
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $name = $this->normalizeName($event->getPlayer()->getName());
        if (!array_key_exists($name, $this->unusedMinutes)) {
            $this->unusedMinutes[$name] = 0;
            $this->savePlayerData();
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $this->savePlayerData();
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        $name = strtolower($command->getName());

        return match ($name) {
            "playtime", "pt" => $this->handlePlaytime($sender),
            "getreward", "gr" => $this->handleGetReward($sender, $args),
            "addreward", "ar" => $this->handleAddReward($sender, $args),
            "removereward", "rr" => $this->handleRemoveReward($sender, $args),
            "prhardmode" => $this->handleHardmode($sender, $args),
            default => false,
        };
    }

    private function handlePlaytime(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command is player-only.");
            return true;
        }
        $minutes = $this->getUnusedMinutes($sender->getName());
        $this->msg($sender, "playtime", ["{minutes}" => (string) $minutes]);
        return true;
    }

    private function handleGetReward(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command is player-only.");
            return true;
        }

        $minutesPerReward = max(
            1,
            (int) $this->getConfig()->get("time_in_minutes", 60),
        );
        $current = $this->getUnusedMinutes($sender->getName());
        $amount = 1;

        if ($args !== []) {
            $first = strtolower((string) $args[0]);
            if ($first === "all") {
                $amount = intdiv($current, $minutesPerReward);
            } else {
                $amount = (int) $first;
            }
        }

        if ($amount < 1) {
            $this->msg($sender, "amount_low");
            return true;
        }

        $needed = $minutesPerReward * $amount;
        if ($current < $needed) {
            $this->msg($sender, "not_enough", [
                "{have}" => (string) $current,
                "{need}" => (string) $needed,
            ]);
            return true;
        }

        $rewards = $this->getRewardsForCurrentStage();
        if ($rewards === []) {
            $this->msg($sender, "no_rewards_stage");
            return true;
        }

        for ($i = 0; $i < $amount; $i++) {
            $reward = $rewards[(int) array_rand($rewards)];
            $this->giveReward($sender, $reward);
            $current -= $minutesPerReward;
        }

        $this->setUnusedMinutes($sender->getName(), max(0, $current));
        $this->msg($sender, "reward_received");
        return true;
    }

    private function handleAddReward(CommandSender $sender, array $args): bool
    {
        if (count($args) < 5) {
            $this->msg($sender, "syntax_add");
            return true;
        }

        $item = trim((string) $args[0]);
        if (!$this->isValidItem($item)) {
            $sender->sendMessage(TextFormat::RED . "Invalid item id or name.");
            return true;
        }

        $amount = (int) $args[1];
        if ($amount < 1) {
            $sender->sendMessage(TextFormat::RED . "Amount must be >= 1.");
            return true;
        }

        $isRandom = $this->parseBool((string) $args[2]);
        $isPreHm = $this->parseBool((string) $args[3]);
        $isHm = $this->parseBool((string) $args[4]);
        if ($isRandom === null || $isPreHm === null || $isHm === null) {
            $sender->sendMessage(
                TextFormat::RED . "Use true/false for boolean flags.",
            );
            return true;
        }

        $rewards = $this->getConfig()->get("rewards", []);
        if (!is_array($rewards)) {
            $rewards = [];
        }
        $rewards[] = [
            "item" => $item,
            "amount" => $amount,
            "is_amount_random" => $isRandom,
            "is_pre_hardmode" => $isPreHm,
            "is_hardmode" => $isHm,
        ];
        $this->getConfig()->set("rewards", $rewards);
        $this->getConfig()->save();

        $this->msg($sender, "reward_added", [
            "{item}" => $item,
            "{amount}" => (string) $amount,
        ]);
        return true;
    }

    private function handleRemoveReward(
        CommandSender $sender,
        array $args,
    ): bool {
        if (count($args) < 1) {
            $this->msg($sender, "syntax_remove");
            return true;
        }

        $needle = strtolower(trim((string) $args[0]));
        if ($needle === "") {
            $this->msg($sender, "syntax_remove");
            return true;
        }

        $rewards = $this->getConfig()->get("rewards", []);
        if (!is_array($rewards)) {
            $rewards = [];
        }

        $kept = [];
        $removed = 0;
        foreach ($rewards as $reward) {
            if (!is_array($reward)) {
                continue;
            }
            $item = strtolower((string) ($reward["item"] ?? ""));
            if ($item === $needle) {
                $removed++;
                continue;
            }
            $kept[] = $reward;
        }

        if ($removed === 0) {
            $this->msg($sender, "reward_not_found", ["{item}" => $needle]);
            return true;
        }

        $this->getConfig()->set("rewards", $kept);
        $this->getConfig()->save();
        $this->msg($sender, "reward_removed", ["{count}" => (string) $removed]);
        return true;
    }

    private function handleHardmode(CommandSender $sender, array $args): bool
    {
        $raw = $args[0] ?? "status";
        $mode = strtolower((string) $raw);
        if (!in_array($mode, ["on", "off", "status"], true)) {
            $sender->sendMessage("Usage: /prhardmode <on|off|status>");
            return true;
        }

        if ($mode === "status") {
            $value = $this->isHardmodeEnabled() ? "on" : "off";
            $this->msg($sender, "hardmode_status", ["{value}" => $value]);
            return true;
        }

        $next = $mode === "on";
        $prev = $this->isHardmodeEnabled();
        $this->getConfig()->set("hardmode_enabled", $next);
        $this->getConfig()->save();

        if (!$prev && $next) {
            $multiplier = (float) $this->getConfig()->get(
                "switch_to_hm_multiplier",
                0.3,
            );
            $this->applyHardmodeMultiplier($multiplier);
        }

        $this->msg($sender, "hardmode_set", [
            "{value}" => $next ? "on" : "off",
        ]);
        return true;
    }

    private function tickPlaytime(): void
    {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $name = $this->normalizeName($player->getName());
            $this->unusedMinutes[$name] =
                ($this->unusedMinutes[$name] ?? 0) + 1;
        }
        $this->savePlayerData();
    }

    private function applyHardmodeMultiplier(float $multiplier): void
    {
        if ($multiplier <= 0) {
            foreach ($this->unusedMinutes as $name => $_minutes) {
                $this->unusedMinutes[$name] = 0;
            }
            $this->savePlayerData();
            return;
        }

        foreach ($this->unusedMinutes as $name => $minutes) {
            $this->unusedMinutes[$name] = max(
                0,
                (int) ($minutes * $multiplier),
            );
        }
        $this->savePlayerData();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRewardsForCurrentStage(): array
    {
        $isHm = $this->isHardmodeEnabled();
        $all = $this->getConfig()->get("rewards", []);
        if (!is_array($all)) {
            return [];
        }

        $filtered = array_filter($all, static function (mixed $reward) use (
            $isHm,
        ): bool {
            if (!is_array($reward)) {
                return false;
            }
            $pre = (bool) ($reward["is_pre_hardmode"] ?? false);
            $hm = (bool) ($reward["is_hardmode"] ?? false);
            return $isHm ? $hm : $pre;
        });

        $output = [];
        foreach ($filtered as $reward) {
            if (is_array($reward)) {
                $output[] = $reward;
            }
        }
        return $output;
    }

    /**
     * @param array<string, mixed> $reward
     */
    private function giveReward(Player $player, array $reward): void
    {
        $type = strtolower((string) ($reward["type"] ?? "item"));
        if ($type === "command") {
            $this->executeRewardCommand($player, $reward);
            return;
        }
        $this->giveRewardItem($player, $reward);
    }

    /**
     * @param array<string, mixed> $reward
     */
    private function executeRewardCommand(Player $player, array $reward): void
    {
        $template = trim((string) ($reward["command"] ?? ""));
        if ($template === "") {
            return;
        }

        $cmd = str_replace(
            ["{player}", "{display_name}"],
            [$player->getName(), $player->getDisplayName()],
            ltrim($template, "/"),
        );
        $this->getServer()->dispatchCommand(
            $this->getServer()->getConsoleSender(),
            $cmd,
        );
    }

    /**
     * @param array<string, mixed> $reward
     */
    private function giveRewardItem(Player $player, array $reward): void
    {
        $itemId = trim((string) ($reward["item"] ?? ""));
        if ($itemId === "") {
            return;
        }

        $item = StringToItemParser::getInstance()->parse($itemId);
        if ($item === null) {
            return;
        }

        $amount = (int) ($reward["amount"] ?? 1);
        $amount = max(1, min(64, $amount));
        $random = (bool) ($reward["is_amount_random"] ?? false);
        $count = $random ? mt_rand(1, $amount) : $amount;

        $toGive = clone $item;
        $toGive->setCount($count);
        $leftovers = $player->getInventory()->addItem($toGive);
        foreach ($leftovers as $leftover) {
            $player->getWorld()->dropItem($player->getLocation(), $leftover);
        }
    }

    private function getUnusedMinutes(string $name): int
    {
        $n = $this->normalizeName($name);
        return $this->unusedMinutes[$n] ?? 0;
    }

    private function setUnusedMinutes(string $name, int $value): void
    {
        $n = $this->normalizeName($name);
        $this->unusedMinutes[$n] = max(0, $value);
        $this->savePlayerData();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int>
     */
    private function normalizePlayerMap(array $input): array
    {
        $result = [];
        foreach ($input as $name => $minutes) {
            if (!is_string($name)) {
                continue;
            }
            $result[$this->normalizeName($name)] = is_numeric($minutes)
                ? max(0, (int) $minutes)
                : 0;
        }
        return $result;
    }

    private function savePlayerData(): void
    {
        $this->players->set(self::PLAYERS_KEY, $this->unusedMinutes);
        $this->players->save();
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }

    private function isHardmodeEnabled(): bool
    {
        return (bool) $this->getConfig()->get("hardmode_enabled", false);
    }

    private function isValidItem(string $item): bool
    {
        return StringToItemParser::getInstance()->parse($item) !== null;
    }

    private function parseBool(string $value): ?bool
    {
        $v = strtolower(trim($value));
        if (in_array($v, ["true", "1", "yes", "on"], true)) {
            return true;
        }
        if (in_array($v, ["false", "0", "no", "off"], true)) {
            return false;
        }
        return null;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function msg(
        CommandSender $sender,
        string $key,
        array $replacements = [],
    ): void {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = (string) $this->getConfig()->getNested(
            "messages." . $key,
            $key,
        );
        foreach ($replacements as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        $sender->sendMessage(TextFormat::colorize($prefix . $text));
    }
}
