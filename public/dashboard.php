<?php $title='Dashboard'; include __DIR__.'/header.php'; ?>
<?php
$myRecentNotifications = notifications_recent((int)(user()['id'] ?? 0), 6);
$myUnreadNotifications = notifications_unread_count((int)(user()['id'] ?? 0));
?>


<div class="grid three">
  <div class="card stretch">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.4rem">
      <h2 class="titlecase" style="margin:0">Calendar</h2>
      <div class="titlecase" style="display:flex;gap:.35rem">
        <button class="btn" id="calBtnMonth" type="button">Month</button>
        <button class="btn" id="calBtnWeek" type="button">Week</button>
        <button class="btn" id="calBtnDay" type="button">Day</button>
        <button class="btn" id="calBtnToday" type="button">Today</button>
      </div>
    </div>
    <div id="calendar" class="tui-cal" style="height:70vh; min-height:620px;"></div>
  </div>

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

<div class="grid two" style="margin-top:1rem">
  <div class="card">
    <div class="flex between center wrap-gap">
      <div>
        <h2 class="titlecase" style="margin:0">Notification Center</h2>
        <p class="muted" style="margin:.35rem 0 0">Unread alerts: <strong><?= (int)$myUnreadNotifications ?></strong></p>
      </div>
      <a class="btn primary" href="<?= url('notifications.php') ?>">Open Notifications</a>
    </div>

    <div class="notification-list compact" style="margin-top:1rem">
      <?php if (!$myRecentNotifications): ?>
        <div class="empty-state">
          <h3 class="titlecase">No recent alerts</h3>
          <p class="muted">New report submissions, review outcomes, and task assignments will appear here.</p>
        </div>
      <?php else: ?>
        <?php foreach ($myRecentNotifications as $n): ?>
          <a class="notif-item compact <?= (int)$n['is_read']===0 ? 'unread' : '' ?>" href="<?= e($n['action_url'] ?: url('notifications.php')) ?>">
            <div class="notif-main">
              <div class="notif-meta">
                <span class="pill"><?= e($n['type']) ?></span>
                <span class="muted"><?= e(date('M d, Y h:i A', strtotime((string)$n['created_at']))) ?></span>
              </div>
              <h3><?= e($n['title']) ?></h3>
              <?php if (!empty($n['body'])): ?><p><?= e($n['body']) ?></p><?php endif; ?>
            </div>
            <div class="notif-dot <?= (int)$n['is_read']===0 ? 'live' : '' ?>"></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2 class="titlecase">What changed in this update</h2>
    <ul class="crm-list">
      <li>New report submissions notify reviewers automatically.</li>
      <li>Managers can send approval / needs-changes alerts back to reps.</li>
      <li>Task attendees now get notified when they are assigned.</li>
      <li>Notifications can be filtered and marked as read.</li>
    </ul>
  </div>
</div>

<!-- Replace ONLY the <script>…</script> block at the bottom of public/dashboard.php with this -->
<script>
window.addEventListener('load', async () => {
  // Toast UI Calendar is loaded (defer) only on this page.
  if (!window.tui || !tui.Calendar) {
    const el = document.getElementById('calendar');
    if (el) el.innerHTML = '<div class="muted" style="padding:14px">Calendar unavailable offline.</div>';
    return;
  }

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


<?php include __DIR__.'/footer.php'; ?>
