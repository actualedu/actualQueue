<?php
header('X-Content-Type-Options: nosniff');
$build_version = '2026-03-05.01';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Office Hours Submissions Queue</title>
<style>
  :root{
    --bg:#070d1a;
    --card:#121a32;
    --card-2:#0e152b;
    --text:#e9eefc;
    --muted:#9fb0d6;
    --accent:#63d7ff;
    --gold:#f9c74f;
    --header-h:86px;
  }
  html,body{height:100%;}
  body{
    margin:0;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    color:var(--text);
    background:
      radial-gradient(circle at 20% 10%, rgba(99,215,255,.13), transparent 38%),
      radial-gradient(circle at 90% 0%, rgba(249,199,79,.12), transparent 32%),
      linear-gradient(180deg, #070d1a, #0b1324 55%, #080f1f);
  }

  .header{
    position:fixed; top:0; left:0; right:0;
    height:var(--header-h);
    display:grid;
    grid-template-columns:1fr auto 1fr;
    align-items:center;
    padding:0 16px;
    z-index:10;
    background:linear-gradient(180deg, rgba(11,19,36,.95), rgba(11,19,36,.78));
    box-shadow:0 8px 22px rgba(0,0,0,.35);
  }
  @supports ((backdrop-filter: blur(2px)) or (-webkit-backdrop-filter: blur(2px))){
    .header{
      backdrop-filter:saturate(110%) blur(4px);
      -webkit-backdrop-filter:saturate(110%) blur(4px);
    }
  }
  .header h1{ grid-column:2; margin:0; font-size:clamp(20px, 3.3vw, 30px); }
  .count-wrap{
    grid-column:3;
    justify-self:end;
    color:var(--muted);
    display:flex;
    align-items:baseline;
    gap:8px;
  }
  .count{
    color:var(--accent);
    font-weight:800;
    font-size:clamp(24px, 4.5vw, 40px);
    font-variant-numeric:tabular-nums;
    min-width:3ch;
    text-align:right;
  }

  .page{ padding-top:calc(var(--header-h) + 18px); display:flex; justify-content:center; }
  .wrap{
    width:min(980px, 94vw);
    background:linear-gradient(180deg, rgba(18,26,50,.95), rgba(14,21,43,.95));
    border:1px solid rgba(255,255,255,.08);
    border-radius:16px;
    padding:18px;
    box-shadow:0 18px 30px rgba(0,0,0,.35);
  }
  .sub{ color:var(--muted); font-size:14px; margin-bottom:12px; }
  .board{ display:flex; flex-direction:column; gap:9px; max-height:min(70vh, 640px); overflow:auto; }
  .row{
    border:1px solid rgba(255,255,255,.08);
    border-radius:12px;
    background:rgba(9,14,30,.82);
    padding:10px 12px;
    position:relative;
    overflow:hidden;
  }
  .vote-bar{
    position:absolute; left:0; top:0; bottom:0;
    background:linear-gradient(90deg, rgba(99,215,255,.22), rgba(99,215,255,.04));
    pointer-events:none;
  }
  .row-inner{ position:relative; z-index:1; display:flex; justify-content:space-between; gap:10px; align-items:center; }
  .name{ font-weight:700; display:flex; align-items:center; gap:8px; }
  .name .star{ color:var(--gold); text-shadow:0 0 10px rgba(249,199,79,.5); }
  .posted-wrap{ display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .posted{ color:var(--muted); font-size:12px; font-weight:500; }
  .queue-minutes{
    color:#062133;
    background:linear-gradient(180deg, rgba(99,215,255,.95), rgba(99,215,255,.78));
    border:1px solid rgba(99,215,255,.55);
    border-radius:999px;
    padding:2px 9px;
    font-size:12px;
    font-weight:800;
    letter-spacing:.1px;
    box-shadow:0 0 0 1px rgba(4,12,28,.24) inset;
    white-space:nowrap;
  }
  .meta{ color:var(--muted); font-size:13px; white-space:nowrap; display:flex; align-items:center; gap:10px; }
  .empty{ color:var(--muted); padding:16px 10px; }
  a.name-link{ color:inherit; text-decoration:none; }
  .upvote-btn{
    border:1px solid rgba(99,215,255,.45);
    color:#dff6ff;
    background:rgba(99,215,255,.14);
    border-radius:8px;
    padding:5px 10px;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
  }
  .upvote-btn[disabled]{ opacity:.55; cursor:not-allowed; }
  .winner-worked{ color:var(--gold); font-size:12px; font-weight:700; white-space:nowrap; }
  .footer-meta{
    margin-top:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
  }
  .updated-at{
    color:rgba(159,176,214,.75);
    font-size:11px;
    letter-spacing:.2px;
  }
  .build-version{
    text-align:right;
    color:rgba(159,176,214,.65);
    font-size:11px;
    letter-spacing:.2px;
  }

  .wheel-overlay{
    position:fixed; inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(3,6,14,.78);
    z-index:50;
  }
  .wheel-shell{ position:relative; width:min(540px, 92vw); }
  #wheelCanvas{ width:100%; height:auto; display:block; }
  .wheel-pointer{
    position:absolute;
    top:-6px;
    left:50%;
    transform:translateX(-50%);
    width:0; height:0;
    border-left:14px solid transparent;
    border-right:14px solid transparent;
    border-top:24px solid #fefefe;
    filter:drop-shadow(0 2px 2px rgba(0,0,0,.45));
  }
  .wheel-status{
    margin-top:14px;
    text-align:center;
    color:#d5e4ff;
    font-weight:600;
    min-height:1.4em;
  }

  @media (max-width:640px){
    .meta{ font-size:12px; }
    .row-inner{ flex-direction:column; align-items:flex-start; }
  }
</style>
</head>
<body>
  <header class="header" role="banner" aria-live="polite">
    <h1>Office Hours Submissions Queue</h1>
    <div class="count-wrap">
      <span>Total</span>
      <span class="count" id="count">0</span>
    </div>
  </header>

  <main class="page" role="main">
    <section class="wrap">
      <div class="sub">Each question can be upvoted once every 3 minutes. Questions gain votes over time, so those waiting are more likely to be picked.</div>
      <div class="board" id="board" aria-live="polite"></div>
      <div class="footer-meta">
        <div class="updated-at" id="updated">Updated --</div>
        <div class="build-version">build <?php echo htmlspecialchars($build_version); ?></div>
      </div>
    </section>
  </main>

  <div class="wheel-overlay" id="wheelOverlay" aria-hidden="true">
    <div class="wheel-shell">
      <div class="wheel-pointer"></div>
      <canvas id="wheelCanvas" width="520" height="520"></canvas>
      <div class="wheel-status" id="wheelStatus"></div>
    </div>
  </div>

<script>
function toUrl(p){
  if(!p) return '#';
  p = String(p).replace(/\\/g, '/');
  try { if (new URL(p).protocol.indexOf('http') === 0) return p; } catch(e){}

  // Build app-relative URLs from wherever stats.php is hosted.
  var dir = '/';
  var pathname = window.location.pathname || '/';
  var slashIdx = pathname.lastIndexOf('/');
  if (slashIdx >= 0) dir = pathname.slice(0, slashIdx + 1);

  if (p.charAt(0) === '/') return p;

  var needle = '/public_html/';
  var idx = p.indexOf(needle);
  if (idx !== -1){
    var rel = p.slice(idx + needle.length).replace(/^\/+/, '');
    return '/' + rel;
  }

  var uploadIdx = p.indexOf('/uploadedImages/');
  if (uploadIdx !== -1) {
    var relUpload = p.slice(uploadIdx + 1);
    return '/' + relUpload;
  }

  if (p.indexOf('uploadedImages/') === 0) {
    return dir + p.replace(/^\/+/, '');
  }

  var base = p.split('/').pop();
  return dir + 'uploadedImages/' + encodeURIComponent(base);
}

function computeVotes(entry){
  if (entry && entry.winner) return 0;
  var tsUnix = entry && entry.ts ? entry.ts : 0;
  var ageMin = Math.max(0, (nowUnix() - tsUnix) / 60);
  var baseVotes = 10 + Math.floor(ageMin * 2);
  var upvotes = entry && entry.upvotes ? parseInt(entry.upvotes, 10) : 0;
  if (!(upvotes > 0)) return baseVotes;
  return Math.floor(baseVotes * (1 + Math.log(upvotes + 1)));
}

function formatPostedTime(tsUnix){
  if (!tsUnix) return '';
  return new Date(tsUnix * 1000).toLocaleTimeString();
}

function formatQueueMinutes(tsUnix){
  if (!tsUnix) return '';
  var mins = Math.max(0, Math.floor((nowUnix() - tsUnix) / 60));
  return mins + ' min ago';
}

function safeText(v){ return (v == null ? '' : String(v)).replace(/[<>]/g,''); }

var lastSpinTs = 0;
var spinReady = false;
var isSpinning = false;
var currentEntries = [];
var upvoteBusy = false;
var serverOffsetSec = 0;
var queueFetchInFlight = false;
var spinFetchInFlight = false;
var tickInFlight = false;

function syncServerTimeFromResponse(res){
  if (!res || !res.headers) return;
  var raw = res.headers.get('X-Server-Time');
  var serverTs = raw ? parseInt(raw, 10) : 0;
  if (serverTs > 0) {
    serverOffsetSec = serverTs - (Date.now() / 1000);
  }
}

function nowUnix(){
  return (Date.now() / 1000) + serverOffsetSec;
}

async function fetchJson(url, options, timeoutMs){
  var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  var timeout = null;
  var opts = options || {};
  if (controller) {
    opts.signal = controller.signal;
    timeout = setTimeout(function(){ controller.abort(); }, timeoutMs || 8000);
  }
  try {
    var res = await fetch(url, opts);
    syncServerTimeFromResponse(res);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return await res.json();
  } finally {
    if (timeout) clearTimeout(timeout);
  }
}

function formatCooldown(seconds){
  var s = Math.max(0, seconds || 0);
  var m = Math.floor(s / 60);
  var r = s % 60;
  return m + ':' + (r < 10 ? '0' : '') + r;
}

function formatElapsed(seconds){
  var s = Math.max(0, seconds || 0);
  var h = Math.floor(s / 3600);
  var m = Math.floor((s % 3600) / 60);
  var r = s % 60;
  function pad(n){ return n < 10 ? ('0' + n) : String(n); }
  if (h > 0) return h + ':' + pad(m) + ':' + pad(r);
  return m + ':' + pad(r);
}

function refreshUpvoteButtons(){
  var now = Math.floor(nowUnix());
  var buttons = document.querySelectorAll('.upvote-btn');
  for (var i=0; i<buttons.length; i++) {
    var btn = buttons[i];
    var isWinner = btn.getAttribute('data-winner') === '1';
    var until = parseInt(btn.getAttribute('data-cooldown-until') || '0', 10);
    var cooldown = Math.max(0, until - now);
    var disabled = upvoteBusy || isSpinning || isWinner || cooldown > 0;
    btn.disabled = disabled;
    if (isWinner) {
      btn.textContent = 'Winner';
    } else if (cooldown > 0) {
      btn.textContent = 'Wait ' + formatCooldown(cooldown);
    } else {
      btn.textContent = 'Upvote';
    }
  }

  var worked = document.querySelectorAll('.winner-worked[data-winner-since]');
  for (var j=0; j<worked.length; j++) {
    var el = worked[j];
    var since = parseInt(el.getAttribute('data-winner-since') || '0', 10);
    if (since > 0) {
      var elapsed = now - since;
      if (elapsed < 0) elapsed = 0;
      el.textContent = 'Working: ' + formatElapsed(elapsed);
    }
  }
}

function rankedEntries(rawEntries){
  var entries = Array.isArray(rawEntries) ? rawEntries : [];
  var voteTotal = 0;
  for (var i=0; i<entries.length; i++) voteTotal += computeVotes(entries[i]);

  var mapped = [];
  for (var j=0; j<entries.length; j++) {
    var e = entries[j] || {};
    var v = computeVotes(e);
    var winnerSince = 0;
    if (e.winner) {
      var wts = e.winner_ts ? parseInt(e.winner_ts, 10) : 0;
      var sts = lastSpinTs ? parseInt(lastSpinTs, 10) : 0;
      winnerSince = wts > 0 ? wts : sts;
      if (sts > winnerSince) winnerSince = sts;
    }
    mapped.push({
      username: safeText(e.username || 'Anonymous'),
      path: e.path || '',
      ts: e.ts || 0,
      winner: !!e.winner,
      winnerTs: winnerSince,
      upvotes: e.upvotes ? parseInt(e.upvotes, 10) : 0,
      entryKey: e.entry_key || '',
      upvoteCooldownUntil: e.upvote_cooldown_until ? parseInt(e.upvote_cooldown_until, 10) : 0,
      votes: v,
      odds: voteTotal > 0 ? (v * 100 / voteTotal) : 0
    });
  }

  mapped.sort(function(a, b){
    if (a.winner && !b.winner) return -1;
    if (!a.winner && b.winner) return 1;
    if (b.votes !== a.votes) return b.votes - a.votes;
    return (a.ts || 0) - (b.ts || 0);
  });

  return { entries: mapped, totalVotes: voteTotal };
}

function renderBoard(rawEntries){
  currentEntries = Array.isArray(rawEntries) ? rawEntries : [];
  var board = document.getElementById('board');
  var countEl = document.getElementById('count');
  var updatedEl = document.getElementById('updated');
  var rank = rankedEntries(currentEntries);

  countEl.textContent = rank.entries.length.toLocaleString();
  updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();

  if (rank.entries.length === 0) {
    board.innerHTML = '<div class="empty">No submissions yet.</div>';
    return;
  }

  var html = '';
  for (var i=0; i<rank.entries.length; i++) {
    var e = rank.entries[i];
    var width = rank.totalVotes > 0 ? (e.votes * 100 / rank.totalVotes) : 0;
    var href = toUrl(e.path);
    var star = e.winner ? '<span class="star">&#11088;</span>' : '';
    var posted = formatPostedTime(e.ts);
    var queueMins = formatQueueMinutes(e.ts);
    var postedMeta = '';
    if (posted || queueMins) {
      postedMeta = '<span class="posted-wrap">' +
        (posted ? '<span class="posted">Submitted ' + posted + '</span>' : '') +
        (queueMins ? '<span class="queue-minutes">' + queueMins + '</span>' : '') +
      '</span>';
    }
    html += '' +
      '<div class="row">' +
        '<div class="vote-bar" style="width:' + width.toFixed(2) + '%"></div>' +
        '<div class="row-inner">' +
          '<div class="name">' + star + '<a class="name-link" href="' + href + '" target="_blank" rel="noopener noreferrer">' + e.username + '</a>' + postedMeta + '</div>' +
          '<div class="meta"><span>' + e.votes + ' votes | ' + e.odds.toFixed(1) + '%</span><button class="upvote-btn" data-entry-key="' + e.entryKey + '" data-winner="' + (e.winner ? '1' : '0') + '" data-cooldown-until="' + (e.upvoteCooldownUntil || 0) + '">Upvote</button>' + (e.winner ? '<span class="winner-worked" data-winner-since="' + (e.winnerTs || 0) + '"></span>' : '') + '</div>' +
        '</div>' +
      '</div>';
  }
  board.innerHTML = html;
  refreshUpvoteButtons();
}

function drawWheel(ctx, wheelEntries, angle, highlightIndex){
  var w = ctx.canvas.width;
  var h = ctx.canvas.height;
  var cx = w / 2;
  var cy = h / 2;
  var r = Math.min(w, h) * 0.45;
  var total = 0;
  var i;
  var colors = ['#4cc9f0','#4895ef','#4361ee','#3a0ca3','#2a9d8f','#f4a261','#e76f51','#f94144','#90be6d','#577590','#f8961e','#43aa8b'];

  for (i=0; i<wheelEntries.length; i++) total += wheelEntries[i].votes;
  if (total <= 0) return;

  ctx.clearRect(0, 0, w, h);
  ctx.save();
  ctx.translate(cx, cy);

  var cursor = 0;
  for (i=0; i<wheelEntries.length; i++) {
    var frac = wheelEntries[i].votes / total;
    var arc = frac * Math.PI * 2;
    var start = angle + cursor - Math.PI / 2;
    var end = start + arc;

    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.arc(0, 0, r, start, end, false);
    ctx.closePath();
    ctx.fillStyle = (i === highlightIndex) ? '#f9c74f' : colors[i % colors.length];
    ctx.fill();

    ctx.lineWidth = 1.5;
    ctx.strokeStyle = 'rgba(255,255,255,0.3)';
    ctx.stroke();

    if (arc > 0.22) {
      var mid = start + arc / 2;
      var tx = Math.cos(mid) * r * 0.67;
      var ty = Math.sin(mid) * r * 0.67;
      var label = safeText(wheelEntries[i].username || 'Anonymous');
      if (label.length > 14) label = label.slice(0, 14) + '...';
      ctx.save();
      ctx.translate(tx, ty);
      ctx.rotate(mid + Math.PI / 2);
      ctx.fillStyle = 'rgba(255,255,255,0.95)';
      ctx.font = 'bold 13px system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(label, 0, 0);
      ctx.restore();
    }

    cursor += arc;
  }

  ctx.beginPath();
  ctx.arc(0, 0, r * 0.13, 0, Math.PI * 2, false);
  ctx.fillStyle = '#fff';
  ctx.fill();
  ctx.restore();
}

function playSpin(spinData){
  if (!spinData || !Array.isArray(spinData.entries) || spinData.entries.length === 0) return;
  isSpinning = true;

  var overlay = document.getElementById('wheelOverlay');
  var statusEl = document.getElementById('wheelStatus');
  var canvas = document.getElementById('wheelCanvas');
  var ctx = canvas.getContext('2d');
  var wheelEntries = [];

  for (var i=0; i<spinData.entries.length; i++) {
    var se = spinData.entries[i] || {};
    var v = parseInt(se.votes, 10);
    if (v < 1) v = 1;
    wheelEntries.push({
      username: safeText(se.username || 'Anonymous'),
      votes: v
    });
  }

  var winnerIndex = parseInt(spinData.winner_index, 10);
  if (!(winnerIndex >= 0 && winnerIndex < wheelEntries.length)) {
    winnerIndex = -1;
    var wu = safeText(spinData.winner_username || '');
    for (i=0; i<wheelEntries.length; i++) {
      if (wheelEntries[i].username === wu) { winnerIndex = i; break; }
    }
  }
  if (winnerIndex < 0) winnerIndex = 0;

  var total = 0;
  for (i=0; i<wheelEntries.length; i++) total += wheelEntries[i].votes;
  var running = 0;
  for (i=0; i<winnerIndex; i++) running += wheelEntries[i].votes;
  var winnerFracStart = total > 0 ? running / total : 0;
  var winnerFracMid = winnerFracStart + (wheelEntries[winnerIndex].votes / total) / 2;
  var tau = Math.PI * 2;
  var target = ((-winnerFracMid * tau) % tau + tau) % tau;
  var turns = 8 + Math.floor(Math.random() * 3);
  var finalAngle = turns * tau + target;

  overlay.style.display = 'flex';
  overlay.setAttribute('aria-hidden', 'false');
  statusEl.textContent = 'Preparing spin...';

  var start = null;
  var duration = 8000;
  var startAngle = 0;

  function easeOutCubic(t){ return 1 - Math.pow(1 - t, 3); }

  function frame(ts){
    if (!start) start = ts;
    var p = (ts - start) / duration;
    if (p > 1) p = 1;
    var eased = easeOutCubic(p);
    var ang = startAngle + (finalAngle - startAngle) * eased;
    drawWheel(ctx, wheelEntries, ang, p >= 1 ? winnerIndex : -1);
    if (p < 1) {
      requestAnimationFrame(frame);
    } else {
      var winnerName = wheelEntries[winnerIndex] ? wheelEntries[winnerIndex].username : 'Winner';
      statusEl.textContent = 'Winner: ' + winnerName;
      setTimeout(function(){
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        isSpinning = false;
        fetchQueue();
        refreshUpvoteButtons();
      }, 1300);
    }
  }

  drawWheel(ctx, wheelEntries, 0, -1);
  setTimeout(function(){
    statusEl.textContent = 'Spinning...';
    requestAnimationFrame(frame);
  }, 2000);
}

async function fetchQueue(){
  if (isSpinning || queueFetchInFlight) return;
  queueFetchInFlight = true;
  try {
    var data = await fetchJson('queue.php', { cache: 'no-store' }, 7000);
    renderBoard(Array.isArray(data) ? data : []);
  } catch (err) {
    console.error(err);
  } finally {
    queueFetchInFlight = false;
  }
}

async function submitUpvote(entryKey){
  if (!entryKey || upvoteBusy || isSpinning) return;
  upvoteBusy = true;
  refreshUpvoteButtons();
  try {
    var res = await fetch('queue.php?upvote=1', {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entry_key: entryKey })
    });
    syncServerTimeFromResponse(res);
    var payload = await res.json();
    if (payload && payload.ok) {
      await fetchQueue();
    } else if (payload && payload.cooldown_remaining) {
      await fetchQueue();
    }
  } catch (err) {
    console.error(err);
  }
  upvoteBusy = false;
  refreshUpvoteButtons();
}

document.addEventListener('click', function(evt){
  var target = evt.target;
  if (!target || !target.classList || !target.classList.contains('upvote-btn')) return;
  var key = target.getAttribute('data-entry-key') || '';
  submitUpvote(key);
});

async function fetchSpin(){
  if (isSpinning || spinFetchInFlight) return;
  spinFetchInFlight = true;
  try {
    var spin = await fetchJson('queue.php?spin=1', { cache: 'no-store' }, 5000);
    var ts = spin && spin.ts ? parseInt(spin.ts, 10) : 0;
    if (!spinReady) {
      spinReady = true;
      lastSpinTs = ts;
      return;
    }
    if (ts > 0 && ts > lastSpinTs) {
      lastSpinTs = ts;
      playSpin(spin);
    }
  } catch (err) {
    console.error(err);
  } finally {
    spinFetchInFlight = false;
  }
}

async function tick(){
  if (tickInFlight) return;
  tickInFlight = true;
  try {
    if (!document.hidden) {
      await fetchSpin();
      if (!isSpinning) await fetchQueue();
    }
    refreshUpvoteButtons();
  } finally {
    tickInFlight = false;
  }
}

tick();
setInterval(tick, 3000);
setInterval(refreshUpvoteButtons, 1000);
document.addEventListener('visibilitychange', function(){
  if (!document.hidden) tick();
});
</script>
</body>
</html>
