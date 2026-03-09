# PlaytimeRewards

**PlaytimeRewards** is a powerful retention tool for **PocketMine-MP** that rewards players with items, money, or commands based on their total time spent on your server. Keep your players engaged by offering tiered rewards!

## Features

*   **Tiered Rewards:** Set up multiple milestones (e.g., 1 hour, 10 hours, 100 hours).
*   **Command Execution:** Run any console command as a reward (give items, ranks, or special abilities).
*   **Economy Integration:** Directly reward players with currency (requires a compatible Economy plugin).
*   **Playtime Tracking:** Accurate tracking of player sessions and total time.
*   **Custom Notifications:** Send messages or titles to players when they unlock a new reward.
*   **Data Persistence:** Saves player progress reliably using SQLite or YAML.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/playtime` | Check your current total playtime | `playtime.command.view` |
| `/playtime top` | View the leaderboard of most active players | `playtime.command.top` |
| `/ptr reload` | Reload the configuration file | `playtime.admin` |

## Configuration Example

```yaml
# PlaytimeRewards Settings
rewards:
  "1hour":
    time: 3600 # Time in seconds
    message: "§aYou've played for 1 hour! Here is $500."
    commands:
      - "givemoney {player} 500"
      - "give {player} grass 64"
  "veteran":
    time: 86400 # 24 hours
    message: "§6Thank you for being a veteran! Enjoy your new rank."
    commands:
      - "setgroup {player} Veteran"
