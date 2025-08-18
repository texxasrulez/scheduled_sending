/**
 * Scheduled Sending: Queue viewer
 * Larry skin friendly; computes local times in browser
 */
(function() {
  if (!window.rcmail) return;

  // Command to open queue page
  rcmail.addEventListener('init', function() {
    rcmail.register_command('plugin.scheduled_sending.open_queue', function() {
      rcmail.goto_url('_task=mail&_action=plugin.scheduled_sending.queue');
    }, true);
  });

  // When queue template is loaded, fetch data
  rcmail.addEventListener('init', function() {
    if (rcmail.env && rcmail.env.action === 'plugin.scheduled_sending.queue') {
      // Fetch
      rcmail.http_post('plugin.scheduled_sending.queue_list', {}, rcmail.set_busy(true, 'loading'));
    }
  });

  // Receive data
  rcmail.addEventListener('plugin.scheduled_sending.queue_data', function(ev) {
    try {
      var rows = ev;
      if (!Array.isArray(rows)) rows = [];
      var root = document.getElementById('ssq-root');
      if (!root) return;

      // Build table
      var html = [];
      html.push('<table class="ssq-table" role="grid">');
      html.push('<thead><tr><th>ID</th><th>Status</th><th>Local time</th><th>UTC</th><th>To</th><th>Subject</th><th>Actions</th></tr></thead><tbody>');
      rows.forEach(function(r) {
        var dt = r.scheduled_ts ? new Date(r.scheduled_ts * 1000) : null;
        function pad(n){return (n<10?'0':'')+n}
        function fmt(dt){
          try { return new Intl.DateTimeFormat(undefined,{month:'short',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit'}).format(dt); }
          catch(e){ var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()]; return m+' '+pad(dt.getDate())+', '+dt.getFullYear()+' '+((dt.getHours()+11)%12+1)+':'+pad(dt.getMinutes())+' '+(dt.getHours()<12?'AM':'PM'); }
        }
        var local = dt ? fmt(dt) : '';
        var utc = (r.scheduled_utc||'').replace(' ', '&nbsp;');
        var actions = [];
        if (r.status === 'queued' || r.status === 'processing') {
          actions.push('<button class="button ssq-cancel" data-id="'+r.id+'">Cancel</button>');
          actions.push('<button class="button ssq-bump10" data-id="'+r.id+'">+10m</button>');
        } else {
          actions.push('<span class="quiet">n/a</span>');
        }
        html.push('<tr data-id="'+r.id+'"><td>'+r.id+'</td><td>'+r.status+'</td><td>'+local+'</td><td>'+utc+'</td><td>'+ (r.to||'') +'</td><td>'+ (r.subj||'') +'</td><td>'+actions.join(' ')+'</td></tr>');
      });
      html.push('</tbody></table>');
      if (!rows.length) html.push('<p class="ssq-empty">No queued messages.</p>');
      root.innerHTML = html.join('');

      root.onclick = function(e){
        var t = e.target;
        if (!t || !t.classList) return;
        var id = t.getAttribute('data-id');
        if (t.classList.contains('ssq-cancel')) {
          rcmail.http_post('plugin.scheduled_sending.queue_cancel', {id:id}, rcmail.set_busy(true, 'loading'));
          // refresh after short delay
          setTimeout(function(){ rcmail.http_post('plugin.scheduled_sending.queue_list', {}); }, 400);
        } else if (t.classList.contains('ssq-bump10')) {
          // Add 10 minutes from now
          var ts = Math.floor(Date.now()/1000) + 600;
          rcmail.http_post('plugin.scheduled_sending.queue_reschedule', {id:id, at_ts: ts}, rcmail.set_busy(true, 'loading'));
          setTimeout(function(){ rcmail.http_post('plugin.scheduled_sending.queue_list', {}); }, 400);
        }
      };
    } catch(e) {
      try { console.error(e); } catch(_) {}
    } finally {
      rcmail.set_busy(false);
    }
  });
})();
