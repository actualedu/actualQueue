╭─── Claude Code v2.1.44 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╮
│                                              │ Tips for getting started                                                                                     │
│               Welcome back Gio!              │ Run /init to create a CLAUDE.md file with instructions for Claude                                            │
│                                              │ ─────────────────────────────────────────────────────────────────                                            │
│                                              │ Recent activity                                                                                              │
│                   ▗ ▗   ▖ ▖                  │ No recent activity                                                                                           │
│                                              │                                                                                                              │
│                     ▘▘ ▝▝                    │                                                                                                              │
│            Opus 4.6 · Claude Pro             │                                                                                                              │
│   ~/Dropbox/claudeCode/submitWebsite_feb16   │                                                                                                              │
╰─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╯
╭─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────╮
│ Plan to implement                                                                                                                                           │
│                                                                                                                                                             │
│ Lottery Ticket Queue with Spinner Wheel                                                                                                                     │
│                                                                                                                                                             │
│ Context                                                                                                                                                     │
│                                                                                                                                                             │
│ The question submission queue fills up early during livestreams, then students stop submitting because strict FIFO means late submissions won't be reached. │
│  We're adding a lottery ticket system with a visual spinning pie chart wheel that the admin triggers to pick a winner. Time in queue earns tickets (more    │
│ time = bigger wedge = better odds), but anyone can win. The existing admin controls (Mark Done, Delete #, Post #) stay unchanged — the spinner is an        │
│ additional "Choose Winner" button.                                                                                                                          │
│                                                                                                                                                             │
│ How It Works (User Flow)                                                                                                                                    │
│                                                                                                                                                             │
│ 1. Students submit questions as usual. The public queue page (stats.php) shows all entries with their ticket count, odds percentage, and a visual ticket    │
│ bar — so students can see their odds growing over time.                                                                                                     │
│ 2. Admin clicks "Choose Winner" in admin.php.                                                                                                               │
│ 3. This writes a spin event to logs/spin.json containing the pre-determined winner (picked server-side with weighted random) and all entries with their     │
│ ticket counts.                                                                                                                                              │
│ 4. The public queue page polls for spin events. When it detects one, it renders a spinning pie chart wheel (colored wedges sized by tickets, ~6-10 seconds  │
│ of spin, decelerates to land on the winner).                                                                                                                │
│ 5. After the wheel stops, the winner gets a gold star next to their name on both the public queue and admin panel, and is moved to position #1 in the       │
│ queue.                                                                                                                                                      │
│ 6. Admin processes the winner whenever ready using existing controls (Mark Done / Post # / Delete #).                                                       │
│ 7. Once the starred winner is removed from the queue, the admin can spin again for a new winner.                                                            │
│                                                                                                                                                             │
│ Ticket Formula (Moderate Randomness)                                                                                                                        │
│                                                                                                                                                             │
│ tickets(entry) = 10 + floor(age_in_minutes * 2)                                                                                                             │
│                                                                                                                                                             │
│                                                                                                                                                             │
│ - New submission: 10 tickets                                                                                                                                │
│ - After 5 min: ~20 tickets                                                                                                                                  │
│ - After 30 min: ~70 tickets (3.5x more likely than a fresh entry — real advantage, not guaranteed)                                                          │
│                                                                                                                                                             │
│ Files to Modify                                                                                                                                             │
│                                                                                                                                                             │
│ 1. admin.php — Add "Choose Winner" button                                                                                                                   │
│                                                                                                                                                             │
│ New button in the admin action row (alongside existing Mark Done / Clear All / Post All):                                                                   │
│ - "Choose Winner" button (disabled if a winner is already starred and still in queue)                                                                       │
│ - Server-side handler (action === 'choose_winner'):                                                                                                         │
│   - Runs lottery_pick($arr) to select a winner index using weighted random                                                                                  │
│   - Marks the winner in submissions.json by setting $arr[$idx]['winner'] = true                                                                             │
│   - Moves the winner to $arr[0] (first position)                                                                                                            │
│   - Writes logs/spin.json with: { "ts": now, "winner_username": "...", "winner_index": original_idx, "entries": [ {username, tickets, ts}... ] }            │
│   - Saves updated submissions.json                                                                                                                          │
│   - Success message: "Spinning for winner... Drew Username (had 70 tickets, 34% chance)!"                                                                   │
│                                                                                                                                                             │
│ Admin JS changes:                                                                                                                                           │
│ - Show a gold star next to the winner entry in the preview table                                                                                            │
│ - Compute and display ticket count + odds columns in the table                                                                                              │
│ - Disable "Choose Winner" button when a winner: true entry exists in the queue                                                                              │
│                                                                                                                                                             │
│ Keep all existing controls untouched: Mark Done (oldest), Delete #, Post #, Clear All, Post All.                                                            │
│                                                                                                                                                             │
│ 2. stats.php — Lottery board + spinning wheel                                                                                                               │
│                                                                                                                                                             │
│ Replace the plain ordered list with a lottery board display:                                                                                                │
│                                                                                                                                                             │
│ Each entry shows:                                                                                                                                           │
│                                                                                                                                                             │
│ [colored ticket bar]  ⭐ Username  |  72 tickets  |  34%                                                                                                    │
│                                                                                                                                                             │
│                                                                                                                                                             │
│ - Bar width proportional to tickets / totalTickets                                                                                                          │
│ - Gold star only on the current winner                                                                                                                      │
│ - Sorted by ticket count (most first), except winner is always at top                                                                                       │
│ - Subtitle: "Earn more tickets the longer you wait! The streamer draws a winner from the lottery."                                                          │
│                                                                                                                                                             │
│ Pie chart spinner wheel:                                                                                                                                    │
│ - Rendered as a <canvas> element, hidden by default                                                                                                         │
│ - Each entry is a wedge: size = tickets / totalTickets * 360°, distinct colors per entry, username labels on wedges                                         │
│ - When a spin event is detected:                                                                                                                            │
│   a. Overlay the wheel on the page (centered, semi-transparent backdrop)                                                                                    │
│   b. Spin animation: fast rotation decelerating over ~8 seconds via requestAnimationFrame, easing to stop on the pre-determined winner's wedge              │
│   c. Landing: winner's wedge highlighted, brief pause, then wheel fades out                                                                                 │
│   d. Queue list updates: winner moves to #1 with gold star                                                                                                  │
│                                                                                                                                                             │
│ Polling for spin events:                                                                                                                                    │
│ - Every 3 seconds (existing poll interval), also fetch queue.php?spin=1 to check for new spin events                                                        │
│ - Compare spin event ts to last-seen spin ts; if newer, trigger the wheel animation                                                                         │
│ - After animation completes, resume normal polling                                                                                                          │
│                                                                                                                                                             │
│ 3. queue.php — Serve spin event data                                                                                                                        │
│                                                                                                                                                             │
│ Add spin event endpoint:                                                                                                                                    │
│ - GET queue.php?spin=1 returns contents of logs/spin.json (or {} if none exists)                                                                            │
│ - Public access (no auth needed) — the spin data only contains usernames and ticket counts (no IPs)                                                         │
│                                                                                                                                                             │
│ Existing public/admin endpoints stay the same. Add winner to the public safe-fields:                                                                        │
│ 'winner' => !empty($e['winner']),                                                                                                                           │
│                                                                                                                                                             │
│ 4. upload.php — No changes                                                                                                                                  │
│                                                                                                                                                             │
│ Ticket counts are computed dynamically from ts. No new fields stored at submission time.                                                                    │
│                                                                                                                                                             │
│ 5. logs/spin.json — New file (created by admin action)                                                                                                      │
│                                                                                                                                                             │
│ {                                                                                                                                                           │
│   "ts": 1771205999,                                                                                                                                         │
│   "winner_username": "PrimeDavidLaid",                                                                                                                      │
│   "entries": [                                                                                                                                              │
│     {"username": "PrimeDavidLaid", "tickets": 70},                                                                                                          │
│     {"username": "Yrage", "tickets": 52},                                                                                                                   │
│     {"username": "Ethan", "tickets": 20}                                                                                                                    │
│   ]                                                                                                                                                         │
│ }                                                                                                                                                           │
│                                                                                                                                                             │
│ Written by admin.php on "Choose Winner". Read by stats.php via queue.php to trigger the wheel animation.                                                    │
│                                                                                                                                                             │
│ Implementation Details                                                                                                                                      │
│                                                                                                                                                             │
│ lottery_pick() function (admin.php)                                                                                                                         │
│                                                                                                                                                             │
│ function lottery_pick($arr) {                                                                                                                               │
│   $now = time();                                                                                                                                            │
│   $tickets = array();                                                                                                                                       │
│   $totalTickets = 0;                                                                                                                                        │
│   for ($i = 0; $i < count($arr); $i++) {                                                                                                                    │
│     $age_min = max(1, ($now - (int)$arr[$i]['ts']) / 60);                                                                                                   │
│     $t = 10 + (int)floor($age_min * 2);                                                                                                                     │
│     $tickets[$i] = $t;                                                                                                                                      │
│     $totalTickets += $t;                                                                                                                                    │
│   }                                                                                                                                                         │
│   $draw = mt_rand(1, $totalTickets);                                                                                                                        │
│   $running = 0;                                                                                                                                             │
│   for ($i = 0; $i < count($tickets); $i++) {                                                                                                                │
│     $running += $tickets[$i];                                                                                                                               │
│     if ($draw <= $running) {                                                                                                                                │
│       return array('index' => $i, 'tickets' => $tickets[$i], 'total' => $totalTickets, 'all_tickets' => $tickets);                                          │
│     }                                                                                                                                                       │
│   }                                                                                                                                                         │
│   return array('index' => 0, 'tickets' => $tickets[0], 'total' => $totalTickets, 'all_tickets' => $tickets);                                                │
│ }                                                                                                                                                           │
│                                                                                                                                                             │
│ Pie chart wheel (stats.php canvas)                                                                                                                          │
│                                                                                                                                                             │
│ - Distinct colors per wedge (cycle through a palette of 10-12 colors)                                                                                       │
│ - Username drawn along each wedge (radially for large wedges, omitted for tiny ones)                                                                        │
│ - Fixed pointer/arrow at the top of the wheel                                                                                                               │
│ - Spin physics: start at high angular velocity, apply easing deceleration (cubic-bezier style), land on pre-calculated angle for the winner's wedge         │
│ - The winner angle is calculated from the spin data so the wheel always lands correctly regardless of the random spin animation                             │
│                                                                                                                                                             │
│ Client-side ticket computation (shared JS)                                                                                                                  │
│                                                                                                                                                             │
│ function computeTickets(tsUnix) {                                                                                                                           │
│   var ageMin = Math.max(1, (Date.now()/1000 - (tsUnix || 0)) / 60);                                                                                         │
│   return 10 + Math.floor(ageMin * 2);                                                                                                                       │
│ }                                                                                                                                                           │
│                                                                                                                                                             │
│ Verification                                                                                                                                                │
│                                                                                                                                                             │
│ 1. Submit 3-4 test images at staggered times (a few minutes apart)                                                                                          │
│ 2. Check stats.php — verify ticket bars, counts, and percentages display correctly; verify they grow on each 3s refresh                                     │
│ 3. Admin: click "Choose Winner" — verify spin.json is written; verify the winner gets winner: true in submissions.json and is moved to position #1          │
│ 4. Check stats.php after spin — verify the wheel animation plays (~8s), lands on the correct winner, then the winner shows at top with a gold star          │
│ 5. Admin: Mark Done the winner — verify winner is removed; verify "Choose Winner" button becomes enabled again                                              │
│ 6. Edge cases:                                                                                                                                              │
│   - Queue with 1 entry: wheel has one wedge, spins and picks them                                                                                           │
│   - Queue with 2 entries of similar age: wheel wedges roughly equal                                                                                         │
│   - Spin while stats.php is open: animation should trigger within a few seconds                                                                             │
│   - Admin clicks spin, then refreshes admin page: winner star should persist (it's in submissions.json)                                                     │
