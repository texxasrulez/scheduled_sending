
/* settings_queue.js - Scheduled Sending preferences page bindings */
(function() {
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function bind_delete_links(rcmail) {
    var nodes = document.querySelectorAll('.delete-scheduled-message');
    if (!nodes.length) return;
    function t(key) { return (rcmail.gettext ? rcmail.gettext(key, 'scheduled_sending') : key); }

    Array.prototype.forEach.call(nodes, function(link) {
      link.addEventListener('click', function(ev) {
        ev.preventDefault();
        var id = this.getAttribute('data-id');
        if (!id) return;

        var ok = window.confirm(t('delete') + '?');
        if (!ok) return;

        rcmail.http_post(
          'plugin.scheduled_sending.queue_delete',
          { _id: id, _token: rcmail.env.request_token },
          rcmail.set_busy(true, 'loading')
        );

        // Optimistically remove the row
        var row = this.closest('tr');
        if (row && row.parentNode) {
          row.parentNode.removeChild(row);
        }
      });
    });
  }

  function bind_edit_links(rcmail) {
    var nodes = document.querySelectorAll('.edit-scheduled-message');
    if (!nodes.length) return;
    function t(key) { return (rcmail.gettext ? rcmail.gettext(key, 'scheduled_sending') : key); }

    Array.prototype.forEach.call(nodes, function(link) {
      link.addEventListener('click', function(ev) {
        ev.preventDefault();
        var id = this.getAttribute('data-id');
        var tsStr = this.getAttribute('data-ts') || '';
        if (!id) return;

        var defVal = '';
        if (tsStr) {
          var ts = parseInt(tsStr, 10);
          if (ts > 0) {
            var d = new Date(ts * 1000);
            defVal = d.getFullYear() + '-' +
                     pad(d.getMonth() + 1) + '-' +
                     pad(d.getDate()) + ' ' +
                     pad(d.getHours()) + ':' +
                     pad(d.getMinutes());
          }
        }

        var promptLabel = t('reschedule_prompt');
        var val = window.prompt(promptLabel, defVal);
        if (!val) return;

        var normalized = val.replace(' ', 'T');
        var d2 = new Date(normalized);
        if (isNaN(d2.getTime())) {
          window.alert(t('reschedule_invalid'));
          return;
        }

        var nowSec = Math.floor(Date.now() / 1000);
        var newTs = Math.floor(d2.getTime() / 1000);
        if (newTs <= nowSec) {
          window.alert(t('reschedule_future'));
          return;
        }

        rcmail.http_post(
          'plugin.scheduled_sending.queue_reschedule',
          { id: id, at_ts: newTs, _token: rcmail.env.request_token },
          rcmail.set_busy(true, 'loading')
        );

        // Show a localized toast immediately; server will also send one
        try {
          var msg = rcmail.gettext('queue_resched_ok', 'scheduled_sending');
          if (msg) {
            rcmail.display_message(msg, 'confirmation');
          }
        } catch (e) {}

        // Reload so that the table shows the updated time
        setTimeout(function() {
          window.location.reload();
        }, 600);
      });
    });
  }

  function init() {
    if (!window.rcmail) return;
    var rcmail = window.rcmail;

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        bind_delete_links(rcmail);
        bind_edit_links(rcmail);
      });
    } else {
      bind_delete_links(rcmail);
      bind_edit_links(rcmail);
    }
  }

  init();
})();
