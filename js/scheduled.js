var __ss_isArray = Array.isArray || function(v){ return Object.prototype.toString.call(v) === '[object Array]'; };
function __ss_collect_attachment_meta(target){
  try{
    if (!target || typeof target !== 'object') return;
    var ids = __ss_isArray(target._attach_ids) ? target._attach_ids.slice() : [];
    var seen = {};
    for (var i=0;i<ids.length;i++){ seen[ids[i]] = true; }
    var probe = [];
    function push(id, name, size){
      if (id && !seen[id]) { ids.push(id); seen[id] = true; }
      probe.push({id:id||'', name:name||'', size:size||''});
    }
    var list = document.getElementById('attachment-list');
    if (list && list.querySelectorAll) {
      var items = list.querySelectorAll('li');
      for (var j=0;j<items.length;j++){
        var li = items[j];
        var aid = li.getAttribute('data-id') || li.getAttribute('rel') || li.id || '';
        var name = li.getAttribute('data-name') || li.textContent || '';
        var sz = li.getAttribute('data-size') || '';
        push(aid, (name || '').trim(), sz);
      }
    }
    if (window.rcmail && rcmail.env) {
      var envList = rcmail.env.attachments || rcmail.env.compose_attachments || null;
      if (envList) {
        if (__ss_isArray(envList)) {
          for (var k=0;k<envList.length;k++){
            var e = envList[k];
            if (!e) continue;
            push(e.id || e.uploadid || e._id || '', e.name || e.filename || '', e.size || e.filesize || '');
          }
        } else {
          for (var key in envList){
            if (!Object.prototype.hasOwnProperty.call(envList, key)) continue;
            var ent = envList[key];
            if (!ent) continue;
            push(ent.id || ent.uploadid || key, ent.name || ent.filename || '', ent.size || ent.filesize || '');
          }
        }
      }
    }
    if (ids.length) target._attach_ids = ids;
    if (probe.length) {
      try { target._ss_attach_probe = JSON.stringify(probe); } catch(e){}
    }
  } catch(_){}
}

/* scheduled.js - binds click handler only once; relies on inline fallback too */
(function(){
  if (window.__SS_JS_BOUND) return;
  window.__SS_JS_BOUND = true;

  function bind(){
    var btn = document.getElementById('ss-schedule-btn');
    if (!btn) return;
    function t(key) { return (window.rcmail && rcmail.gettext) ? rcmail.gettext(key, 'scheduled_sending') : key; }
    var inflight = false;
    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      if (inflight) return;
      inflight = true;
      setTimeout(function(){ inflight = false; }, 1200);
      // if inline binder already attached, let it do the work
      if (typeof window.__SS_BOUND !== 'undefined') return;

      var when = document.getElementById('ss-when');
      if (!when || !when.value) { if (window.rcmail) rcmail.display_message(t('pick_future_time'), 'error'); return; }
      var d = new Date(when.value);
      /* SS: toast delay v61 */
      try {
        (function(){
          function pad(n){return (n<10?'0':'')+n}
          var dt=d;
          var localText;
          try { localText = new Intl.DateTimeFormat(undefined,{month:'short',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit'}).format(dt); }
          catch(e){ var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()]; localText=m+' '+pad(dt.getDate())+', '+dt.getFullYear()+' '+((dt.getHours()%12)||12)+':'+pad(dt.getMinutes())+' '+(dt.getHours()<12?'AM':'PM'); }
          window.__SS_LAST_LOCAL_TEXT = localText;
        })();
      } catch(e) {}

      if (isNaN(d.getTime()) || d.getTime() <= Date.now()) { if (window.rcmail) rcmail.display_message(t('pick_future_time'), 'error'); return; }

      var form = document.getElementById('composeform') || btn.closest('form');
      if (!form) { if (window.rcmail) rcmail.display_message(t('compose_form_not_ready'), 'error'); return; }

      var data = {};
      var f = new FormData(form);
      function copyField(name, as){ if (f.has(name)) data[as||name] = f.get(name); }
      copyField('_id'); copyField('_from'); copyField('_to'); copyField('_cc'); copyField('_bcc'); copyField('_subject'); copyField('_is_html');
      data['_schedule_at'] = when.value;
      data['_schedule_ts'] = Math.floor(d.getTime()/1000);
      data['_schedule_tzoffset'] = - (new Date().getTimezoneOffset());

      // SS helper: decide if HTML is semantically plain (only <p>/<br> and optional RC sig placeholder)
      function __ss_is_plain_html(html, text){
        try{
          if (!html) return true;
          var s = (''+html);
          // remove Roundcube signature placeholder blocks
          s = s.replace(/<div[^>]*id=['"]?_rc_sig['"]?[^>]*>[\s\S]*?<\/div>/gi, '');
          // strip leading/trailing whitespace and non-breaking spaces
          s = s.replace(/&nbsp;/gi,' ').replace(/\s+/g,' ');
          // only allow p and br tags; detect others
          if (/<(?!\/?(p|br)\b)/i.test(s)) return false;
          // convert basic HTML structure to text for rough equivalence
          var t = s;
          // normalize paragraphs to double newlines
          t = t.replace(/<\/p>\s*<p[^>]*>/gi, '\n\n');
          t = t.replace(/<br\s*\/?>/gi, '\n');
          t = t.replace(/<\/?p[^>]*>/gi, '');
          // remove remaining tags (should be none)
          t = t.replace(/<[^>]+>/g, '');
          t = t.replace(/\s+\n/g, '\n').replace(/\n\s+/g, '\n').trim();
          var plain = (''+(text||'')).replace(/\s+\n/g,'\n').replace(/\n\s+/g,'\n').trim();
          // Treat as plain if equal (or equal ignoring trailing newline)
          if (t === plain || t === plain.replace(/\n+$/,'')) return true;
          return false;
        }catch(_){ return false; }
      }


      // SS: ensure body is included
      try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
      try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
      (function(){
        var html = '', text = '';
        try {
          if (window.tinyMCE && tinyMCE.activeEditor) {
            html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
            text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
          }
        } catch(_){}
        if (!text) {
          var ta = form && form.querySelector && form.querySelector('textarea[name="_message"]');
          if (ta) text = ta.value || '';
        }
        if (html && !__ss_is_plain_html(html, text)) {
          data['_message_html'] = html;
          data['_is_html'] = 1;
        } else {
          data['_message'] = text || '';
          try { delete data['_message_html']; } catch(_){}
          try { delete data['_is_html']; } catch(_){}
        }
      })();

      __ss_collect_attachment_meta(data);

      
/* SS: client UTC encode v57 */
try {
  if (isSched && data && typeof data === 'object') {
    var utcStr = null;
    function pad(n){ return (n<10?'0':'')+n; }
    function toUTCString(dt){
      return dt.getUTCFullYear()+'-'+pad(dt.getUTCMonth()+1)+'-'+pad(dt.getUTCDate())+' '+pad(dt.getUTCHours())+':'+pad(dt.getUTCMinutes())+':'+pad(dt.getUTCSeconds());
    }
    if (typeof data._schedule_ts === 'number' && isFinite(data._schedule_ts)) {
      var dt = new Date(data._schedule_ts * 1000); // epoch seconds (local or absolute? we treat as absolute ms since epoch)
      utcStr = toUTCString(dt);
    } else if (typeof data._schedule_at === 'string') {
      var m = (data._schedule_at+'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
      if (m) {
        var y=+m[1], mo=+m[2]-1, d=+m[3], h=+m[4], mi=+m[5], s=m[6]?+m[6]:0;
        var dt = new Date(y,mo,d,h,mi,s,0); // interpret as local wall time
        utcStr = toUTCString(dt);
      }
    }
    if (utcStr) {
      data.scheduled_at = utcStr;
      data.scheduled_at_is_utc = 1;
    }
    // Always include tz_offset for server-side sanity
    if (typeof data.tz_offset === 'undefined') {
      data.tz_offset = (new Date()).getTimezoneOffset();
    }
  }
} catch(_){}

      if (window.rcmail && typeof rcmail.http_post === 'function') {
        
        /* SS: pre-save for draft_uid v1 (non-disruptive) */
        try {
          var form = document.getElementById('composeform') || btn.closest('form');
          if (form && !form.querySelector('#ss-draft-uid-hidden')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = '_draft_uid'; hidden.id = 'ss-draft-uid-hidden';
            form.appendChild(hidden);
          }
          var done = false;
          var setUID = function(){
            try {
              var uid = (rcmail.env && (rcmail.env.compose_draft_uid || rcmail.env.draft_uid || rcmail.env.draft_id)) || null;
              if (uid) form.querySelector('#ss-draft-uid-hidden').value = uid;
            } catch(e){}
          };
          var onSaved = function(){
            if (done) return;
            done = true;
            try { setUID(); } catch(e){}
            // Continue immediately
            if (window.rcmail && typeof rcmail.http_post === 'function') {
              __ss_collect_attachment_meta(data);
              rcmail.http_post('plugin.scheduled_sending.schedule', data);
            }
          };
          // Listen for draft_saved
          try { rcmail.addEventListener && rcmail.addEventListener('draft_saved', onSaved); } catch(e){}
          try { rcmail.addEventListener && rcmail.addEventListener('plugin.draft_saved', onSaved); } catch(e){}
          // Trigger save and set a short failsafe
          try { rcmail.command && rcmail.command('save'); } catch(e){}
          setTimeout(function(){ if (!done) onSaved(); }, 600);
          return; // prevent double http_post below; onSaved will call it
        } catch(e){ /* fall through to normal path */ }

        __ss_collect_attachment_meta(data);

        rcmail.http_post('plugin.scheduled_sending.schedule', data);
      }
    }, { once:false });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();


/* SS NAV PATCH ACTIVE v41 (http_post wrapper) */
(function(){
  try{
    if (window.rcmail && !window.__SS_WRAP_BOUND) {
      window.__SS_WRAP_BOUND = true;
      var __orig_post = rcmail.http_post;
      rcmail.http_post = function(action, data, lock){
        var isSched = (action === 'plugin.scheduled_sending.schedule');
        if (isSched) {
          // SS: enforce body fields on final post (prefer plain text when HTML is trivial wrappers)
          try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
          try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
          try {
            if (data && typeof data === 'object' && (!data._message && !data._message_html)) {
              var form = document.getElementById('composeform') || document.querySelector('form#compose-content form');
              var html = '', text = '';
              try {
                if (window.tinyMCE && tinyMCE.activeEditor) {
                  html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
                  text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
                }
              } catch(_){}
              if (!text && form) {
                var ta = form.querySelector('textarea[name="_message"]');
                if (ta) text = ta.value || '';
              }
              if (html && !__ss_is_plain_html(html, text)) { data._message_html = html; data._is_html = 1; }
              else { data._message = text || ''; }
            }
          } catch(_){}


          // SS: enforce body fields on final post
          try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
          try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
          try {
            if (data && typeof data === 'object' && (!data._message && !data._message_html)) {
              var form = document.getElementById('composeform') || document.querySelector('form#compose-content form');
              var html = '', text = '';
              try {
                if (window.tinyMCE && tinyMCE.activeEditor) {
                  html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
                  text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
                }
              } catch(_){}
              if (!text && form) {
                var ta = form.querySelector('textarea[name="_message"]');
                if (ta) text = ta.value || '';
              }
              if (html && html.replace(/<[^>]*>/g,'').trim() !== '') { data._message_html = html; data._is_html = 1; }
              else if (typeof text === 'string') { data._message = text; }
            }
          } catch(_){}

          __ss_collect_attachment_meta(data);
          /* TZ attach */

/* SS: client UTC encode */
try {
  if (isSched && data && typeof data === 'object' && typeof data.scheduled_at === 'string') {
    var m = (data.scheduled_at+'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (m) {
      var y=+m[1], mo=+m[2]-1, d=+m[3], h=+m[4], mi=+m[5], s= m[6]?+m[6]:0;
      var dt = new Date(y, mo, d, h, mi, s, 0); // local wall time
      function pad(n){ return (n<10?'0':'')+n; }
      var utc = dt.getUTCFullYear()+'-'+pad(dt.getUTCMonth()+1)+'-'+pad(dt.getUTCDate())+' '+pad(dt.getUTCHours())+':'+pad(dt.getUTCMinutes())+':'+pad(dt.getUTCSeconds());
      data.scheduled_at = utc;
      data.scheduled_at_is_utc = 1;
    }
  }
} catch(_){}

          try { if (data && typeof data === 'object') data.tz_offset = (new Date()).getTimezoneOffset(); } catch(_) {}

          try{
            if (data && typeof data === 'object') data._unlock = 1;
            rcmail.env.compose_submit = true;
            rcmail.env.compose_changes = false;
            if (typeof rcmail._unsaved_changes !== 'undefined') rcmail._unsaved_changes = false;
            if (typeof rcmail.env.exit_warning !== 'undefined') rcmail.env.exit_warning = false;
            if (typeof rcmail.unload_cancel !== 'undefined') rcmail.unload_cancel = true;
            if (window.onbeforeunload) window.onbeforeunload = null;
          }catch(_){}
        }
        var ret = __orig_post.apply(rcmail, arguments);
        if (isSched) {
          // SS: enforce body fields on final post (prefer plain text when HTML is trivial wrappers)
          try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
          try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
          try {
            if (data && typeof data === 'object' && (!data._message && !data._message_html)) {
              var form = document.getElementById('composeform') || document.querySelector('form#compose-content form');
              var html = '', text = '';
              try {
                if (window.tinyMCE && tinyMCE.activeEditor) {
                  html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
                  text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
                }
              } catch(_){}
              if (!text && form) {
                var ta = form.querySelector('textarea[name="_message"]');
                if (ta) text = ta.value || '';
              }
              if (html && !__ss_is_plain_html(html, text)) { data._message_html = html; data._is_html = 1; }
              else { data._message = text || ''; }
            }
          } catch(_){}


          // SS: enforce body fields on final post
          try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
          try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
          try {
            if (data && typeof data === 'object' && (!data._message && !data._message_html)) {
              var form = document.getElementById('composeform') || document.querySelector('form#compose-content form');
              var html = '', text = '';
              try {
                if (window.tinyMCE && tinyMCE.activeEditor) {
                  html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
                  text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
                }
              } catch(_){}
              if (!text && form) {
                var ta = form.querySelector('textarea[name="_message"]');
                if (ta) text = ta.value || '';
              }
              if (html && html.replace(/<[^>]*>/g,'').trim() !== '') { data._message_html = html; data._is_html = 1; }
              else if (typeof text === 'string') { data._message = text; }
            }
          } catch(_){}

          setTimeout(function(){
            try{
              if (rcmail.command) {
                rcmail.command('compose-cancel') || rcmail.command('cancel') || rcmail.command('close');
              }
              setTimeout(function(){
                try{
                  if (rcmail.list_mailbox) {
                    rcmail.list_mailbox(rcmail.env.mailbox, rcmail.env.current_page);
                  } else if (rcmail.command) {
                    rcmail.command('list');
                  }
                }catch(_){}
              }, 20);
            }catch(_){}
          }, 10);
        }
        return ret;
      };
      // Console sanity check: rcmail.http_post.__ss_wrapped === true
      rcmail.http_post.__ss_wrapped = true;
    }
  }catch(_){}
})();
