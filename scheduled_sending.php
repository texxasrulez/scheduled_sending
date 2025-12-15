<?php
require_once __DIR__ . '/worker_send_due.inc.php';
require_once __DIR__ . '/queue.inc.php';
/**
 * Roundcube Scheduled Sending - clean rebuild (hotfix16b)
 * Compatible with Roundcube 1.6.x
 */
class scheduled_sending extends rcube_plugin
{
    
    // Unified debug logger; respects config('scheduled_debug', false)
    private function ss_debug($payload) {
        try {
            $rc = $this->rc ?: rcmail::get_instance();
            if ($rc && $rc->config->get('scheduled_debug', false) && function_exists('write_log')) {
                if (!is_scalar($payload)) {
                    $payload = json_encode($payload);
                }
                write_log('scheduled_sending_debug', $payload);
            }
        } catch (Exception $e) {
            // never let logging break sending
        }
    }
// SS: normalize trivial HTML to text/plain, strip RC signature placeholder
    private function _ss_is_semantically_plain_html($html, $text_guess='')
    {
        if (!is_string($html) || $html === '') return true;
        $s = $html;
        // strip Roundcube signature placeholder blocks (any content inside)
        $s = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $s);
        // normalize nbsp and whitespace
        $s = str_ireplace('&nbsp;', ' ', $s);
        // Only p/br allowed? If other tags present, it's not plain
        if (preg_match('~<(?!/?(?:p|br)\b)~i', $s)) {
            return false;
        }
        // Reduce to text approximation
        $t = $s;
        $t = preg_replace('~</p>\s*<p[^>]*>~i', "\n\n", $t);
        $t = preg_replace('~<br\s*/?>~i', "\n", $t);
        $t = preg_replace('~</?p[^>]*>~i', '', $t);
        $t = preg_replace('~<[^>]+>~', '', $t); // any leftover
        $t = preg_replace("~\r\n?~", "\n", $t);
        $t = preg_replace("~[ \t]+\n~", "\n", $t);
        $t = trim($t);
        $plain = is_string($text_guess) ? trim(preg_replace("~\r\n?~", "\n", $text_guess)) : '';
        if ($plain !== '') {
            if ($t === $plain || rtrim($t, "\n") === rtrim($plain, "\n")) return true;
        }
        // If visible text equals the original stripped of HTML tags, call it plain
        $only_text = trim(strip_tags($s));
        return ($only_text === $t);
    }

    private function _ss_text_from_trivial_html($html)
    {
        $s = (string)$html;
        $s = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $s);
        $s = str_ireplace('&nbsp;', ' ', $s);
        $s = preg_replace('~</p>\s*<p[^>]*>~i', "\n\n", $s);
        $s = preg_replace('~<br\s*/?>~i', "\n", $s);
        $s = preg_replace('~</?p[^>]*>~i', '', $s);
        $s = preg_replace('~<[^>]+>~', '', $s);
        // Collapse multiple blank lines to at most 2
        $s = preg_replace("~\n{3,}~", "\n\n", $s);
        // Trim trailing whitespace
        $s = preg_replace("~[ \t]+\n~", "\n", $s);
        return trim($s);
    }

    use scheduled_sending_worker_trait, scheduled_sending_queue_trait;
    public $task = 'login|mail|settings';
    private $rc;
    private $logname = 'scheduled_sending';
    
    /** 
     * Try to fetch the full raw MIME (with attachments) from the Drafts folder
     * matching the current Subject and most recent date.
     * Returns string MIME on success, or empty string on failure.
     */
    
    /** 
     * Try to fetch the full raw MIME (with attachments) from the Drafts folder.
     * Strategy:
     *  - exact Subject match, pick latest
     *  - else scan recent N drafts and pick the first with same Subject, and multipart content
     *  - require presence of "Content-Type: multipart/" to avoid picking a body-only draft
     * Logs decisions to scheduled_sending for debugging.
     */
    private function _ss_try_fetch_draft_mime($subject, $from_hint = '')
    {
        try {
            $rc = $this->rc ?: rcmail::get_instance();
            $cfg = $rc->config;
            $storage = $rc->get_storage();
            $drafts = $cfg->get('drafts_mbox', 'Drafts');
            if (empty($drafts)) return '';

            if (!$storage->folder_exists($drafts)) return '';
            $storage->set_folder($drafts);

            $uid_candidate = null;
            $picked_via = 'none';

            // 1) Exact Subject header search
            $index = $storage->search($drafts, 'HEADER', array('Subject' => $subject));
            if ($index && !empty($index->count)) {
                $last = null;
                foreach ($index->get() as $msg) { $last = $msg; }
                if ($last && isset($last->uid)) { $uid_candidate = $last->uid; $picked_via = 'subject_exact'; }
            }

            // 2) Fallback: recent drafts, prefer multipart
            if (!$uid_candidate) {
                $hdrs = $storage->list_messages($drafts, 1, null, null, null, 'DATE DESC', 50);
                if (is_array($hdrs)) {
                    foreach ($hdrs as $h) {
                        $h_subj = isset($h->subject) ? trim($h->subject) : '';
                        if ($h_subj === trim($subject)) {
                            $uid_candidate = $h->uid;
                            $picked_via = 'recent_subject';
                            break;
                        }
                    }
                }
            }

            if (!$uid_candidate) {
                $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime no_candidate','subject'=>$subject));
                return '';
            }

            // Pull raw source (prefer get_raw_message)
            $raw = '';
            if (method_exists($storage, 'get_raw_message')) {
                try { $raw = (string)$storage->get_raw_message($uid_candidate, $drafts); } catch (\Exception $e) { $raw = ''; }
            }
            if ($raw === '' && method_exists($storage, 'get_raw_body')) {
                try { $raw = (string)$storage->get_raw_body($uid_candidate, $drafts); } catch (\Exception $e) { $raw = ''; }
            }
            if ($raw === '') {
                $msgobj = $storage->get_message($uid_candidate, $drafts);
                if ($msgobj && isset($msgobj->headers->raw) && isset($msgobj->body)) {
                    $raw = (string)$msgobj->headers->raw . "\r\n\r\n" . (string)$msgobj->body;
                } elseif ($msgobj && !empty($msgobj->body)) {
                    $raw = (string)$msgobj->body;
                }
            }

            if ($raw === '') {
                $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime empty_raw','uid'=>$uid_candidate,'via'=>$picked_via));
                return '';
            }

            // Heuristic: require MIME headers and preferably multipart if we expect attachments
            $has_mime = (stripos($raw, "MIME-Version:") !== false);
            $is_multipart = (stripos($raw, "Content-Type: multipart/") !== false);
            $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime picked','uid'=>$uid_candidate,'via'=>$picked_via,'mime'=>$has_mime,'multipart'=>$is_multipart));

            if (!$has_mime) return '';
            // Don't *require* multipart — user might send no attachments — but prefer it
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime fail','err'=>$e->getMessage()));
            return '';
        }
    }

    private function _ss_get_compose_context($compose_id)
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
        } catch (\Exception $e) {
        }

        $sess = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : array();
        $ctx = array('id' => $compose_id, 'bucket' => null, 'session' => $sess);

        if (empty($ctx['id'])) {
            $pools = array();
            if (isset($sess['compose_data']) && is_array($sess['compose_data'])) {
                $pools[] = $sess['compose_data'];
            }
            if (isset($sess['rcmail.compose']) && is_array($sess['rcmail.compose'])) {
                $pools[] = $sess['rcmail.compose'];
            }
            if (isset($sess['rcmail']) && isset($sess['rcmail']['compose']) && is_array($sess['rcmail']['compose'])) {
                $pools[] = $sess['rcmail']['compose'];
            }
            foreach ($pools as $pool) {
                if (!is_array($pool) || !count($pool)) continue;
                $keys = array_keys($pool);
                if (!empty($keys)) {
                    $ctx['id'] = end($keys);
                    break;
                }
            }
        }

        $compose_keys = array();
        foreach ($sess as $k => $v) {
            if (stripos($k, 'compose') !== false) {
                $compose_keys[] = $k;
            }
        }
        if (!empty($compose_keys)) {
            $this->ss_debug(array('msg' => 'compose_session_keys', 'keys' => $compose_keys));
        }

        $bucket = null;
        $cid = $ctx['id'];
        if ($cid) {
            $direct = 'compose_data_' . $cid;
            if (isset($sess[$direct]) && is_array($sess[$direct])) {
                $bucket = $sess[$direct];
            } elseif (isset($sess['compose_data']) && isset($sess['compose_data'][$cid])) {
                $bucket = $sess['compose_data'][$cid];
            } elseif (isset($sess['rcmail.compose']) && isset($sess['rcmail.compose'][$cid])) {
                $bucket = $sess['rcmail.compose'][$cid];
            } elseif (isset($sess['rcmail']) && isset($sess['rcmail']['compose']) && isset($sess['rcmail']['compose'][$cid])) {
                $bucket = $sess['rcmail']['compose'][$cid];
            }
        }

        if (is_array($bucket)) {
            $ctx['bucket'] = $bucket;
            $this->ss_debug(array('msg' => 'compose_bucket_keys', 'compose_id' => $cid, 'keys' => array_keys($bucket)));
        } elseif ($cid) {
            $this->ss_debug(array('msg' => 'compose_bucket_missing', 'compose_id' => $cid));
        }

        return $ctx;
    }

    private function _ss_body_is_empty($body, $is_html)
    {
        if (!is_string($body) || $body === '') return true;
        $text = $body;
        if ($is_html) {
            $text = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $text);
            $text = strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\xC2\xA0/u", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text) === '';
    }

    private function _ss_looks_like_base64_blob($string)
    {
        if (!is_string($string) || $string === '') return false;
        $sample = substr($string, 0, 256);
        $sample_stripped = preg_replace('/[\r\n\s]+/', '', $sample);
        if (strlen($sample_stripped) < 200) return false;
        return (bool)preg_match('/^[A-Za-z0-9+\/=]+$/', $sample_stripped);
    }

    private function _ss_extract_body_candidate($node)
    {
        if (!is_array($node)) return null;
        $stack = array(array('', $node));
        $visited = 0; $max_nodes = 4000;
        $best = null; $global_html = null;

        while ($stack) {
            $cur = array_pop($stack);
            $path = $cur[0];
            $val = $cur[1];
            if (!is_array($val)) continue;
            if (++$visited > $max_nodes) break;
            foreach ($val as $k => $v) {
                $key = is_string($k) ? $k : (string)$k;
                $lk = strtolower($key);
                $next_path = $path === '' ? $lk : $path . '.' . $lk;
                if ($lk === 'is_html' && !is_array($v) && $global_html === null) {
                    $global_html = (bool)$v;
                }
                if (is_array($v)) {
                    $stack[] = array($next_path, $v);
                    continue;
                }
                if (!is_string($v)) continue;
                $trim = trim($v);
                if ($trim === '') continue;
                if ($this->_ss_looks_like_base64_blob($trim)) continue;

                $length = strlen($trim);
                $score = null; $html = false;
                $pineedle = strpos($lk, 'plain') !== false;
                $msgneedle = strpos($lk, 'message') !== false;

                if (in_array($lk, array('html', 'body_html', 'message_html', 'htmlbody'))) {
                    $score = 100; $html = true;
                } elseif ($msgneedle && strpos($lk, 'html') !== false) {
                    $score = 95; $html = true;
                } elseif (strpos($lk, 'html') !== false && strpos($lk, 'subject') === false) {
                    $score = 85; $html = true;
                } elseif (in_array($lk, array('body', 'text', 'message', 'plain'))) {
                    $score = $pineedle ? 75 : 70;
                } elseif (strpos($lk, 'body') !== false || strpos($lk, 'text') !== false) {
                    $score = 60;
                }

                if ($score === null) continue;
                if ($length > 1000) {
                    $score += 5;
                } elseif ($length > 400) {
                    $score += 2;
                }

                $looks_html = $html;
                if (!$looks_html) {
                    if ($global_html) $looks_html = true;
                    elseif (strip_tags($trim) !== $trim) $looks_html = true;
                }
                if ($best === null || $score > $best['score']) {
                    $best = array('body' => $trim, 'is_html' => $looks_html, 'score' => $score, 'path' => $next_path);
                }
            }
        }
        return $best;
    }

    private function _ss_resolve_body_from_context($ctx, $body, $is_html)
    {
        if (!$this->_ss_body_is_empty($body, $is_html)) {
            return array('body' => $body, 'is_html' => $is_html);
        }
        $candidate = null;
        if (is_array($ctx)) {
            if (isset($ctx['bucket']) && is_array($ctx['bucket'])) {
                $candidate = $this->_ss_extract_body_candidate($ctx['bucket']);
            }
            if (!$candidate && isset($ctx['session']) && is_array($ctx['session'])) {
                $candidate = $this->_ss_extract_body_candidate($ctx['session']);
            }
        }
        if ($candidate && !empty($candidate['body'])) {
            $this->ss_debug(array('msg' => 'compose_body_hydrated', 'is_html' => (int)$candidate['is_html'], 'path' => isset($candidate['path']) ? $candidate['path'] : '?', 'len' => strlen($candidate['body'])));
            return array('body' => $candidate['body'], 'is_html' => $candidate['is_html']);
        }
        return array('body' => $body, 'is_html' => $is_html);
    }

    /**
     * Build full MIME using Roundcube compose session (attachments in $_SESSION).
     * Returns raw RFC822 or empty string on failure.
     */
    
    /**
     * Build MIME from Roundcube compose session.
     * - Recursively searches $_SESSION for attachment-like entries under any compose bucket.
     * - If $attach_ids is provided (array of ids/keys), only include those.
     */
    private function _ss_build_mime_from_compose($compose_id, $from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_ids = null, $compose_ctx = null)
    {
        try {
            if ($compose_ctx === null || !is_array($compose_ctx)) {
                $compose_ctx = $this->_ss_get_compose_context($compose_id);
            }
            $compose_id = isset($compose_ctx['id']) ? $compose_ctx['id'] : $compose_id;
            $sess = (isset($compose_ctx['session']) && is_array($compose_ctx['session'])) ? $compose_ctx['session'] : ((isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : array());
            $bucket = isset($compose_ctx['bucket']) ? $compose_ctx['bucket'] : null;
            if ($bucket === null && $compose_id) {
                $this->ss_debug(array('msg'=>'compose_bucket_missing','compose_id'=>$compose_id));
            }

            $attachments = array();
            $seen_attachments = array();
            $collect_attachment = function($key, $arr) use (&$attachments, &$seen_attachments) {
                if (!is_array($arr)) return;
                $name = isset($arr['name']) ? $arr['name'] : (isset($arr['filename']) ? $arr['filename'] : 'file');
                $type = isset($arr['mimetype']) ? $arr['mimetype'] : (isset($arr['type']) ? $arr['type'] : 'application/octet-stream');
                $path = null;
                if (!empty($arr['path'])) $path = $arr['path'];
                elseif (!empty($arr['file'])) $path = $arr['file'];
                elseif (!empty($arr['tmp_name'])) $path = $arr['tmp_name'];
                $inline_data = null;
                $inline_b64  = null;
                if ($path && !@is_readable($path)) {
                    $path = null;
                }
                if (!$path) {
                    if (isset($arr['content_b64'])) $inline_b64 = $arr['content_b64'];
                    elseif (isset($arr['content'])) $inline_data = $arr['content'];
                    elseif (isset($arr['data'])) $inline_data = $arr['data'];
                    elseif (isset($arr['body'])) $inline_data = $arr['body'];
                    elseif (isset($arr['contents'])) $inline_data = $arr['contents'];
                }
                $has_inline = ($inline_b64 !== null) || ($inline_data !== null && $inline_data !== '');
                if (!$path && !$has_inline) {
                    return;
                }
                $fingerprint = $path ? ('path:' . $path) : ('mem:' . ($inline_b64 !== null ? sha1($inline_b64) : sha1((string)$inline_data)));
                if (isset($seen_attachments[$fingerprint])) {
                    return;
                }
                $seen_attachments[$fingerprint] = true;
                $attachments[$key] = array(
                    'name' => $name,
                    'type' => $type ?: 'application/octet-stream',
                    'path' => $path,
                    'data' => $inline_data,
                    'data_b64' => $inline_b64,
                );
            };

            if (is_array($bucket) && isset($bucket['attachments']) && is_array($bucket['attachments'])) {
                foreach ($bucket['attachments'] as $akey=>$aval) {
                    if (is_array($aval)) {
                        $this->ss_debug(array('msg'=>'compose_bucket_attachment_keys','aid'=>$akey,'keys'=>array_keys($aval)));
                        if (empty($aval['path']) && empty($aval['file']) && empty($aval['tmp_name']) && isset($aval['id'])) {
                            try {
                                $rc = $this->rc ?: rcmail::get_instance();
                                $tmpdir = $rc->config->get('temp_dir');
                                if ($tmpdir) {
                                    $try = rtrim($tmpdir, '/').'/'.$aval['id'];
                                    if (is_readable($try)) $aval['path'] = $try;
                                    else {
                                        $alt = rtrim($tmpdir, '/').'/rcmattach-'.$aval['id'];
                                        if (is_readable($alt)) $aval['path'] = $alt;
                                    }
                                }
                            } catch (\Exception $e) {}
                        }
                        $collect_attachment($akey, $aval);
                    }
                }
            }

            // Gather possible attachment arrays by recursive walk (depth-limited)
            $visited = 0;
            $max_nodes = 2000;

            $walk = function($node, $path='') use (&$walk, &$attachments, &$visited, $max_nodes, $collect_attachment) {
                if ($visited++ > $max_nodes) return;
                if (is_array($node)) {
                    // detect array-of-attachments
                    $keys = array_keys($node);
                    $has_file_ref = (isset($node['path']) || isset($node['file']) || isset($node['tmp_name']));
                    $has_inline = (isset($node['content']) || isset($node['data']) || isset($node['body']) || isset($node['contents']) || isset($node['content_b64']));
                    $is_attachment = (isset($node['name']) && ($has_file_ref || $has_inline));
                    if ($is_attachment) {
                        $collect_attachment($path, $node);
                        return;
                    }
                    // walk children
                    foreach ($node as $k=>$v) {
                        $npath = ($path === '') ? (string)$k : ($path.'.'.$k);
                        $walk($v, $npath);
                    }
                }
            };

            // Prefer obvious compose buckets first
            $candidates = array();
            $keys_try = array(
                'compose_data_'.$compose_id,
                'compose_data',
                'rcmail.compose',
                'compose',
                'rcmail',
            );
            foreach ($keys_try as $ck) {
                if (isset($sess[$ck])) $candidates[] = $sess[$ck];
                // nested dotted path e.g. rcmail.compose
                if (strpos($ck,'.') !== false) {
                    list($a,$b) = explode('.', $ck, 2);
                    if (isset($sess[$a]) && is_array($sess[$a]) && isset($sess[$a][$b])) $candidates[] = $sess[$a][$b];
                }
            }
            if (!count($candidates)) $candidates[] = $sess;

            foreach ($candidates as $cand) $walk($cand, '');

            // If client posted specific attach ids, filter by those
            if (is_array($attach_ids) && count($attach_ids)) {
                $filtered = array();
                foreach ($attachments as $k=>$a) {
                    foreach ($attach_ids as $want) {
                        if ($k === $want || substr($k, -strlen($want)) === $want) {
                            $filtered[$k] = $a; break;
                        }
                    }
                }
                if (count($filtered)) $attachments = $filtered;
            }

            $this->ss_debug(array('msg'=>'compose_session_found_attach','count'=>count($attachments)));

            if (!count($attachments)) return '';

            // Build multipart/mixed as before
            $nl = "\r\n";
            $boundary = '=_SS_' . bin2hex(random_bytes(12));

            $headers = array();
            if ($from !== '') $headers[] = 'From: ' . $from;
            if ($to !== '') $headers[] = 'To: ' . $to;
            if ($cc !== '') $headers[] = 'Cc: ' . $cc;
            if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
            if ($subject !== '') $headers[] = 'Subject: ' . $subject;
            $headers[] = 'Message-ID: <ss-' . bin2hex(random_bytes(8)) . '@localhost>';
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $body_part  = '--' . $boundary . $nl;
            $body_part .= ($is_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8') . $nl;
            $body_part .= 'Content-Transfer-Encoding: 8bit' . $nl . $nl;
            $body_part .= (string)$body . $nl;

            $att_parts = '';
            foreach ($attachments as $k=>$a) {
                $data = '';
                if (!empty($a['path']) && @is_readable($a['path'])) {
                    $data = @file_get_contents($a['path']);
                } elseif (!empty($a['data_b64'])) {
                    $decoded = base64_decode($a['data_b64'], true);
                    if ($decoded !== false) $data = $decoded;
                } elseif (isset($a['data'])) {
                    $data = $a['data'];
                }
                if ($data === false || $data === null) continue;
                if ($data === '' && $data !== '0') continue;
                $b64 = rtrim(chunk_split(base64_encode($data)));
                $fname = addcslashes($a['name'], '\"\\');
                $ctype = $a['type'] ?: 'application/octet-stream';
                $att_parts .= '--' . $boundary . $nl
                    . 'Content-Type: ' . $ctype . '; name="' . $fname . '"' . $nl
                    . 'Content-Transfer-Encoding: base64' . $nl
                    . 'Content-Disposition: attachment; filename="' . $fname . '"' . $nl . $nl
                    . $b64 . $nl;
            }
            $closing = '--' . $boundary . '--' . $nl;

            $raw = implode($nl, $headers) . $nl . $nl . $body_part . $att_parts . $closing;
            $this->ss_debug(array('msg'=>'compose_mime_built','compose_id'=>$compose_id,'attachments'=>count($attachments)));
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'compose_mime_error','err'=>$e->getMessage()));
            return '';
        }
    }
    
    /**
     * Build multipart from posted JSON attachments (name, type, content_b64).
     */
    private function _ss_build_mime_from_json($from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_list)
    {
        try {
            if (!is_array($attach_list) || !count($attach_list)) return '';

            $nl = "\r\n";
            $boundary = '=_SSJSON_' . bin2hex(random_bytes(12));

            $headers = array();
            if ($from !== '') $headers[] = 'From: ' . $from;
            if ($to !== '') $headers[] = 'To: ' . $to;
            if ($cc !== '') $headers[] = 'Cc: ' . $cc;
            if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
            if ($subject !== '') $headers[] = 'Subject: ' . $subject;
            $headers[] = 'Message-ID: <ss-' . bin2hex(random_bytes(8)) . '@localhost>';
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $body_part  = '--' . $boundary . $nl;
            $body_part .= ($is_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8') . $nl;
            $body_part .= 'Content-Transfer-Encoding: 8bit' . $nl . $nl;
            $body_part .= (string)$body . $nl;

            $att_parts = '';
            foreach ($attach_list as $a) {
                if (!isset($a['content_b64'])) continue;
                $b64 = preg_replace('/\s+/', '', (string)$a['content_b64']);
                $fname = isset($a['name']) ? addcslashes($a['name'], '\"\\') : 'file';
                $ctype = isset($a['type']) && $a['type'] ? $a['type'] : 'application/octet-stream';
                $att_parts .= '--' . $boundary . $nl
                    . 'Content-Type: ' . $ctype . '; name="' . $fname . '"' . $nl
                    . 'Content-Transfer-Encoding: base64' . $nl
                    . 'Content-Disposition: attachment; filename="' . $fname . '"' . $nl . $nl
                    . chunk_split($b64) . $nl;
            }
            $closing = '--' . $boundary . '--' . $nl;

            $raw = implode($nl, $headers) . $nl . $nl . $body_part . $att_parts . $closing;
            $this->ss_debug(array('msg'=>'json_mime_built','attachments'=>count($attach_list)));
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'json_mime_error','err'=>$e->getMessage()));
            return '';
        }
    }

    private function _ss_mime_attachment_count($raw)
    {
        if (!is_string($raw) || $raw === '') return 0;
        if (preg_match_all('/Content-Disposition:\s*attachment/iu', $raw, $m)) {
            return count($m[0]);
        }
        return 0;
    }

    private function _ss_mime_body_length($raw)
    {
        if (!is_string($raw) || $raw === '') return 0;
        $payload = '';
        if (preg_match('/boundary="([^"]+)"/i', $raw, $mm)) {
            $boundary = $mm[1];
            $parts = preg_split('/--' . preg_quote($boundary, '/') . '/', $raw);
            if (count($parts) > 1) {
                $first = $parts[1];
                $sections = preg_split("/\r?\n\r?\n/", $first, 2);
                if (isset($sections[1])) $payload = $sections[1];
            }
        }
        if ($payload === '') {
            $sections = preg_split("/\r?\n\r?\n/", $raw, 2);
            if (isset($sections[1])) $payload = $sections[1];
        }
        $payload = preg_replace('/--[^\r\n]+--\s*$/', '', $payload);
        $text = strip_tags($payload);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', '', $text);
        return strlen(trim($text));
    }

    function init()
    {
        $this->rc = rcmail::get_instance();

        // Load plugin localization for all tasks/actions
        $this->add_texts('localization', true);

        // Queue UI actions
        $this->register_action('plugin.scheduled_sending.queue', array($this, 'action_queue'));
        $this->register_action('plugin.scheduled_sending.queue_list', array($this, 'action_queue_list'));
        $this->register_action('plugin.scheduled_sending.queue_cancel', array($this, 'action_queue_cancel'));
        $this->register_action('plugin.scheduled_sending.queue_delete', array($this, 'action_queue_delete'));
        $this->register_action('plugin.scheduled_sending.queue_reschedule', array($this, 'action_queue_reschedule'));

        // Add hook for preferences sections
        if ($this->rc->task == 'settings') {
            $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
            $this->add_hook('preferences_list', array($this, 'preferences_list'));
        // Client command to open queue
        } else if ($this->rc->task == 'mail') {
            $this->include_script('js/queue.js');
        }

        /* SS: early worker intercept (handles login bounce via _url) */
        $act = rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC);
        $urlq = rcube_utils::get_input_value('_url', rcube_utils::INPUT_GPC);
        if (!$act && $urlq) {
            parse_str($urlq, $u);
            if (!empty($u['_action'])) $act = $u['_action'];
            if (empty($_REQUEST['_token']) && !empty($u['_token'])) {
                $_REQUEST['_token'] = $_GET['_token'] = $_POST['_token'] = $u['_token'];
            }
        }
        if ($act === 'plugin.scheduled_sending.send_due') {
            $this->load_config();
            $this->action_send_due();
            exit;
        }

        if ($act === 'plugin.scheduled_sending.worker') {
                $this->load_config();
            $this->action_worker();
            exit;
        }

        // server handler
        $this->register_action('plugin.scheduled_sending.schedule', array($this, 'action_schedule'));

        // compose render hook (inject panel)
        $this->add_hook('render_page', array($this, 'on_render_page'));

        // assets
        $skin = $this->rc->config->get('skin', 'larry');
        $this->include_script('js/scheduled.js');
        $this->include_stylesheet($this->local_skin_path() . '/scheduled.css');
    }

    private function log($msg, $ctx = array())
    {
        // be defensive: only log if RC logger available
        if (function_exists('write_log') && $this->rc->config->get('scheduled_debug', false)) {
            $entry = array('msg'=>$msg,'time'=>gmdate('c'),'ctx'=>$ctx);
            write_log($this->logname, $entry);
        }
    }

    public function on_render_page($p)
    {
        // only in compose view
        if ($this->rc->action !== 'compose') {
            return $p;
        }

        // inject panel once just after compose headers container opens
        $panel = $this->build_inline_panel_html();

        $patterns = array(
            '/(<div id="composeheaders"[^>]*>)/i',
            '/(<div id="compose-headers"[^>]*>)/i',
        );

        $already = (strpos($p['content'], 'id="ss-inline-schedule"') !== false);
        if (!$already) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $p['content'])) {
                    $p['content'] = preg_replace($pat, '$1' . $panel, $p['content'], 1);
                    $this->log('inline panel injected', array('skin'=>$this->rc->config->get('skin','?')));
                    break;
                }
            }
        }
        return $p;
    }

    private function build_inline_panel_html()
    {
        // default value: local now + 30min (no seconds)
        $def = date('Y-m-d\TH:i', time() + 1800);

        // inline fallback binder: uses RC's http_post, de-duped, no full form submit
        $inline = <<<HTML
		<script>
		(function(){
		  if (window.__SS_BOUND) return; // avoid double binding
		  window.__SS_BOUND = true;

		  function nowTs(){ return Math.floor(Date.now()/1000); }

		  function bind(){
			var btn = document.getElementById('ss-schedule-btn');
			if (!btn) return;
			var inflight = false;
			btn.addEventListener('click', function(ev){
			  ev.preventDefault();
			  if (inflight) return;
			  var when = document.getElementById('ss-when');
			  if (!when || !when.value) { if (window.rcmail) rcmail.display_message('Pick a future time', 'error'); return; }
			  var d = new Date(when.value);
			  if (isNaN(d.getTime()) || d.getTime() <= Date.now()) { if (window.rcmail) rcmail.display_message('Pick a future time', 'error'); return; }

			  var form = document.getElementById('composeform') || btn.closest('form');
			  if (!form) { if (window.rcmail) rcmail.display_message('Compose form not ready', 'error'); return; }

			  // build payload (minimal safe fields)
			  var data = {};
			  var f = new FormData(form);
			  function copyField(name, as){ if (f.has(name)) data[as||name] = f.get(name); }
			  copyField('_id');
			  copyField('_from'); // identity id
			  copyField('_to');
			  copyField('_cc');
			  copyField('_bcc');
			  copyField('_subject');
			  copyField('_is_html');

			  data['_schedule_at'] = when.value;
			  data['_schedule_ts'] = Math.floor(d.getTime()/1000);
			  data['_schedule_tzoffset'] = - (new Date().getTimezoneOffset()); // minutes

			  // mark compose as clean to avoid discard modal
			  if (window.rcmail) {
				rcmail.env.compose_submit = true;
				rcmail.env.is_dirty = false;
				rcmail.env.exit_warning = false;
			  }

			  // AJAX via Roundcube
			  inflight = true;
			  btn.disabled = true;
			  try {
				if (window.rcmail && typeof rcmail.http_post === 'function') {
				  rcmail.http_post('plugin.scheduled_sending.schedule', data);
				} else {
				  // last-resort sync fallback (should not happen)
				  var xhr = new XMLHttpRequest();
				  xhr.open('POST', '?_task=mail&_action=plugin.scheduled_sending.schedule&_remote=1', true);
				  xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
				  var parts = [];
				  for (var k in data) { if (data.hasOwnProperty(k)) parts.push(encodeURIComponent(k)+'='+encodeURIComponent(data[k])); }
				  xhr.send(parts.join('&'));
				}
			  } catch(e) {
				inflight = false; btn.disabled = false;
				if (window.console) console.error(e);
			  }
			  // re-enable after a short time; server will show toast
			  setTimeout(function(){ inflight=false; btn.disabled=false; }, 1200);
			}, { once:false });
		  }

		  if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', bind);
		  } else {
			bind();
		  }
		})();
		</script>
		HTML;

				$html = <<<HTML
		<div id="ss-inline-schedule" class="ss-row">
		  <label class="ss-label">⏰ Send at</label>
		  <input id="ss-when" type="datetime-local" name="_schedule_at" value="{$def}" />
		  <button type="button" id="ss-schedule-btn" class="button">Send later</button>
		</div>
		$inline
		HTML;
        return $html;
    }

    public function action_schedule()
    {
        // ===== SS TZ DEBUG START =====
        try {
            $post = function($k){ return rcube_utils::get_input_value($k, rcube_utils::INPUT_GPC); };
            $dbg = array(
                'msg' => 'tz_debug',
                'php_default_tz' => @date_default_timezone_get(),
                'server_now_utc' => gmdate('Y-m-d H:i:s'),
                'server_now_local' => date('Y-m-d H:i:s'),
                'posted' => array(
                    'scheduled_at' => $post('scheduled_at'),
                    'scheduled_at_is_utc' => $post('scheduled_at_is_utc'),
                    'schedule_date' => $post('schedule_date'),
                    'schedule_time' => $post('schedule_time'),
                    'tz_offset' => $post('tz_offset'),
                    '_schedule_at' => $post('_schedule_at'),
                    '_schedule_ts' => $post('_schedule_ts'),
                    '_schedule_tzoffset' => $post('_schedule_tzoffset'),
                ),
            );
            $this->ss_debug($dbg);
        } catch (Exception $e) {
            $this->ss_debug(array('msg'=>'tz_debug_error','err'=>$e->getMessage()));
        }
       
        /* SS: schedule TZ normalize (v58) */
        try {
            $post     = function($k){ return rcube_utils::get_input_value($k, rcube_utils::INPUT_GPC); };
            $incoming = $post('scheduled_at');
            $is_utc   = $post('scheduled_at_is_utc');
            $tzoff    = $post('tz_offset');

            // v57 underscore fallbacks
            $sched_at_u = $post('_schedule_at');
            $sched_ts   = $post('_schedule_ts');
            $sched_tzo  = $post('_schedule_tzoffset');

            $scheduled_utc = null;

            if ($is_utc && $incoming) {
                // Client already sent UTC string
                $scheduled_utc = trim($incoming);
                $this->ss_debug(array('msg'=>'tz skip (client utc)','scheduled_at'=>$scheduled_utc));
            }
            elseif ($sched_ts) {
                // Epoch seconds are absolute UTC
                $ts = (int)$sched_ts;
                if ($ts > 0) {
                    $scheduled_utc = gmdate('Y-m-d H:i:s', $ts);
                    $this->ss_debug(array('msg'=>'tz from _schedule_ts','scheduled_at'=>$scheduled_utc));
                }
            }
            else {
                // Build from local wall time and an offset
                $stamp = $incoming;
                if (!$stamp) $stamp = $sched_at_u;
                if (!$stamp) {
                    $d = $post('schedule_date'); $t = $post('schedule_time');
                    if ($d && $t) $stamp = trim($d).' '.trim($t);
                }

                // Prefer tz_offset (positive minutes == UTC - local), else use _schedule_tzoffset (negative usually)
                $off = 0;
                if ($tzoff !== null && $tzoff !== '') {
                    $off = (int)$tzoff;            // e.g. Chicago summer => 300
                } elseif ($sched_tzo !== null && $sched_tzo !== '') {
                    // Roundcube older field is often negative of tz_offset
                    $raw = (int)$sched_tzo;        // e.g. -300
                    $off = ($raw > 0) ? $raw : -$raw;
                }

                if ($stamp) {
                    // Parse local and add offset minutes to get UTC
                    $ts = strtotime($stamp . ':00'); // add seconds for consistency
                    if ($ts !== false) {
                        $ts += $off * 60;
                        $scheduled_utc = gmdate('Y-m-d H:i:s', $ts);
                        $this->ss_debug(array('msg'=>'tz normalized','scheduled_at'=>$scheduled_utc,'tz_offset'=>$off));
                    }
                }
            }

            if ($scheduled_utc) {
                $_REQUEST['scheduled_at'] = $_POST['scheduled_at'] = $_GET['scheduled_at'] = $scheduled_utc;
            }
        } catch (Exception $e) {
            $this->ss_debug(array('msg'=>'tz normalize error','err'=>$e->getMessage()));
        }
		$rc = $this->rc;
        $this->ss_debug(array('msg'=>'action_schedule start','time'=>date('c'),'framed'=>(int)$rc->output->framed));

        // pull time
        $when    = rcube_utils::get_input_value('_schedule_at', rcube_utils::INPUT_POST);
        $epoch   = (int) rcube_utils::get_input_value('_schedule_ts', rcube_utils::INPUT_POST);
        $epoch_src = ($epoch > 0) ? 'absolute' : 'none';
        $tzoff   = (int) rcube_utils::get_input_value('_schedule_tzoffset', rcube_utils::INPUT_POST); // minutes east of UTC

        if ($epoch <= 0 && $when) {
            $t = strtotime($when);
            if ($t) {
                $epoch = $t;
                $epoch_src = 'local';
            }
        }

        if ($epoch <= 0) {
            $this->log('schedule error', array('error'=>'invalid_or_past','epoch'=>$epoch,'now'=>time()));
            $rc->output->command('display_message', 'Invalid or past time', 'error');
            $rc->output->send();
            return;
        }

        // Convert to UTC only when client did NOT send absolute epoch (_schedule_ts)
        if ($epoch_src !== 'absolute' && $tzoff) {
            $epoch = $epoch - ($tzoff * 60);
        }
        if ($epoch <= time()) {
            $this->log('schedule error', array('error'=>'invalid_or_past','epoch'=>$epoch,'now'=>time()));
            $rc->output->command('display_message', 'Invalid or past time', 'error');
            $rc->output->send();
            return;
        }

        $scheduled_at = gmdate('Y-m-d H:i:00', $epoch);

        // user + identity
        $user_id = $rc->user ? (int) $rc->user->ID : 0;
        $identity_id = (int) rcube_utils::get_input_value('_from', rcube_utils::INPUT_POST);

        // gather light meta
        $meta = array(
            'to'   => rcube_utils::get_input_value('_to', rcube_utils::INPUT_POST),
            'cc'   => rcube_utils::get_input_value('_cc', rcube_utils::INPUT_POST),
            'bcc'  => rcube_utils::get_input_value('_bcc', rcube_utils::INPUT_POST),
            'subj' => rcube_utils::get_input_value('_subject', rcube_utils::INPUT_POST),
            'html' => (int) rcube_utils::get_input_value('_is_html', rcube_utils::INPUT_POST),
        );

        // DB insert (no NULL raw_mime)
        $table = $rc->config->get('scheduled_sending_table', 'scheduled_queue');
        $db = $rc->get_dbh();
        // Build or accept raw MIME payload (minimal fallback if client does not send _raw_mime)
        $raw_mime = rcube_utils::get_input_value('_raw_mime', rcube_utils::INPUT_POST, true);
        if ($raw_mime === null) $raw_mime = '';
        if ($raw_mime === '') {
            $subject = (string) rcube_utils::get_input_value('_subject', rcube_utils::INPUT_POST, true);
            $to      = (string) rcube_utils::get_input_value('_to', rcube_utils::INPUT_POST, true);
            $cc      = (string) rcube_utils::get_input_value('_cc', rcube_utils::INPUT_POST, true);
            $bcc     = (string) rcube_utils::get_input_value('_bcc', rcube_utils::INPUT_POST, true);

                        $body    = (string) rcube_utils::get_input_value('_message', rcube_utils::INPUT_POST, true);
            $msg_html = rcube_utils::get_input_value('_message_html', rcube_utils::INPUT_POST, true);
            $is_html = false;
            if ($msg_html) {
                // Decide if HTML is trivial wrappers only; prefer plain text in that case
                $maybe_plain = $this->_ss_is_semantically_plain_html($msg_html, $body);
                if ($maybe_plain) {
                    $body = $this->_ss_text_from_trivial_html($msg_html);
                    $is_html = false;
                } else {
                    $body = (string)$msg_html;
                    $is_html = true;
                }
            }


            $from = '';
            $idval = rcube_utils::get_input_value('_from', rcube_utils::INPUT_POST);
            if ($idval) {
                $ident = $this->rc->user->get_identity((int)$idval);
                if ($ident && !empty($ident['email'])) {
                    $name = !empty($ident['name']) ? $ident['name'] : $ident['email'];
                    $from = sprintf('"%s" <%s>', $name, $ident['email']);
                }
            }

            $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
            $compose_ctx = $this->_ss_get_compose_context($compose_id);
            $compose_id = isset($compose_ctx['id']) ? $compose_ctx['id'] : $compose_id;
            $hydrated = $this->_ss_resolve_body_from_context($compose_ctx, $body, $is_html);
            $body = $hydrated['body'];
            $is_html = $hydrated['is_html'];

            $attach_ids = rcube_utils::get_input_value('_attach_ids', rcube_utils::INPUT_POST);
            if (!is_array($attach_ids)) { $attach_ids = $attach_ids ? array($attach_ids) : array(); }
            $attach_probe_json = rcube_utils::get_input_value('_ss_attach_probe', rcube_utils::INPUT_POST, true);
            $attach_probe = array();
            if ($attach_probe_json) {
                $tmp_probe = json_decode($attach_probe_json, true);
                if (is_array($tmp_probe)) $attach_probe = $tmp_probe;
                $this->ss_debug(array('msg'=>'attach_probe_client','probe'=>$attach_probe_json));
            }
            $expected_attachments = count($attach_ids);
            if (!$expected_attachments && count($attach_probe)) $expected_attachments = count($attach_probe);
            $compose_mime = '';
            $compose_attach_count = 0;
            if ($compose_id) {
                $compose_mime = $this->_ss_build_mime_from_compose($compose_id, $from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_ids, $compose_ctx);
                if ($compose_mime !== '') {
                    $compose_attach_count = $this->_ss_mime_attachment_count($compose_mime);
                } else {
                    $this->ss_debug(array('msg'=>'compose_mime_prefetch_empty','compose_id'=>$compose_id));
                }
            } else {
                $this->ss_debug(array('msg'=>'compose_id_missing'));
            }

            
            // Try to reuse existing Draft (captures attachments) before falling back to minimal MIME
            $draft_uid_post = (int) rcube_utils::get_input_value('_draft_uid', rcube_utils::INPUT_POST);
            $draft_mime = '';
            if ($draft_uid_post > 0) {
                try {
                    $rc = $this->rc ?: rcmail::get_instance();
                    $storage = $rc->get_storage();
                    $drafts = $rc->config->get('drafts_mbox', 'Drafts');
                    if ($drafts && $storage->folder_exists($drafts)) {
                        $raw_try = '';
                        if (method_exists($storage, 'get_raw_message')) {
                            $raw_try = (string)$storage->get_raw_message($draft_uid_post, $drafts);
                        }
                        if ($raw_try === '' && method_exists($storage, 'get_raw_body')) {
                            $raw_try = (string)$storage->get_raw_body($draft_uid_post, $drafts);
                        }
                        if ($raw_try !== '') {
                            $draft_mime = $raw_try;
                            $this->ss_debug(array('msg'=>'draft_uid_used','uid'=>$draft_uid_post));
                        } else {
                            $this->ss_debug(array('msg'=>'draft_uid_empty_raw','uid'=>$draft_uid_post));
                        }
                    }
                } catch (Exception $e) {
                    $this->ss_debug(array('msg'=>'draft_uid_fetch_err','err'=>$e->getMessage()));
                }
            }
            if ($draft_mime === '') {
                $draft_mime = $this->_ss_try_fetch_draft_mime($subject, $from);
            }

            $draft_used = false;
            if ($draft_mime === '' && $compose_mime !== '') {
                $draft_mime = $compose_mime;
                $draft_used = true;
                $this->ss_debug(array('msg'=>'compose_mime_used','compose_id'=>$compose_id,'reason'=>'no_draft'));
            } elseif ($draft_mime !== '' && $compose_mime !== '' && $compose_mime !== $draft_mime) {
                $draft_attach_count = $this->_ss_mime_attachment_count($draft_mime);
                $draft_body_len = $this->_ss_mime_body_length($draft_mime);
                $input_body_text = $is_html ? trim(strip_tags((string)$body)) : trim((string)$body);
                $input_body_len = strlen(preg_replace('/\s+/', '', $input_body_text));

                $prefer_compose = false;
                if ($compose_attach_count > $draft_attach_count) {
                    $prefer_compose = true;
                }
                if ($expected_attachments && $compose_attach_count >= $expected_attachments && $draft_attach_count < $expected_attachments) {
                    $prefer_compose = true;
                }
                if ($input_body_len > 0 && $draft_body_len === 0) {
                    $prefer_compose = true;
                }
                if ($prefer_compose) {
                    $draft_mime = $compose_mime;
                    $draft_used = true;
                    $this->ss_debug(array('msg'=>'compose_mime_preferred','compose_id'=>$compose_id,'draft_attach'=>$draft_attach_count,'compose_attach'=>$compose_attach_count,'body_len'=>$input_body_len));
                }
            }

            $this->ss_debug(array('msg'=>'draft_probe','used'=>($draft_mime!=='') && !$draft_used,'subject'=>$subject,'uid_post'=>$draft_uid_post));
            if ($draft_mime !== '') {
                $raw_mime = $draft_mime;
            } else {
                $raw_mime = $this->build_minimal_mime($from, $to, $cc, $bcc, $subject, $body, $is_html);
            }

            // Safety net: if body ended up empty (headers-only), try to build from compose session even without explicit _id
            $parts_check = preg_split("/\r?\n\r?\n/", (string)$raw_mime, 2);
            $body_check  = isset($parts_check[1]) ? trim($parts_check[1]) : '';
            if ($body_check === '' && $compose_mime !== '' && $raw_mime !== $compose_mime) {
                $raw_mime = $compose_mime;
                $this->ss_debug(array('msg'=>'auto_compose_fallback_used','compose_id'=>$compose_id));
            } elseif ($body_check === '' && $compose_mime === '') {
                $this->ss_debug(array('msg'=>'auto_compose_fallback_failed'));
            }

        }

        $q  = "INSERT INTO $table (user_id, identity_id, scheduled_at, status, raw_mime, meta_json, created_at, updated_at)
               VALUES (?, ?, ?, 'queued', ?,  ?, NOW(), NOW())";
        $ok = $db->query($q, $user_id, $identity_id, $scheduled_at, $raw_mime, json_encode($meta));
        $job_id = $db->insert_id();

        // Save a Draft copy
        try {
            $sentmb = $rc->config->get('sent_mbox', 'Sent');
            if ($sentmb) {
                $storage = $rc->get_storage();
                if (!$storage->folder_exists($sentmb)) {
                    $storage->create_folder($sentmb, true);
                }
                $saved = $storage->save_message($sentmb, $raw_mime, null, false, array('SEEN'));
                $this->ss_debug(array('msg'=>'initial saved to sent','ok'=>(bool)$saved,'folder'=>$sentmb));
                $meta['initial_saved_in'] = 'Sent';
                if ($saved && isset($job_id) && $job_id) {
                    if (!is_array($meta)) { $meta = array(); }
                    $meta['draft_uid'] = $saved;
                    $db->query("UPDATE $table SET meta_json=? WHERE id=?", json_encode($meta), (int)$job_id);
                }
            }
        } catch (Exception $e) {
            $this->ss_debug(array('msg'=>'draft save error','err'=>$e->getMessage()));
        }

        $this->log('queue insert', array('ok'=>(bool)$ok, 'scheduled_at'=>$scheduled_at, 'framed'=>(int)$rc->output->framed, 'identity_id'=>$identity_id));

        // Friendly toast back to client
        if ($ok) {
            $this->ss_debug(array('msg'=>'schedule success','time'=>date('c')));
            // Build local wall-time string for toast
            $offmin = (int) rcube_utils::get_input_value('_schedule_tzoffset', rcube_utils::INPUT_POST);
            $local_epoch = $epoch;
            if ($offmin) { $local_epoch = $epoch - ($offmin * 60); }
            $local_text = date('M j, Y g:i A', $local_epoch);
            $rc->output->command('display_message', 'Scheduled for ' . $local_text, 'confirmation');
        } else {
            $rc->output->command('display_message', 'Unable to schedule (DB)', 'error');
        }
        $rc->output->send(); // JSON response for AJAX
    }
    private function build_minimal_mime($from, $to, $cc, $bcc, $subject, $body, $is_html)
    {
        $nl = "";
        $headers = array();
        $headers[] = 'Date: ' . date('r');
        if ($from)   $headers[] = 'From: ' . $from;
        if ($to)     $headers[] = 'To: ' . $to;
        if ($cc)     $headers[] = 'Cc: ' . $cc;
        if ($subject !== '') $headers[] = 'Subject: ' . $subject;
        $headers[] = 'Message-ID: <' . uniqid() . '@localhost>';
        $meta['msgid'] = end($headers); // store Message-ID header line
        $headers[] = 'MIME-Version: 1.0';
        if ($is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        return implode($nl, $headers) . $nl . $nl . (string)$body . $nl;
    }
    /**
     * HTTP worker: send all queued messages scheduled at/earlier than now.
     * URL: ?_task=mail&_action=plugin.scheduled_sending.send_due&_token=YOUR_SECRET
     * Guarded by config: $config['scheduled_sending_worker_token']
     */
    public function action_send_due()
    {
        $rc = $this->rc;
        $cfg = $rc->config;

        $token = rcube_utils::get_input_value('_token', rcube_utils::INPUT_GPC);
        $need  = $cfg->get('scheduled_sending_worker_token', null);
        if (!$need || !$token || !hash_equals((string)$need, (string)$token)) {
            $this->ss_debug(array('msg'=>'send_due denied','time'=>date('c'),'remote'=>$_SERVER['REMOTE_ADDR'] ?? ''));
            header('HTTP/1.1 403 Forbidden'); echo 'forbidden'; exit;
        }

        $db    = $rc->get_dbh();
        $table = $cfg->get('db_table_scheduled_sending', 'scheduled_sending_queue');
        $batch = (int)$cfg->get('scheduled_sending_batch', 25);
        if ($batch < 1) $batch = 25;
        $delivery = $cfg->get('scheduled_sending_delivery', 'mail'); // 'mail' or 'none' (dry-run)

        $sel = $db->query(
            "SELECT id,user_id,identity_id,scheduled_at,raw_mime,meta_json,status FROM $table WHERE status='queued' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT $batch",
            gmdate('Y-m-d H:i:s')
        );

        $rows = array();
        while ($sel && ($r = $db->fetch_assoc($sel))) $rows[] = $r;
        $this->ss_debug(array('msg'=>'worker scan','time'=>date('c'),'count'=>count($rows)));

        $sent_ok = 0;
        foreach ($rows as $row) {
            $id  = (int)$row['id'];
            $raw = (string)$row['raw_mime'];
            if ($raw === '') {
                $db->query("UPDATE $table SET status = 'error', updated_at = NOW() WHERE id = ?", $id);
                $this->ss_debug(array('msg'=>'empty raw_mime','id'=>$id));
                continue;
            }

            $hdrs  = $this->ss_parse_headers($raw);
            $from  = isset($hdrs['from']) ? $hdrs['from'] : '';
            $to    = isset($hdrs['to']) ? $hdrs['to'] : '';
            $cc    = isset($hdrs['cc']) ? $hdrs['cc'] : '';
            $bcc   = isset($hdrs['bcc']) ? $hdrs['bcc'] : '';
            $subj  = isset($hdrs['subject']) ? $hdrs['subject'] : '';
            $msgid = isset($hdrs['message-id']) ? $hdrs['message-id'] : '';

            $ok = false;
            if ($delivery === 'mail') {
                // Split raw into header/body at first blank line
                $parts = preg_split("/\\r?\\n\\r?\\n/", $raw, 2);
                $hdrblock = $parts[0];
                $body = isset($parts[1]) ? $parts[1] : '';

                // Build recipients
                $rcpts = trim(implode(', ', array_filter(array($to, $cc, $bcc))));
                // Remove To/Subject headers from hdrblock to avoid duplication
                $hdrblock = preg_replace('/^(Subject|To):.*\\r?\\n/im', '', $hdrblock);

                $params = '';
                if ($from && preg_match('/<([^>]+)>/', $from, $m)) {
                    $params = '-f ' . escapeshellarg($m[1]);
                }

                $ok = @mail($rcpts, $subj, $body, $hdrblock, $params);
                $this->ss_debug(array('msg'=>'worker mail()','id'=>$id,'ok'=>(bool)$ok,'rcpts'=>$rcpts));
            } else {
                // dry-run
                $ok = true;
                $this->ss_debug(array('msg'=>'worker dry-run','id'=>$id));
            }

            
            if ($ok) {
                // Best-effort: append to Sent and remove matching Draft by Message-ID
                try {
                    $storage = $rc->get_storage();

                    // Sent folder: prefer Roundcube's 'sent_mbox', fallback to plugin config
                    $sentmb = $cfg->get('sent_mbox', $cfg->get('scheduled_sending_sent_folder', 'Sent'));
                    if (!empty($sentmb)) {
                        if (!$storage->folder_exists($sentmb)) {
                            $storage->create_folder($sentmb, true);
                        }
                        $storage->save_message($sentmb, $raw);
                    }

                    // Delete the original draft by Message-ID
                    $drafts = $cfg->get('drafts_mbox', 'Drafts');
                    if (!empty($drafts)) {
                        $draft_uid = null;
                        if (isset($meta) && is_array($meta) && !empty($meta['draft_uid'])) {
                            $draft_uid = (int)$meta['draft_uid'];
                        }
                        if ($draft_uid) {
                            $storage->delete_message($draft_uid, $drafts);
                        } else if (!empty($msgid)) {
                            $index = $storage->search($drafts, 'HEADER', array('Message-ID' => $msgid));
                            if ($index && !empty($index->count)) {
                                foreach ($index->get() as $msg) {
                                    $storage->delete_message($msg->uid, $drafts);
                                }
                            }
                        }
                    }
                    $db->query("UPDATE $table SET status = 'sent', updated_at = NOW() WHERE id = ?", $id);
                    $sent_ok++;
                } catch (\Exception $e) {
                    $this->ss_debug(array('msg'=>'worker imap err','err'=>$e->getMessage()));
                }
            }
		else {
                $db->query("UPDATE $table SET status = 'error', updated_at = NOW() WHERE id = ?", $id);
            }
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo "ok sent=$sent_ok total=".count($rows);
        exit;
    }

    private function ss_parse_headers($raw)
    {
        $lines = preg_split("/\\r?\\n/", $raw);
        $headers = array();
        $current = '';
        foreach ($lines as $ln) {
            if ($ln === '') break;
            if (preg_match('/^\\s+/', $ln) && $current) {
                $headers[$current] .= ' ' . trim($ln);
                continue;
            }
            if (strpos($ln, ':') !== false) {
                list($k, $v) = explode(':', $ln, 2);
                $current = strtolower(trim($k));
                $headers[$current] = trim($v);
            }
        }
        return $headers;
    }

    // Run worker very early (before auth) when requested
    public function on_startup($args)
    {
        $this->ss_debug(array('msg'=>'startup hook','task'=>$this->rc->task,'action'=>rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC)));
        $action = rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC);

        // If login bounced our request, original params are in _url
        $urlq = rcube_utils::get_input_value('_url', rcube_utils::INPUT_GPC);
        if ($urlq) {
            parse_str($urlq, $u);
            if (isset($u['_action'])) {
                $action = $u['_action'];
            }
            if (isset($u['_token']) && !rcube_utils::get_input_value('_token', rcube_utils::INPUT_GPC)) {
                $_REQUEST['_token'] = $_GET['_token'] = $_POST['_token'] = $u['_token'];
            }
        }

        if ($action === 'plugin.scheduled_sending.send_due') {
            // Load config so token is available
            $this->load_config();
            $this->action_send_due();
            exit;
        }
        
        return $args;
    }

    public function action_worker()
    {
        $rc = $this->rc;
        $token = rcube_utils::get_input_value('_token', rcube_utils::INPUT_GPC);
        $cfg_token = $rc->config->get('scheduled_sending_worker_token', '');
        if (!$token || !$cfg_token || !hash_equals($cfg_token, $token)) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(array('ok'=>false, 'error'=>'forbidden'));
            exit;
        }
        try {
            $res = $this->run_send_due_worker();
            if (!is_array($res)) $res = array('ok'=>true,'note'=>'worker executed');
            header('Content-Type: application/json');
            echo json_encode($res);
        } catch (Exception $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(array('ok'=>false, 'error'=>$e->getMessage()));
        }
        exit;
    }

    public function preferences_sections_list($args)
    {
        $args['list']['scheduled_sending'] = array('id' => 'scheduled_sending', 'section' => 'Scheduled Sending');
        return $args;
    }

    
    public function preferences_list($args)
    {
        if ($args['section'] == 'scheduled_sending') {
            $this->add_texts('localization', true);
            $this->rc->output->set_pagetitle($this->gettext('scheduledmessages'));

            // include the table class
            $this->rc->output->include_script('list.js');
            $table = new html_table(array(
                'width' => '75%',
                'cols'  => 8,
                'class' => 'scheduled-messages-table',
                'id'    => 'scheduled-messages-table',
            ));

            $table->add_header(array('width' => '2%',  'align' => 'left', 'class' => 'id'), $this->gettext('id'));
            $table->add_header(array('width' => '15%', 'align' => 'left', 'class' => 'scheduled_at'), $this->gettext('scheduled_at'));
            $table->add_header(array('width' => '28%', 'align' => 'left', 'class' => 'subject'), $this->gettext('subject'));
            $table->add_header(array('width' => '28%', 'align' => 'left', 'class' => 'to'), $this->gettext('to'));
            $table->add_header(array('width' => '8%',  'align' => 'left', 'class' => 'status'), $this->gettext('status'));
            $table->add_header(array('width' => '15%', 'align' => 'left', 'class' => 'created_at'), $this->gettext('created_at'));
            $table->add_header(array('width' => '4%',  'align' => 'left', 'class' => 'edit'), 'Edit');
            $table->add_header(array('width' => '6%',  'align' => 'left', 'class' => 'delete'), $this->gettext('delete'));

            $db = $this->rc->get_dbh();
            $table_name = $this->rc->config->get('scheduled_sending_table', 'scheduled_queue');
            $user_id = $this->rc->user->ID;

            $sql = "SELECT id, scheduled_at, meta_json, status, created_at FROM {$table_name} ".
                   "WHERE user_id = ? AND status NOT IN ('sent', 'cancelled') ORDER BY scheduled_at ASC";
            $res = $db->query($sql, $user_id);

            while ($row = $db->fetch_assoc($res)) {
                $meta = json_decode($row['meta_json'], true);
                $to = isset($meta['to']) ? rcube::Q($meta['to']) : '';
                $subject = isset($meta['subj']) ? rcube::Q($meta['subj']) : '';

                $table->add_row();
                $table->add(array('class' => 'id'), rcube::Q($row['id']));
                $table->add(array('class' => 'scheduled_at'), rcube::Q($row['scheduled_at']));
                $table->add(array('class' => 'subject'), $subject);
                $table->add(array('class' => 'to'), $to);
                $table->add(array('class' => 'status'), rcube::Q($row['status']));
                $table->add(array('class' => 'created_at'), rcube::Q($row['created_at']));

                $scheduled_ts = (int) strtotime($row['scheduled_at']);

                // Resolve plugin skin asset for *any* active skin, then fall back safely.
                $skin = (string) $this->rc->config->get('skin', 'default');
                $try  = array(
                    "skins/$skin/images/trash.svg",   // active skin override in this plugin
                    "skins/default/images/trash.svg", // plugin default skin fallback
                    "skins/images/trash.svg",         // legacy layout
                    "images/trash.svg",               // last resort in plugin root
                );

                $src = '';
                foreach ($try as $rel) {
                    if (is_file($this->home . '/' . $rel)) {
                        $src = $this->rc->output->abs_url($this->url($rel));
                        break;
                    }
                }

                $delete_button = '<a href="#" class="delete-scheduled-message" data-id="' . rcube::Q($row['id']) . '">'
                               . '<img style="vertical-align: middle;" src="' . rcube::Q($src) . '" height="16" alt="Delete">'
                               . '</a>';

                $edit_button = '<a href="#" class="edit-scheduled-message" data-id="' . rcube::Q($row['id']) . '" data-ts="' . $scheduled_ts . '">Edit</a>';

                $table->add(array('class' => 'edit'), $edit_button);
                $table->add(array('class' => 'delete'), $delete_button);
            }

            $html = $table->show();
            $this->include_script('js/settings_queue.js');

            $args['blocks']['scheduled_sending'] = array(
                'name' => $this->gettext('scheduledmessages'),
                'content' => $html,
            );
        }

        return $args;
    }

}
