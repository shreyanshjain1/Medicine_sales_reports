<<<<<<< HEAD
<?php
require_once __DIR__.'/../init.php';
require_login();

$scope = reports_scope_where('r');
$stats = [
  'reports_today' => 0,
  'reports_week' => 0,
  'pending' => 0,
  'approved' => 0,
  'top_medicine' => '—',
  'top_doctor' => '—',
];

$q1 = $mysqli->query("SELECT 
  SUM(CASE WHEN DATE(visit_datetime)=CURDATE() THEN 1 ELSE 0 END) reports_today,
  SUM(CASE WHEN YEARWEEK(visit_datetime,1)=YEARWEEK(CURDATE(),1) THEN 1 ELSE 0 END) reports_week,
  SUM(CASE WHEN COALESCE(NULLIF(status,''),'pending')='pending' THEN 1 ELSE 0 END) pending,
  SUM(CASE WHEN COALESCE(NULLIF(status,''),'pending')='approved' THEN 1 ELSE 0 END) approved
  FROM reports r WHERE {$scope}");
if ($q1 && ($row=$q1->fetch_assoc())) {
  foreach ($row as $k=>$v) $stats[$k] = (int)$v;
}
$q2 = $mysqli->query("SELECT medicine_name, COUNT(*) c FROM reports r WHERE {$scope} AND medicine_name IS NOT NULL AND medicine_name<>'' GROUP BY medicine_name ORDER BY c DESC, medicine_name ASC LIMIT 1");
if ($q2 && ($row=$q2->fetch_assoc())) $stats['top_medicine'] = $row['medicine_name'];
$q3 = $mysqli->query("SELECT doctor_name, COUNT(*) c FROM reports r WHERE {$scope} AND doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name ORDER BY c DESC, doctor_name ASC LIMIT 1");
if ($q3 && ($row=$q3->fetch_assoc())) $stats['top_doctor'] = $row['doctor_name'];

$title='Dashboard'; include __DIR__.'/header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Dashboard</h2>
    <div class="subtle">Sales reporting workspace with calendar, queue visibility, and rep activity metrics.</div>
  </div>
  <a class="btn primary" href="report_add.php">Create report</a>
</div>
<div class="kpi-strip">
  <div class="metric"><div class="label">Reports today</div><div class="value"><?= (int)$stats['reports_today'] ?></div><div class="hint">Daily field activity</div></div>
  <div class="metric"><div class="label">Reports this week</div><div class="value"><?= (int)$stats['reports_week'] ?></div><div class="hint">Weekly submission volume</div></div>
  <div class="metric"><div class="label">Pending approvals</div><div class="value"><?= (int)$stats['pending'] ?></div><div class="hint">Needs manager action</div></div>
  <div class="metric"><div class="label">Approved</div><div class="value"><?= (int)$stats['approved'] ?></div><div class="hint">Closed submissions</div></div>
</div>
<div class="kpi-strip" style="margin-top:-4px">
  <div class="metric"><div class="label">Top medicine</div><div class="value" style="font-size:1.2rem"><?= e($stats['top_medicine']) ?></div><div class="hint">Most reported product</div></div>
  <div class="metric"><div class="label">Top doctor</div><div class="value" style="font-size:1.2rem"><?= e($stats['top_doctor']) ?></div><div class="hint">Most visited account</div></div>
  <div class="metric"><div class="label">Approval queue</div><div class="value" style="font-size:1.2rem"><a href="approvals.php">Open Queue</a></div><div class="hint">Review pending and returned reports</div></div>
  <div class="metric"><div class="label">Exports</div><div class="value" style="font-size:1.2rem"><?php if (is_manager()): ?><a href="exports.php">Open Export Center</a><?php else: ?>Manager only<?php endif; ?></div><div class="hint">Filtered CSV downloads</div></div>
</div>
<div class="grid three">
  <div class="card stretch">
    <div class="flex-between" style="margin-bottom:12px">
      <h2 style="margin:0">Task Calendar</h2>
      <div class="actions-inline">
=======
<?php $title='Dashboard'; include __DIR__.'/header.php'; ?>

<div class="grid three">
  <div class="card stretch">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.4rem">
      <h2 class="titlecase" style="margin:0">Calendar</h2>
      <div class="titlecase" style="display:flex;gap:.35rem">
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
        <button class="btn" id="calBtnMonth" type="button">Month</button>
        <button class="btn" id="calBtnWeek" type="button">Week</button>
        <button class="btn" id="calBtnDay" type="button">Day</button>
        <button class="btn" id="calBtnToday" type="button">Today</button>
      </div>
    </div>
    <div id="calendar" class="tui-cal" style="height:70vh; min-height:620px;"></div>
  </div>
<<<<<<< HEAD
  <div class="card kpi-card">
    <h2>Charts</h2>
    <?php if(in_array(user()['role'] ?? '', ['manager','district_manager'], true)): ?>
      <div class="kpi-grid">
        <div class="chart-box"><canvas id="chartEmployees"></canvas></div>
        <div class="chart-box"><canvas id="chartStatus"></canvas></div>
        <div class="chart-box"><canvas id="chartTimeline"></canvas></div>
      </div>
      <script>
      window.addEventListener('load', () => {
        if (!window.Chart) return;
        fetch('chart_data.php').then(r=>r.json()).then(d=>{
          new Chart(document.getElementById('chartEmployees'),{type:'bar',data:{ labels:d.byEmployee.labels, datasets:[{label:'Reports',data:d.byEmployee.data,borderWidth:0,borderRadius:8}] },options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', scales:{ x:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } }, y:{ ticks:{ autoSkip:false } } }, plugins:{ legend:{display:false} } }});
          new Chart(document.getElementById('chartStatus'),{type:'doughnut',data:{ labels:d.byStatus.labels, datasets:[{ data:d.byStatus.data }] },options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }});
          new Chart(document.getElementById('chartTimeline'),{type:'bar',data:{ labels:d.byDate.labels, datasets:[{label:'Reports / Day',data:d.byDate.data,borderWidth:0,borderRadius:8}] },options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } } }, plugins:{ legend:{display:false}, tooltip:{ mode:'index', intersect:false } } }});
        }).catch(()=>{});
      });
      </script>
    <?php else: ?>
      <p class="muted">Your report activity over time.</p>
      <div class="chart-box solo"><canvas id="chartMine"></canvas></div>
      <script>
      window.addEventListener('load', () => {
        if (!window.Chart) return;
        fetch('chart_data.php?mine=1').then(r=>r.json()).then(d=>{
          new Chart(document.getElementById('chartMine'),{type:'bar',data:{ labels:d.byDate.labels, datasets:[{ label:'My Reports', data:d.byDate.data, borderWidth:0, borderRadius:8 }] },options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } } }, plugins:{ legend:{display:false}, tooltip:{ mode:'index', intersect:false } } }});
        }).catch(()=>{});
      });
      </script>
    <?php endif; ?>
  </div>
</div>
<script>
window.addEventListener('load', async () => {
=======

  <div class="card kpi-card">
    <h2 class="titlecase">KPIs</h2>

<?php if(in_array(user()['role'] ?? '', ['manager','district_manager'], true)): ?>
  <div class="kpi-grid">
    <div class="chart-box"><canvas id="chartEmployees"></canvas></div>
    <div class="chart-box"><canvas id="chartStatus"></canvas></div>
    <div class="chart-box"><canvas id="chartTimeline"></canvas></div>
  </div>

  <script>
  window.addEventListener('load', () => {
    // Chart.js is loaded (defer) only on this page. If offline and it fails to load, skip charts.
    if (!window.Chart) return;

    fetch('chart_data.php')
      .then(r=>r.json())
      .then(d=>{
        new Chart(document.getElementById('chartEmployees'),{
          type:'bar',
          data:{ labels:d.byEmployee.labels, datasets:[{label:'Reports',data:d.byEmployee.data, borderWidth:0, borderRadius:8}] },
          options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
            scales:{ x:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } }, y:{ ticks:{ autoSkip:false } } },
            plugins:{ legend:{display:false} }
          }
        });
        new Chart(document.getElementById('chartStatus'),{
          type:'doughnut',
          data:{ labels:d.byStatus.labels, datasets:[{ data:d.byStatus.data }] },
          options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
        });
        new Chart(document.getElementById('chartTimeline'),{
          type:'bar',
          data:{ labels:d.byDate.labels, datasets:[{label:'Reports / Day',data:d.byDate.data, borderWidth:0, borderRadius:8}] },
          options:{ responsive:true, maintainAspectRatio:false,
            scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } } },
            plugins:{ legend:{display:false}, tooltip:{ mode:'index', intersect:false } }
          }
        });
      })
      .catch(()=>{});
  });
  </script>

<?php else: ?>
  <p class="muted titlecase">Your Activity Over Time</p>
  <div class="chart-box solo"><canvas id="chartMine"></canvas></div>
  <script>
  window.addEventListener('load', () => {
    if (!window.Chart) return;
    fetch('chart_data.php?mine=1')
      .then(r=>r.json())
      .then(d=>{
        new Chart(document.getElementById('chartMine'),{
          type:'bar',
          data:{ labels:d.byDate.labels, datasets:[{ label:'My Reports', data:d.byDate.data, borderWidth:0, borderRadius:8 }] },
          options:{ responsive:true, maintainAspectRatio:false,
            scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } } },
            plugins:{ legend:{display:false}, tooltip:{ mode:'index', intersect:false } }
          }
        });
      })
      .catch(()=>{});
  });
  </script>
<?php endif; ?>

  </div>
</div>

<!-- Replace ONLY the <script>…</script> block at the bottom of public/dashboard.php with this -->
<script>
window.addEventListener('load', async () => {
  // Toast UI Calendar is loaded (defer) only on this page.
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
  if (!window.tui || !tui.Calendar) {
    const el = document.getElementById('calendar');
    if (el) el.innerHTML = '<div class="muted" style="padding:14px">Calendar unavailable offline.</div>';
    return;
  }
<<<<<<< HEAD
  let eventsRaw = [];
  try { const resp = await fetch('api_events.php'); eventsRaw = await resp.json(); } catch (e) { eventsRaw = []; }
  const base = (eventsRaw || []).filter(e => e.start);
  const schedulesV1 = base.map((e,i)=>({ id:String(e.id ?? i), calendarId:'default', title:e.title || 'Task', start:(e.start||'').replace(' ','T'), end:((e.end||e.start)||'').replace(' ','T'), isAllDay:!!e.allDay, category:'time' }));
  const eventsV2 = base.map((e,i)=>({ id:String(e.id ?? i), calendarId:'default', title:e.title || 'Task', start:new Date((e.start||'').replace(' ','T')), end:new Date(((e.end||e.start)||'').replace(' ','T')), isAllday:!!e.allDay }));
  const Calendar = tui.Calendar;
  const cal = new Calendar('#calendar', { defaultView: 'month', useFormPopup:false, useDetailPopup:true, isReadOnly:true, calendars:[{ id:'default', name:'Tasks', backgroundColor:'#ccfbf1', borderColor:'#5eead4' }], theme:{ common:{ backgroundColor:'#ffffff' }, month:{ dayName:{ color:'#6b7280' }, weekend:{ backgroundColor:'#fcfcfc' }, today:{ color:'#0f766e' } }, week:{ today:{ color:'#0f766e' } } } });
  if (typeof cal.createEvents === 'function') { cal.createEvents(eventsV2); cal.on('clickEvent', (ev)=>{ const id=ev?.event?.id ? String(ev.event.id) : ''; if (id) window.location='task_view.php?id=' + encodeURIComponent(id); }); }
  else { cal.createSchedules(schedulesV1); cal.on('clickSchedule', ({schedule})=>{ const id=schedule&&schedule.id?String(schedule.id):''; if (id) window.location='task_view.php?id=' + encodeURIComponent(id); }); }
  function fit() { const el=document.getElementById('calendar'); const topbarH=(document.querySelector('.topbar')?.offsetHeight || 0); const available=window.innerHeight-topbarH-220; const h=Math.max(620, available); el.style.height=h+'px'; cal.render(); }
  window.addEventListener('resize', fit); fit();
  document.getElementById('calBtnMonth')?.addEventListener('click',()=>cal.changeView('month', true));
  document.getElementById('calBtnWeek')?.addEventListener('click',()=>cal.changeView('week', true));
  document.getElementById('calBtnDay')?.addEventListener('click',()=>cal.changeView('day', true));
  document.getElementById('calBtnToday')?.addEventListener('click',()=>cal.today());
});
</script>
=======

  let eventsRaw = [];
  try {
    const resp = await fetch('api_events.php');
    eventsRaw = await resp.json();
  } catch (e) {
    // If truly offline and events were never cached, just render an empty calendar.
    eventsRaw = [];
  }

  // Normalize API -> Calendar formats
  const base = (eventsRaw || []).filter(e => e.start);
  const schedulesV1 = base.map((e,i)=>({
    id: String(e.id ?? i),
    calendarId: 'default',
    title: e.title || 'Task',
    start: (e.start||'').replace(' ', 'T'),
    end: ((e.end||e.start)||'').replace(' ', 'T'),
    isAllDay: !!e.allDay,
    category: 'time'
  }));
  const eventsV2 = base.map((e,i)=>({
    id: String(e.id ?? i),
    calendarId: 'default',
    title: e.title || 'Task',
    start: new Date((e.start||'').replace(' ', 'T')),
    end: new Date(((e.end||e.start)||'').replace(' ', 'T')),
    isAllday: !!e.allDay
  }));

  const Calendar = tui.Calendar;
  const cal = new Calendar('#calendar', {
    defaultView: 'month',
    useFormPopup: false,
    useDetailPopup: true,
    isReadOnly: true,
    calendars: [{ id: 'default', name: 'Tasks', backgroundColor: '#d1fae5', borderColor: '#a7f3d0' }],
    theme: {
      common: { backgroundColor: '#ffffff' },
      month: { dayName: { color: '#6b7280' }, weekend: { backgroundColor: '#fcfcfc' }, today: { color: '#065f46' } },
      week: { today: { color: '#065f46' } }
    }
  });

  // Support both Toast UI Calendar v1 and v2
  if (typeof cal.createEvents === 'function') {
    cal.createEvents(eventsV2);
    cal.on('clickEvent', (ev)=>{
      const id = ev?.event?.id ? String(ev.event.id) : '';
      if (id) window.location = 'task_view.php?id=' + encodeURIComponent(id);
    });
  } else {
    cal.createSchedules(schedulesV1);
    cal.on('clickSchedule', ({schedule})=>{
      const id = schedule && schedule.id ? String(schedule.id) : '';
      if (id) window.location = 'task_view.php?id=' + encodeURIComponent(id);
    });
  }

  function fit() {
    const el = document.getElementById('calendar');
    const topbarH = (document.querySelector('.topbar')?.offsetHeight || 0);
    const available = window.innerHeight - topbarH - 220;
    const h = Math.max(620, available);
    el.style.height = h + 'px';
    cal.render();
  }
  window.addEventListener('resize', fit);
  fit();
});
</script>


>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
<?php include __DIR__.'/footer.php'; ?>
