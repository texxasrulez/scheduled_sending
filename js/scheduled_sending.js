/* scheduled_sending.js (v1.0.1) */
(function(){
  var DBG = !!(window.SCHEDULED_SENDING_DEBUG || (window.rcmail && rcmail.env && rcmail.env.scheduled_sending_debug));
  function log(){ if (DBG && window.console) { try { console.debug.apply(console, ['[SS]'].concat([].slice.call(arguments))); } catch(e) {} } }
  function pad(n){ return (n<10?'0':'')+n; }
  function t(key){ return (window.rcmail && rcmail.gettext) ? rcmail.gettext(key, 'scheduled_sending') : key; }

  function prefill(){
    var i = document.querySelector('#scheduled-sending-modal input[name="_schedule_at"]');
    if (!i) return;
    if (!i.value) {
      var d = new Date(Date.now()+30*60000);
      i.value = d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());
    }
  }

  function openModal(){
    var m = document.getElementById('scheduled-sending-modal');
    if (!m) { log('no modal'); return; }
    m.style.display = 'flex';
    prefill();
    var i = m.querySelector('input[name="_schedule_at"]');
    if (i) i.focus();
  }

  function closeModal(){
    var m = document.getElementById('scheduled-sending-modal');
    if (m) m.style.display = 'none';
  }

  function scheduleSubmit(){
    var m = document.getElementById('scheduled-sending-modal');
    var i = m && m.querySelector('input[name="_schedule_at"]');
    if (!i || !i.value) { if (window.rcmail) rcmail.display_message(rcmail.gettext('invaliddatetime','scheduled_sending'), 'error'); return; }

    var form = (window.rcmail && rcmail.gui_objects && rcmail.gui_objects.messageform) ||
               document.querySelector('form#compose-content form, form#composeform, form[name="form"]') ||
               document.forms[0];
    if (!form) { if (window.rcmail) rcmail.display_message(t('compose_form_not_ready'), 'error'); log('compose form missing'); return; }

    try { window.onbeforeunload = null; } catch(e){}
    var url = (window.rcmail ? rcmail.url('plugin.scheduled_sending.schedule') : '?_task=mail&_action=plugin.scheduled_sending.schedule');
    var fd = new FormData(form);

    // SS: ensure editor content is flushed and included
    try {
      if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave();
    } catch(e){}
    try {
      if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save();
    } catch(e){}
    // Collect body
    var is_html = false, body_html = '', body_text = '';
    try {
      if (window.tinyMCE && tinyMCE.activeEditor) {
        body_html = tinyMCE.activeEditor.getContent({format:'html'});
        body_text = tinyMCE.activeEditor.getContent({format:'text'});
        is_html = true;
      }
    } catch(e){}
    if (!body_text) {
      var ta = form.querySelector('textarea[name="_message"]');
      if (ta) body_text = ta.value;
    }
    if (is_html && body_html) {
      fd.set('_message_html', body_html);
      fd.set('_is_html', '1');
    } else if (body_text) {
      fd.set('_message', body_text);
      try { fd.delete('_message_html'); } catch(e){}
      try { fd.delete('_is_html'); } catch(e){}
    }

    fd.append('_schedule_at', i.value);

    fetch(url, { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(resp){ if (!resp.ok) throw new Error('HTTP '+resp.status); return resp.text(); })
      .then(function(){
        try { if (window.rcmail) rcmail.display_message(rcmail.gettext('scheduled','scheduled_sending'), 'confirmation'); } catch(e){}
        closeModal();
        setTimeout(function(){ try{ if(window.rcmail) rcmail.command('save'); }catch(e){} }, 50);
        setTimeout(function(){ try{ if(window.rcmail) rcmail.command('list'); }catch(e){} }, 350);
      })
      .catch(function(err){
        try { if (window.rcmail) rcmail.display_message(rcmail.gettext('schedulefail','scheduled_sending')+' '+err, 'error'); } catch(e){}
        log('schedule error', err);
      });
  }

  function bind(){
    // Button click
    var fab = document.getElementById('ss-fab');
    if (fab && !fab.__ssBound) {
      fab.addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); openModal(); }, true);
      fab.__ssBound = true;
      log('bound to #ss-fab');
    }
    // Modal buttons
    var confirm = document.getElementById('scheduled-sending-confirm');
    if (confirm && !confirm.__ssBound) {
      confirm.addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); scheduleSubmit(); }, true);
      confirm.__ssBound = true;
      log('bound confirm');
    }
    var cancel = document.getElementById('scheduled-sending-cancel');
    if (cancel && !cancel.__ssBound) {
      cancel.addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); closeModal(); }, true);
      cancel.__ssBound = true;
      log('bound cancel');
    }
    // Hotkey Alt+L
    if (!document.__ssHK) {
      document.addEventListener('keydown', function(e){ if (e.altKey && (e.key==='l' || e.key==='L')) { e.preventDefault(); openModal(); } }, true);
      document.__ssHK = true;
    }
  }

  // Bind now if DOM is ready
  if (document.readyState !== 'loading') { bind(); }
  // Bind on DOM ready
  document.addEventListener('DOMContentLoaded', bind, {once:true});
  // Bind on Roundcube init if available
  if (window.rcmail && rcmail.addEventListener) {
    try { rcmail.addEventListener('init', bind); } catch(e){}
  }
})();
