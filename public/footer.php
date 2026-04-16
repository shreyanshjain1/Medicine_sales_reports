  </section>
</main>
<footer class="footer">&copy; <?= date('Y') ?> <?= e(COMPANY_NAME) ?> · <?= e(APP_NAME) ?></footer>

<div class="session-warning" id="sessionWarning" hidden>
  <div class="session-warning-card">
    <strong>Session expiring soon</strong>
    <div class="muted">For security, you will be signed out soon if there is no activity.</div>
    <div class="session-warning-timer" id="sessionWarningTimer">—</div>
    <div class="actions-inline"><button class="btn tiny" type="button" id="staySignedInBtn">Stay signed in</button><a class="btn tiny danger" href="<?= url('logout.php') ?>">Logout now</a></div>
  </div>
</div>
<script>
(function(){
  const body=document.body;
  const remaining=parseInt(body.dataset.sessionRemaining||'0',10);
  const warning=parseInt(body.dataset.sessionWarning||'0',10);
  const box=document.getElementById('sessionWarning');
  const timerEl=document.getElementById('sessionWarningTimer');
  const stayBtn=document.getElementById('staySignedInBtn');
  if(!box||!timerEl||!stayBtn||!remaining||!warning){ return; }
  let secs=remaining;
  function fmt(n){ const m=Math.floor(n/60); const s=n%60; return m+':'+String(s).padStart(2,'0'); }
  function tick(){
    secs -= 1;
    if(secs <= warning){ box.hidden=false; timerEl.textContent='Auto logout in '+fmt(Math.max(0,secs)); }
    if(secs <= 0){ window.location.href='<?= url('logout.php') ?>'; }
  }
  setInterval(tick,1000);
  stayBtn.addEventListener('click', function(){ window.location.reload(); });
})();
</script>

</body>
</html>
