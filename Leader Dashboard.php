// [leadership_dashboard]
add_shortcode('leadership_dashboard', function () {
  if (!is_user_logged_in()) return '<p>Please log in to view this dashboard.</p>';
  if (!current_user_can('access_leadership')) return '<p>You do not have permission to view this page.</p>';

  $ajax_url   = esc_url(admin_url('admin-ajax.php'));

  // ---- Disciple page via SLUG (you said slug is "leadership-dashboard"; update if needed) ----
  $disciple_slug = 'disciple-dashboard'; // <-- change if your Disciple page has a different slug
  $drill_url     = esc_url( home_url('/' . trim($disciple_slug, '/') . '/') );

  // Request nonce for the leader AJAX request (defense-in-depth)
  $ld_req_nonce = wp_create_nonce('ld_request');

  ob_start(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Leadership</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  :root{
    --bg:#f7f9fc; --card:#ffffff; --ink:#111827; --muted:#6b7280; --shadow:0 8px 24px rgba(0,0,0,.06);
    --radius:16px; --ring:#111827;

    /* container width (match Disciple) */
    --container-max: 1400px;
    --side-pad: 24px;

    /* calendar palette */
    --g0:#eef2ff; --g1:#c7d2fe; --g2:#93c5fd; --g3:#60a5fa; --g4:#3b82f6; --g5:#1d4ed8;
    --bar:#4561a8; --legend:#0ea5a4;

    /* spacing */
    --cell-gap: 10px;
  }

  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial}

  /* page container (aligned with Disciple wrap) */
  .ld-container{
    max-width: var(--container-max);
    margin:0 auto;
    padding-inline: var(--side-pad);
    padding-block-end:42px;
  }

  /* cards */
  .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 16px 18px;margin-top:16px}
  .title-center{ text-align:center; font-size:20px; font-weight:800; letter-spacing:.2px; }

  /* success insight */
  .insight{
    background:var(--card); border-radius:var(--radius); box-shadow:var(--shadow);
    padding:12px 14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;
  }
  .insight .tag{font-weight:600; letter-spacing:.2px; font-size:15px; color:#0b1324}
  .insight .pill{border:1px solid #e5e7eb; border-radius:999px; padding:6px 10px; background:#fff; font-weight:700}
  .insight .bar{flex:1; min-width:220px; height:8px; background:#f1f5f9; border-radius:999px; overflow:hidden}
  .insight .bar > span{display:block; height:100%; background:linear-gradient(90deg,#a7f3d0,#10b981,#059669); width:0}

  /* leaderboard */
  .leaderbar{display:flex;justify-content:center;align-items:center;margin-bottom:8px}
  .section-title{ text-align:center; font-size:20px; font-weight:800; letter-spacing:.2px; margin:0; }
  .leadergrid{display:grid;grid-template-columns:1fr;gap:10px}
  .row{
    border:1px solid #e5e7eb;border-radius:14px;padding:10px 12px;display:grid;
    grid-template-columns:auto 1fr auto;gap:12px;align-items:center;text-decoration:none;color:inherit;
    transition:box-shadow .15s ease, transform .15s ease, border-color .15s ease;
  }
  .row:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgba(0,0,0,.08);border-color:#d1d5db}
  .avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;background:linear-gradient(135deg,#64748b,#334155)}
  .name{font-weight:700}
  .meta{font-size:12px;color:var(--muted)}
  .bar{height:8px;border-radius:999px;background:#f1f5f9;overflow:hidden}
  .fill{height:100%;background:linear-gradient(90deg,#a5b4fc,#4f46e5)}
  .right{text-align:right}
  .pct{font-weight:800}
  .ratio{font-size:12px;color:#6b7280}

  .badge{font-size:18px; display:inline-flex; align-items:center; justify-content:center; border-radius:999px; border:1px solid #e5e7eb; padding:6px 12px; background:#fff}
  .badge.ok{color:#065f46;border-color:#10b981;background:#e8f8ee}
  .badge.warn{color:#92400e;border-color:#f59e0b;background:#fff7e6}
  .badge.bad{color:#991b1b;border-color:#ef4444;background:#fdeaea}

  .pager{display:flex;gap:8px;align-items:center;justify-content:center;margin-bottom:8px}
  .pager button, .pager select{border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;background:#fff;cursor:pointer}

  /* ==================== CALENDAR (aligned to Disciple) ==================== */
  .calendar{margin-top:16px; container-type:inline-size;}
  .monthbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;flex-wrap:wrap}
  .monthpager{display:flex;gap:8px;align-items:center}
  .monthpager button{border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;background:#fff;cursor:pointer}
  .legend{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .grad{display:flex;gap:4px;align-items:center}
  .grad .swatch{width:16px;height:12px;border-radius:4px;border:1px solid #e5e7eb}

  /* Scrollable like Disciple (so width + responsiveness match) */
  .calendar-scroll{
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 12px;
    padding-bottom: 0;
  }

  .calendar-grid{
    display:grid;
    grid-template-columns: repeat(7, minmax(120px, 1fr)); /* match Disciple */
    gap: var(--cell-gap);
    min-width: 840px;
  }
  @media (max-width:480px){
    .calendar-grid{
      grid-template-columns: repeat(7, minmax(110px,1fr));
      min-width: 770px;
      gap: 8px;
    }
  }

  .dow{font-size:11px;color:var(--muted);text-align:center;margin-bottom:6px;font-weight:700}

  /* Day cells: remove square ratio, give breathing room like Disciple */
  .cell{
    min-height:128px; border-radius:10px; background:#fff; position:relative; overflow:hidden;
    border:1px solid #e5e7eb; transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    display:flex; flex-direction:column; padding:10px;
  }
  .cell:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(0,0,0,.09);border-color:#d1d5db}
  .cell.is-today{box-shadow:0 0 0 2px var(--ring) inset, 0 10px 22px rgba(0,0,0,.09)}
  .date{position:absolute;top:8px;left:8px;font-size:12px;line-height:1;font-weight:900;padding:4px 7px;border:1px solid #e5e7eb;border-radius:999px;background:#fff}
  .cell.is-today .date{box-shadow:0 0 0 2px var(--ring) inset;border-color:var(--ring);color:var(--ring)}
  .fillday{position:absolute;inset:0;opacity:.08}

  /* Bubble grid: 3 columns, un-cramped */
  .pr-grid{
    margin-top:36px;                /* room under date/% header row */
    display:grid; grid-template-columns:repeat(3,1fr);
    gap:6px;
    padding:0 8px 8px;
  }
  .pr{background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:6px 6px 8px; display:flex; flex-direction:column; align-items:center; gap:4px}
  .abbr{font-size:12px; font-weight:900; color:#111827}
  .mini-bar{ width:100%; height:7px; background:#f3f4f6; border-radius:999px; overflow:hidden }
  .mini-bar > span{ display:block; height:100%; width:0; background:var(--bar); transition:width .25s ease }

  /* % pill moved to TOP-RIGHT, compact */
  .pctday{
    position:absolute; top:8px; right:8px;
    height:26px; line-height:26px; padding:0 10px;
    font-size:13px; font-weight:900; color:#fff;
    background:linear-gradient(135deg, #4f46e5, #1d4ed8);
    border-radius:999px; box-shadow:0 2px 6px rgba(0,0,0,.12);
    pointer-events:none;
  }

  /* Centered tinted “hugbox” look (make sure it's centered within the card) */
  .hugbox{
    display:flex; align-items:center; justify-content:center;
    gap:10px; flex-wrap:wrap;
    padding:10px 12px; margin:12px auto 0;
    background: rgba(69, 97, 168, 0.08);
    border:1px solid rgba(69, 97, 168, 0.18);
    border-radius:12px; box-shadow:var(--shadow);
    width:max-content; max-width:100%;
    text-align:center;
  }
  .legend2{display:flex;flex-wrap:wrap;gap:10px;margin-top:0; justify-content:center;}
  .legend2 .chip{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px}
  .legend2 .sw{width:30px;height:22px;display:grid;place-items:center;font-size:11px;font-weight:900;background:#fff;color:#111;border:1px solid #e5e7eb;border-radius:6px}

  /* tooltips (hover only; calendar cells are NOT clickable) */
  #cal-tip,#day-tip{
    position:fixed; z-index:9999; pointer-events:none; background:#111827; color:#fff;
    padding:14px 16px; border-radius:14px; box-shadow:0 12px 30px rgba(0,0,0,.30);
    font-size:14px; line-height:1.4; opacity:0; transform:translateY(-6px);
    transition:opacity .12s ease, transform .12s ease;
  }
  #cal-tip .title,#day-tip .title{ font-weight:800; font-size:15px; margin-bottom:6px }
  #cal-tip .pill,#day-tip .pill{ display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:800; border:1px solid rgba(255,255,255,.2) }
  #cal-tip .sub,#day-tip .sub{ color:rgba(255,255,255,.9); font-size:12px }

  canvas#groupRadar{max-height:420px}
</style>
</head>
<body>

<div class="ld-container" id="ld-root"
     data-ajax-url="<?php echo $ajax_url; ?>"
     data-disciple-url="<?php echo $drill_url; ?>"
     data-ld-nonce="<?php echo esc_attr($ld_req_nonce); ?>">
  <!-- Success likelihood -->
  <div class="card insight" id="succBox">
    <span class="tag">Success likelihood</span>
    <div class="pill" id="succText">—</div>
    <div class="bar"><span id="succBar"></span></div>
  </div>

  <!-- Performance Overview -->
  <section class="card">
    <h2 class="title-center">Performance Overview</h2>
    <canvas id="groupRadar"></canvas>
    <div class="hugbox" id="radarNote"></div>
  </section>

  <!-- Leaderboard -->
  <section class="card">
    <div class="leaderbar"><h2 class="section-title">Overall Achievement per Disciple</h2></div>
    <div class="pager">
      <button id="prevBtn">◀ Prev</button>
      <span id="pageInfo">Page 1</span>
      <button id="nextBtn">Next ▶</button>
      <label style="margin-left:8px">Rows:
        <select id="pageSize"><option>3</option><option selected>4</option><option>5</option></select>
      </label>
    </div>
    <div id="leaderGrid" class="leadergrid"></div>
  </section>

  <!-- Calendar -->
  <section class="card calendar">
    <div class="monthbar">
      <div class="monthpager">
        <button id="calPrev">◀ Prev Month</button>
        <h2 id="rangeLabel" style="min-width:200px;text-align:center">—</h2>
        <button id="calNext">Next Month ▶</button>
      </div>
      <div class="legend">
        <span>0%</span>
        <div class="grad">
          <div class="swatch" style="background:var(--g0)"></div>
          <div class="swatch" style="background:var(--g1)"></div>
          <div class="swatch" style="background:var(--g2)"></div>
          <div class="swatch" style="background:var(--g3)"></div>
          <div class="swatch" style="background:var(--g4)"></div>
          <div class="swatch" style="background:var(--g5)"></div>
        </div>
        <span>100%</span>
      </div>
    </div>

    <div class="calendar-scroll">
      <div class="calendar-grid" id="dowRow">
        <div class="dow">S</div><div class="dow">M</div><div class="dow">T</div>
        <div class="dow">W</div><div class="dow">T</div><div class="dow">F</div><div class="dow">S</div>
      </div>

      <div class="calendar-grid" id="calGrid" style="margin-top:6px"></div>
    </div>

    <div class="legend2 hugbox" id="pracLegend"></div>
  </section>
</div>

<!-- Tooltips (hover only; calendar cells are NOT clickable) -->
<div id="cal-tip" role="status" aria-live="polite"></div>
<div id="day-tip" role="status" aria-live="polite"></div>

<script>
const ROOT = document.getElementById('ld-root');
const ajaxURL = ROOT.dataset.ajaxUrl + '?action=get_leadership_data';
let   DRILLDOWN_URL = ROOT.dataset.discipleUrl; // may be overridden by AJAX meta
const LD_REQ_NONCE = ROOT.dataset.ldNonce;

const PRACTICES = [
  {abbr:'F',  db:'Fasting',                      label:'Fasting',                       unit:'weekly'},
  {abbr:'MP', db:'Midnight Intercessory Prayer', label:'Midnight Prayer',               unit:'weekly'},
  {abbr:'BS', db:'Bible Study & Meditation',     label:'Bible Study & Meditation',      unit:'weekly'},
  {abbr:'SM', db:'Scripture Memorization',       label:'Scripture Memorization',        unit:'weekly'},
  {abbr:'CP', db:'Corporate Prayers',            label:'Corporate Prayers',             unit:'weekly'},
  {abbr:'MI', db:'Morning Intimacy',             label:'Morning Intimacy',              unit:'daily'},
  {abbr:'BR', db:'Bible Reading',                label:'Bible Reading',                 unit:'daily'},
];
const DB2ABBR   = Object.fromEntries(PRACTICES.map(p=>[p.db,p.abbr]));
const ABBR2UNIT = Object.fromEntries(PRACTICES.map(p=>[p.abbr,p.unit]));
const ABBR2LABEL= Object.fromEntries(PRACTICES.map(p=>[p.abbr,p.label]));
const COLORS = ["var(--g0)","var(--g1)","var(--g2)","var(--g3)","var(--g4)","var(--g5)"];
const TARGET = 0.94;

const ymd = d => d.toISOString().slice(0,10);
const sod = d => { const x=new Date(d); x.setHours(0,0,0,0); return x; };

/* SUNDAY-based week (MATCH BACKEND) */
function weekStartSun(d){ const x=sod(d); const day=x.getDay(); x.setDate(x.getDate()-day); return x; }

function bucketColor(x){ const b=Math.max(0, Math.min(5, Math.floor(x*6))); return COLORS[b]; }
function pct(x){ return Math.round(Math.max(0,Math.min(1, x))*100); }
function titleCase(s){ return s.replace(/(^|\\s|-|_|\\.)\\w/g, m=>m.toUpperCase()); }
function guessNameFromEmail(email){
  const local=(email||'').split('@')[0].replace(/\\d+/g,'');
  const parts=local.split(/[._-]+/).filter(Boolean);
  if(parts.length>=2) return titleCase(parts[0])+' '+titleCase(parts[1]);
  return titleCase(local);
}

let RAW_COMMITMENTS=[], PARTICIPANTS=[], board=[], monthOffset=0, DRILL_TOKENS={};

/* LOAD */
async function load(){
  // POST with request nonce (handler will verify if implemented)
  const params = new URLSearchParams({ ld_req_nonce: LD_REQ_NONCE });
  const res = await fetch(ajaxURL, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    credentials:'same-origin',
    body: params.toString()
  });
  const payload = await res.json();

  // New format: { participants: [...], commitments: [...], meta: {...} }
  PARTICIPANTS = payload.participants || [];
  RAW_COMMITMENTS = payload.commitments || [];
  DRILL_TOKENS = payload.drills || {};
  
  if (payload.meta && payload.meta.disciple_url) {
    DRILLDOWN_URL = payload.meta.disciple_url;
  }

  buildRadar(RAW_COMMITMENTS);
  buildLeaderboard(PARTICIPANTS);
  renderSummary();
}

/* SUMMARY */
function renderSummary(){
  const total = board.length;
  const onpace = board.filter(b => b.pct >= TARGET).length;
  const p = total ? Math.round(onpace/total*100) : 0;
  document.getElementById('succText').textContent = total ? `${onpace} of ${total} on pace (≥${Math.round(TARGET*100)}%)` : 'No participants yet';
  document.getElementById('succBar').style.width = p + '%';
}

/* RADAR */
let radarChart=null;
function buildRadar(rows){
  const byPractice = {};
  rows.forEach(r=>{
    const ab = DB2ABBR[r.practice]; if(!ab) return;
    const v = Math.max(0, Math.min(1, Number(r.value||0)));
    byPractice[ab] = byPractice[ab] || {done:0,total:0};
    byPractice[ab].done += v; byPractice[ab].total += 1;
  });
  const labels = PRACTICES.map(p=>p.label);
  const dataPct = PRACTICES.map(p=>{
    const t = byPractice[p.abbr]; return t && t.total ? (t.done/t.total*100) : 0;
  });

  const ctx = document.getElementById("groupRadar").getContext("2d");
  if (radarChart) radarChart.destroy();
  radarChart = new Chart(ctx, {
    type: "radar",
    data: { labels, datasets: [{
      label: "% Attainment by Practice",
      data: dataPct,
      borderWidth: 2, pointRadius: 3,
      backgroundColor: "rgba(59,130,246,.15)",
      borderColor: "rgba(59,130,246,.9)"
    }]},
    options: {
      responsive:true,
      plugins:{ legend:{ display:true, labels:{ boxWidth:14, font:{ size:14 } } } },
      scales:{ r:{
        suggestedMin:0, suggestedMax:100,
        pointLabels:{ font:{ size:14 } },
        ticks:{ stepSize:20, font:{ size:12 }, backdropColor:'transparent' }
      } }
    }
  });

  let weakest=null, wVal=101, strongest=null, sVal=-1;
  labels.forEach((k,i)=>{ const v=dataPct[i]; if(v<wVal){wVal=v; weakest=k;} if(v>sVal){sVal=v; strongest=k;} });
  const note = document.getElementById('radarNote');
  note.innerHTML = `<span class="badge ok">Strongest · ${strongest || '—'} (${Math.round(sVal)}%)</span>
                    <span class="badge warn">Weakest · ${weakest || '—'} (${Math.round(wVal)}%)</span>`;
}

/* LEADERBOARD */
const leaderGrid = document.getElementById("leaderGrid");
const pageInfo = document.getElementById("pageInfo");
const prevBtn = document.getElementById("prevBtn");
const nextBtn = document.getElementById("nextBtn");
const pageSizeSel = document.getElementById("pageSize");
let page=1, pageSize=4;

function buildLeaderboard(participants){
  // Use attainment directly from wp_discipleship_participants table
  board = participants.map(p => {
    const pid = (p.participant_id || p.user_email || '').trim().toLowerCase();
    const name = p.participant_name || guessNameFromEmail(pid);
    const attainment = Math.max(0, Math.min(100, Number(p.attainment || 0)));
    const pct = attainment / 100; // Convert percentage to decimal for consistency
    
    return {
      pid,
      name,
      pct,
      attainment,  // Keep original percentage for display
      status: p.status || 'active',
      program_id: p.program_id,
      level_id: p.current_level_id,
      user_id: p.user_id
    };
  }).sort((a, b) => b.pct - a.pct);
  
  renderLeaderboard();
}
function badgeClass(p){ if(p>=TARGET) return "badge ok"; if(p>=0.80) return "badge warn"; return "badge bad"; }
function statusLabel(status){
  const labels = { active: 'Active', paused: 'Paused', completed: 'Completed', dropped: 'Dropped' };
  return labels[status] || status;
}
function renderLeaderboard(){
  const size = Number(pageSizeSel.value||4); pageSize=size;
  const pages = Math.max(1, Math.ceil(board.length / pageSize));
  page = Math.max(1, Math.min(page, pages));
  pageInfo.textContent = `Page ${page} / ${pages}`;
  prevBtn.disabled = page<=1; nextBtn.disabled = page>=pages;

  const items = board.slice((page-1)*pageSize, (page-1)*pageSize + pageSize);
  leaderGrid.innerHTML = "";
  items.forEach(b=>{
    const pctNum = Math.round(b.attainment * 10) / 10; // Use attainment directly (already percentage)

    const row = document.createElement("a");
    row.className="row";

    // Secure drill link — include per-email nonce if provided by the handler
    const token = DRILL_TOKENS[b.pid] || '';
    const u = new URL(DRILLDOWN_URL, window.location.origin);
    u.searchParams.set('participant', b.pid);
    if (token) u.searchParams.set('dd_view_nonce', token);

    row.href = u.toString();
    row.title = "Open disciple dashboard";

    const av = document.createElement("div"); av.className="avatar";
    av.textContent = (b.name||'?').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase();

    const mid = document.createElement("div");
    const nm = document.createElement("div"); nm.className="name"; nm.textContent = b.name;
    const meta = document.createElement("div"); meta.className="meta"; meta.textContent = b.pid;
    const bar = document.createElement("div"); bar.className="bar";
    const fill = document.createElement("div"); fill.className="fill"; fill.style.width = Math.min(100, Math.max(0, pctNum)) + "%";
    bar.appendChild(fill);
    const ratio = document.createElement("div"); ratio.className="ratio"; ratio.textContent = statusLabel(b.status);
    mid.appendChild(nm); mid.appendChild(meta); mid.appendChild(bar); mid.appendChild(ratio);

    const right = document.createElement("div"); right.className="right";
    const p = document.createElement("div"); p.className="pct"; p.textContent = pctNum + "%";
    const badge = document.createElement("div"); badge.className = badgeClass(b.pct);
    badge.textContent = b.pct>=TARGET ? "Likely to Complete" : (b.pct>=0.80 ? "Watch" : "At Risk");
    right.appendChild(p); right.appendChild(badge);

    row.appendChild(av); row.appendChild(mid); row.appendChild(right);
    leaderGrid.appendChild(row);
  });
}
prevBtn.addEventListener("click", ()=>{ page--; renderLeaderboard(); });
nextBtn.addEventListener("click", ()=>{ page++; renderLeaderboard(); });
pageSizeSel.addEventListener("change", ()=>{ page=1; renderLeaderboard(); });

/* CALENDAR */
const calGrid = document.getElementById("calGrid");
const rangeLabel = document.getElementById("rangeLabel");
const calPrev = document.getElementById("calPrev");
const calNext = document.getElementById("calNext");
const pracLegend = document.getElementById("pracLegend");

function monthTitle(y,m){ return new Date(y,m,1).toLocaleString(undefined,{month:"long",year:"numeric"}); }

function aggregateForCalendar(rows){
  const seenDaily = new Map();   // pid|date|abbr
  const seenWeekly= new Map();   // pid|week|abbr (week = SUNDAY start)
  rows.forEach(r=>{
    const pid = (r.participant_id||'').trim().toLowerCase(); if(!pid) return;
    const ab  = DB2ABBR[r.practice]; if(!ab) return;
    const v   = Math.max(0, Math.min(1, Number(r.value||0)));
    if (ABBR2UNIT[ab]==='daily'){
      const d = r.date; if(!d) return;
      const key = `${pid}|${d}|${ab}`;
      seenDaily.set(key, Math.max(v, seenDaily.get(key)||0));
    } else {
      const w = r.week_start; if(!w) return; // SUNDAY from backend
      const key = `${pid}|${w}|${ab}`;
      seenWeekly.set(key, Math.max(v, seenWeekly.get(key)||0));
    }
  });

  const dailyAgg = {}, weeklyAgg = {};
  const participantsDaily = {}, participantsWeekly = {};
  for(const [key,v] of seenDaily.entries()){
    const [pid,date,ab] = key.split("|");
    (dailyAgg[date] ||= {}); (dailyAgg[date][ab] ||= {sum:0,count:0});
    dailyAgg[date][ab].sum += v; dailyAgg[date][ab].count += 1;
    (participantsDaily[date] ||= new Set()).add(pid);
  }
  for(const [key,v] of seenWeekly.entries()){
    const [pid,week,ab] = key.split("|");
    (weeklyAgg[week] ||= {}); (weeklyAgg[week][ab] ||= {sum:0,count:0});
    weeklyAgg[week][ab].sum += v; weeklyAgg[week][ab].count += 1;
    (participantsWeekly[week] ||= new Set()).add(pid);
  }
  return {dailyAgg, weeklyAgg, participantsDaily, participantsWeekly};
}

function renderLegendChips(){
  pracLegend.innerHTML = '';
  PRACTICES.forEach(p=>{
    const chip = document.createElement('div'); chip.className='chip';
    chip.innerHTML = `<span class="sw">${p.abbr}</span> <span>${p.label}</span>`;
    pracLegend.appendChild(chip);
  });
}

function renderCalendar(){
  const {dailyAgg, weeklyAgg, participantsDaily, participantsWeekly} = aggregateForCalendar(RAW_COMMITMENTS);

  const base = new Date();
  const year = base.getFullYear();
  const monthIndex = base.getMonth() + monthOffset;

  const start = new Date(year, monthIndex, 1);
  const end   = new Date(year, monthIndex + 1, 0);
  const startGrid = new Date(start); startGrid.setDate(1 - start.getDay());   // SUNDAY grid start
  const endGrid   = new Date(end);   endGrid.setDate(end.getDate() + (6 - end.getDay()));
  const todayStr = ymd(sod(new Date()));

  rangeLabel.textContent = monthTitle(start.getFullYear(), start.getMonth());
  calGrid.innerHTML = "";

  for (let d = new Date(startGrid); d <= endGrid; d.setDate(d.getDate()+1)){
    const dateStr = ymd(d);
    const inMonth = (d >= start && d <= end);
    const wkSun = ymd(weekStartSun(d)); // SUNDAY week key

    const pcts = {}, present = {};
    PRACTICES.forEach(p=>{
      if (p.unit==='daily'){
        const rec = dailyAgg[dateStr]?.[p.abbr];
        if (rec && rec.count){ pcts[p.abbr] = rec.sum/rec.count; present[p.abbr]=1; }
        else { pcts[p.abbr] = 0; present[p.abbr]=0; }
      } else {
        const rec = weeklyAgg[wkSun]?.[p.abbr];
        if (rec && rec.count){ pcts[p.abbr] = rec.sum/rec.count; present[p.abbr]=1; }
        else { pcts[p.abbr] = 0; present[p.abbr]=0; }
      }
    });

    const denom = Object.values(present).reduce((a,b)=>a+b,0) || PRACTICES.length;
    const overall = PRACTICES.reduce((sum,p)=> sum + pcts[p.abbr], 0) / denom;

    const nDaily = participantsDaily[dateStr]?.size || 0;
    const nWeekly= participantsWeekly[wkSun]?.size || 0;
    const n = Math.max(nDaily, nWeekly);

    const cell = document.createElement("div");
    cell.className = "cell";
    if (inMonth) cell.classList.add('in-month');
    if (dateStr === todayStr) cell.classList.add('is-today');

    const fill = document.createElement("div"); fill.className = "fillday"; fill.style.background = bucketColor(overall);
    cell.appendChild(fill);

    const dateEl = document.createElement("div"); dateEl.className = "date"; dateEl.textContent = d.getDate();
    cell.appendChild(dateEl);

    const grid = document.createElement("div"); grid.className = "pr-grid";
    PRACTICES.forEach(p=>{
      const wrap = document.createElement("div");
      wrap.className = "pr";
      wrap.dataset.abbr=p.abbr; wrap.dataset.label=p.label; wrap.dataset.value=pcts[p.abbr]; wrap.dataset.unit=p.unit;
      const ab = document.createElement("div"); ab.className = "abbr"; ab.textContent = p.abbr;
      const mb = document.createElement("div"); mb.className = "mini-bar";
      const span = document.createElement("span"); span.style.width = pct(pcts[p.abbr]) + "%";
      mb.appendChild(span);
      wrap.appendChild(ab); wrap.appendChild(mb);
      grid.appendChild(wrap);
    });
    cell.appendChild(grid);

    const pctEl = document.createElement("div");
    pctEl.className = "pctday"; pctEl.textContent = pct(overall) + "%";
    cell.appendChild(pctEl);

    // for hover tooltips only (click removed)
    cell.dataset.date = dateStr; cell.dataset.n = n; cell.dataset.overall = overall;
    PRACTICES.forEach(p=>{ cell.dataset['p'+p.abbr] = pcts[p.abbr]; });

    calGrid.appendChild(cell);
  }

  renderLegendChips();

  /* tooltips (hover only) */
  const tip = document.getElementById('cal-tip');
  const dayTip = document.getElementById('day-tip');
  function showTip(el, html,x,y){ el.innerHTML=html; el.style.left=(x+12)+'px'; el.style.top=(y+12)+'px'; el.style.opacity=1; el.style.transform='translateY(0)'; }
  function hide(el){ el.style.opacity=0; el.style.transform='translateY(-6px)'; }

  calGrid.addEventListener('mousemove', e=>{
    const pr = e.target.closest('.pr');
    if (pr){
      const v = Number(pr.dataset.value||0);
      const pctStr = pct(v) + '%';
      const status = (v>=1) ? {txt:'✓ Full',cls:'ok'} : (v>0) ? {txt:'½ Partial',cls:'warn'} : {txt:'– None',cls:'bad'};
      const cadence = (pr.dataset.unit==='weekly') ? 'Weekly target' : 'Daily practice';
      const html = `<div class="title">${pr.dataset.abbr} · ${pr.dataset.label}</div>
                    <div class="meta"><span class="pill ${status.cls}">${status.txt}</span>
                    <span class="sub">${cadence} · ${pctStr}</span></div>`;
      showTip(tip, html, e.clientX, e.clientY); hide(dayTip); return;
    }
    const cell = e.target.closest('.cell.in-month');
    if (!cell){ hide(tip); hide(dayTip); return; }
    const html = `<div class="title">${cell.dataset.date}</div>
      <div style="display:grid;grid-template-columns:1fr auto;gap:6px;font-size:14px">
        <div>Participants counted</div><div style="font-weight:700">${cell.dataset.n}</div>
        <div>Overall completion</div><div style="font-weight:700">${pct(cell.dataset.overall)}%</div>
        <hr style="grid-column:1/-1;margin:8px 0;border:none;border-top:1px solid rgba(255,255,255,.15)">
        ${['BR','MI','F','MP','BS','SM','CP'].map(k=>{
          const label={'BR':'Bible Reading','MI':'Morning Intimacy','F':'Fasting','MP':'Midnight Prayer','BS':'Bible Study & Meditation','SM':'Scripture Memorization','CP':'Corporate Prayers'}[k];
          const v = pct(cell.dataset['p'+k] || 0);
          return `<div>${label}</div><div style="font-weight:700">${v}%</div>`;
        }).join('')}
      </div>`;
    showTip(dayTip, html, e.clientX, e.clientY); hide(tip);
  });
  calGrid.addEventListener('mouseleave', ()=>{ hide(tip); hide(dayTip); });
}

calPrev.addEventListener("click", ()=>{ monthOffset -= 1; renderCalendar(); });
calNext.addEventListener("click", ()=>{ monthOffset += 1; renderCalendar(); });

/* BOOT */
(async function(){
  await load();
  renderCalendar();
})();
</script>
</body>
</html>
<?php return ob_get_clean(); });
