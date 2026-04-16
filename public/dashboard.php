<?php $title='Dashboard'; include __DIR__.'/header.php'; ?>
<?php $perf = fetch_performance_overview(current_target_month()); $perfSummary = $perf['summary']; $slaSummary = (is_manager() || is_district_manager()) ? fetch_approval_sla_summary() : null; $overdueQuick = (is_manager() || is_district_manager()) ? fetch_overdue_reports(5) : []; ?>

<div class="summary-grid summary-grid-dashboard">
  <div class="card summary-card"><div class="summary-label">This Month Reports</div><div class="summary-value"><?= (int)$perfSummary['total_reports'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Approved</div><div class="summary-value"><?= (int)$perfSummary['total_approved'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?= (int)$perfSummary['total_pending'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Doctor Coverage</div><div class="summary-value"><?= (int)$perfSummary['total_doctors'] ?></div></div>
</div>

<?php if (is_manager() || is_district_manager()): ?>
<div class="card">
  <div class="flex-between">
    <h2 class="titlecase">Approval SLA Snapshot</h2>
    <a class="btn tiny" href="<?= url('admin/approval_sla.php') ?>">Open SLA View</a>
  </div>
  <div class="sla-inline-grid">
    <div class="sla-inline-item"><span class="muted">Pending</span><strong><?= (int)($slaSummary['pending_total'] ?? 0) ?></strong></div>
    <div class="sla-inline-item"><span class="muted">24h+</span><strong class="warning-text"><?= (int)($slaSummary['aging_warning'] ?? 0) ?></strong></div>
    <div class="sla-inline-item"><span class="muted">Overdue</span><strong class="danger-text"><?= (int)($slaSummary['overdue_total'] ?? 0) ?></strong></div>
    <div class="sla-inline-item"><span class="muted">Avg Approval</span><strong><?= e((string)($slaSummary['avg_hours_to_approve'] ?? 0)) ?>h</strong></div>
  </div>
  <?php if ($overdueQuick): ?>
    <div class="table-wrap" style="margin-top:.75rem">
      <table class="table">
        <thead><tr><th>Rep</th><th>Doctor</th><th>Age</th><th></th></tr></thead>
        <tbody>
          <?php foreach($overdueQuick as $row): ?>
            <tr>
              <td><?= e($row['employee']) ?></td>
              <td><?= e($row['doctor_name']) ?></td>
              <td><span class="pill danger"><?= (int)$row['age_hours'] ?>h</span></td>
              <td><a class="btn tiny" href="report_view.php?id=<?= (int)$row['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="muted small" style="margin-top:.75rem">No overdue approvals right now.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

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

  <div class="vstack gap-s">
    <div class="card kpi-card">
      <div class="flex-between">
        <h2 class="titlecase">KPIs</h2>
        <?php if (is_manager() || is_district_manager()): ?>
          <a class="btn tiny" href="<?= url('admin/performance.php') ?>">Open Performance</a>
        <?php endif; ?>
      </div>

<?php if(in_array(user()['role'] ?? '', ['manager','district_manager'], true)): ?>
  <div class="kpi-grid">
    <div class="chart-box"><canvas id="chartEmployees"></canvas></div>
    <div class="chart-box"><canvas id="chartStatus"></canvas></div>
    <div class="chart-box"><canvas id="chartTimeline"></canvas></div>
  </div>

  <script>
  window.addEventListener('load', () => {
    if (!window.Chart) return;

    fetch('api/chart_data.php')
      .then(r=>r.json())
      .then(resp=>{
        const d = (resp && resp.data) ? resp.data : {};
        new Chart(document.getElementById('chartEmployees'),{
          type:'bar',
          data:{ labels:(d.byEmployee?.labels || []), datasets:[{label:'Reports',data:(d.byEmployee?.data || []), borderWidth:0, borderRadius:8}] },
          options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
            scales:{ x:{ beginAtZero:true, ticks:{ stepSize:1, callback:v=>Number.isInteger(v)?v:'' } }, y:{ ticks:{ autoSkip:false } } },
            plugins:{ legend:{display:false} }
          }
        });
        new Chart(document.getElementById('chartStatus'),{
          type:'doughnut',
          data:{ labels:(d.byStatus?.labels || []), datasets:[{ data:(d.byStatus?.data || []) }] },
          options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
        });
        new Chart(document.getElementById('chartTimeline'),{
          type:'bar',
          data:{ labels:(d.byDate?.labels || []), datasets:[{label:'Reports / Day',data:(d.byDate?.data || []), borderWidth:0, borderRadius:8}] },
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
    fetch('api/chart_data.php?mine=1')
      .then(r=>r.json())
      .then(resp=>{
        const d = (resp && resp.data) ? resp.data : {};
        new Chart(document.getElementById('chartMine'),{
          type:'bar',
          data:{ labels:(d.byDate?.labels || []), datasets:[{ label:'My Reports', data:(d.byDate?.data || []), borderWidth:0, borderRadius:8 }] },
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

    <?php if (is_manager() || is_district_manager()): ?>
    <div class="card">
      <div class="flex-between">
        <h2 class="titlecase">Territory Snapshot</h2>
        <span class="pill-soft"><?= e(current_target_month()) ?></span>
      </div>
      <div class="mini-kpi-list">
        <div class="mini-kpi"><span>Target Achievement</span><strong><?= (int)$perfSummary['achievement_pct'] ?>%</strong></div>
        <div class="mini-kpi"><span>Hospital Reach</span><strong><?= (int)$perfSummary['total_hospitals'] ?></strong></div>
        <div class="mini-kpi"><span>Medicine Reach</span><strong><?= (int)$perfSummary['total_medicines'] ?></strong></div>
      </div>
      <p class="muted" style="margin-top:.75rem">Use the performance page for monthly target setting and rep-by-rep attainment tracking.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.addEventListener('load', async () => {
  if (!window.tui || !tui.Calendar) {
    const el = document.getElementById('calendar');
    if (el) el.innerHTML = '<div class="muted" style="padding:14px">Calendar unavailable offline.</div>';
    return;
  }

  let eventsRaw = [];
  try {
    const resp = await fetch('api/api_events.php');
    const payload = await resp.json();
    eventsRaw = (payload && payload.data && Array.isArray(payload.data.events)) ? payload.data.events : [];
  } catch (e) {
    eventsRaw = [];
  }

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
